<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

use SymPress\NginxCache\Security\SecretCipher;

final readonly class NetworkConfiguration
{
    public function __construct(private NetworkSettings $network, private WordPressCacheSettings $settings, private CompatibilitySettings $compatibility)
    {
    }

    public function register(): void
    {
        $this->settings->register(migrateSecrets: false);
        $this->compatibility->register();
        (new TagIndexSettings())->register();
    }

    public function sanitize(string $option, mixed $value): mixed
    {
        if (!array_key_exists($option, ConfigurationCatalog::defaults())) {
            throw new \InvalidArgumentException('Unknown cache setting.');
        }
        if (ConfigurationCatalog::secret($option)) {
            $value = is_string($value) ? trim($value) : '';
            $cipher = new SecretCipher();
            if ($value === '') {
                return '';
            }
            if (str_starts_with($value, SecretCipher::PREFIX)) {
                if ($cipher->decrypt($value, $option) === null) {
                    throw new \RuntimeException('The stored credential cannot be decrypted.');
                }
                return $value;
            }
            return $cipher->encrypt($value, $option);
        }
        if ($option === 'sympress_nginx_cache_purge_roles') {
            $roles = array_keys(wp_roles()->get_names());
            return array_values(array_intersect(array_filter(is_array($value) ? $value : [], is_string(...)), $roles));
        }
        if ($option === 'sympress_nginx_cache_key_template') {
            if (!is_string($value) || strlen($value) > 1024 || preg_match('/[\r\n;{}]/', $value) === 1) {
                throw new \InvalidArgumentException('Invalid cache key template.');
            }
            foreach (['$scheme', '$request_method', '$host', '$request_uri'] as $token) {
                if (substr_count($value, $token) !== 1) {
                    throw new \InvalidArgumentException('Cache key template requires each supported token once.');
                }
            }
            return $value;
        }
        return sanitize_option($option, $value);
    }

    /** @return list<array{option: string, value: mixed, policy: string}> */
    public function adoption(int $siteId, bool $dryRun = true): array
    {
        $site = get_site($siteId);
        if (!$this->network->active() || $site === null || (int) $site->site_id !== get_current_network_id()) {
            throw new \InvalidArgumentException('Select a site in the current network.');
        }
        $this->register();
        $values = [];
        switch_to_blog($siteId);
        try {
            foreach (ConfigurationCatalog::defaults() as $option => $default) {
                $value = get_option($option, $default);
                if ($option === WordPressCacheSettings::OPTION_PATH && $value === '') {
                    $value = $this->settings->defaultPath();
                }
                $values[$option] = $this->sanitize($option, $value);
            }
        } finally {
            restore_current_blog();
        }
        $report = [];
        foreach ($values as $option => $value) {
            $policy = $this->network->mandatory($option) ? 'network' : 'default';
            if (!$dryRun) {
                $this->network->save($option, $value, $policy);
            }
            $report[] = ['option' => $option, 'value' => ConfigurationCatalog::secret($option) ? ($value === '' ? '[empty]' : '[configured]') : $value, 'policy' => $policy];
        }
        return $report;
    }
}
