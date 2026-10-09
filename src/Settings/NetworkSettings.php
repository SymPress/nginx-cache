<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

final readonly class NetworkSettings
{
    public const string PREFIX = 'sympress_nginx_cache_network_';
    public const string POLICIES = self::PREFIX . 'policies';

    public function active(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    public function name(string $option): string
    {
        return self::PREFIX . (str_starts_with($option, CompatibilitySettings::PREFIX) ? substr($option, strlen(CompatibilitySettings::PREFIX)) : $option);
    }

    public function mandatory(string $option): bool
    {
        return in_array($option, [WordPressCacheSettings::OPTION_PATH, WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS, WordPressCacheSettings::OPTION_REMOTE_SECRET, WordPressCacheSettings::OPTION_FULL_PURGE_MODE, WordPressCacheSettings::OPTION_FULL_PURGE_ENDPOINT, WordPressCacheSettings::OPTION_FULL_PURGE_HTTP_METHOD, WordPressCacheSettings::OPTION_CLOUDFLARE_ZONE_ID, WordPressCacheSettings::OPTION_CLOUDFLARE_API_TOKEN, CompatibilitySettings::PREFIX . 'purge_backend', CompatibilitySettings::PREFIX . 'key_template', CompatibilitySettings::PREFIX . 'purge_roles'], true)
            || str_starts_with($option, CompatibilitySettings::PREFIX . 'redis_');
    }

    public function policy(string $option): string
    {
        if ($this->mandatory($option)) {
            return 'network';
        }
        $policies = $this->active() ? get_site_option(self::POLICIES, []) : [];
        return is_array($policies) && ($policies[$option] ?? '') === 'network' ? 'network' : 'default';
    }

    public function has(string $option): bool
    {
        $missing = new \stdClass();
        return $this->active() && get_site_option($this->name($option), $missing) !== $missing;
    }

    public function value(string $option, mixed $default = null): mixed
    {
        return $this->active() ? get_site_option($this->name($option), $default) : $default;
    }

    public function managed(string $option): bool
    {
        // Existing networks retain site configuration until settings are explicitly adopted.
        return $this->active() && $this->policy($option) === 'network' && $this->has($option);
    }

    public function save(string $option, mixed $value, string $policy = 'default'): void
    {
        if (!$this->active()) {
            throw new \LogicException('Network settings require Multisite.');
        }
        update_site_option($this->name($option), $value);
        $policies = get_site_option(self::POLICIES, []);
        $policies = is_array($policies) ? $policies : [];
        $policies[$option] = $this->mandatory($option) || $policy === 'network' ? 'network' : 'default';
        update_site_option(self::POLICIES, $policies);
    }
}
