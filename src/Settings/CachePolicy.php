<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

final readonly class CachePolicy
{
    public const array LIMITS = [
        'valid_seconds'    => ['default' => 600, 'min' => 60, 'max' => 86400],
        'inactive_seconds' => ['default' => 3600, 'min' => 60, 'max' => 604800],
        'max_size_mb'      => ['default' => 256, 'min' => 16, 'max' => 65536],
        'keys_zone_mb'     => ['default' => 100, 'min' => 1, 'max' => 1024],
    ];

    public static function register(): void
    {
        foreach (self::LIMITS as $name => $bounds) {
            register_setting('sympress_nginx_cache', 'sympress_nginx_cache_' . $name, [
                'type'              => 'integer',
                'sanitize_callback' => static fn (mixed $value): int => self::value($value, $bounds),
                'default'           => $bounds['default'],
            ]);
        }
    }

    /** @return array<string, int> */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::LIMITS as $name => $bounds) {
            $defaults['sympress_nginx_cache_' . $name] = $bounds['default'];
        }
        return $defaults;
    }

    /** @return array<string, int> */
    public static function values(): array
    {
        $policy = [];
        foreach (self::LIMITS as $name => $bounds) {
            $constant = 'SYMPRESS_NGINX_CACHE_' . strtoupper($name);
            $value = defined($constant) ? constant($constant) : (function_exists('get_option') ? get_option('sympress_nginx_cache_' . $name, $bounds['default']) : $bounds['default']);
            $policy[$name] = self::value($value, $bounds);
        }
        return $policy;
    }

    /** @param array{default: int, min: int, max: int} $bounds */
    private static function value(mixed $value, array $bounds): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer === false ? $bounds['default'] : max($bounds['min'], min($bounds['max'], $integer));
    }
}
