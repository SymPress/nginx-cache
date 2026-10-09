<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

final readonly class OptionSource
{
    public function __construct(private NetworkSettings $network = new NetworkSettings())
    {
    }

    public function value(string $option, mixed $default = false): mixed
    {
        $names = ['nginx_cache_path' => 'PATH', 'nginx_auto_purge' => 'AUTO_PURGE', 'sympress_nginx_cache_queue_enabled' => 'QUEUE', 'sympress_nginx_cache_prewarm_enabled' => 'PREWARM'];
        $suffix = $names[$option] ?? strtoupper(str_replace('sympress_nginx_cache_', '', $option));
        $constant = 'SYMPRESS_NGINX_CACHE_' . $suffix;
        // Credential readers resolve constants directly; raw option readers must retain ciphertext.
        if (!ConfigurationCatalog::secret($option) && LegacyConstants::value($constant) !== null) {
            return LegacyConstants::value($constant);
        }
        if ($this->network->managed($option)) {
            return $this->network->value($option, $default);
        }
        $missing = new \stdClass();
        $site = function_exists('get_option') ? get_option($option, $missing) : $missing;
        return $site !== $missing ? $site : $this->network->value($option, $default);
    }

    public function protectUpdate(mixed $value, string $option, mixed $oldValue): mixed
    {
        return $this->network->managed($option) ? $oldValue : $value;
    }

    public function managed(string $option): bool
    {
        return $this->network->managed($option);
    }
}
