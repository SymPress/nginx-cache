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
    public const string RETENTION_OPTION = 'sympress_nginx_cache_retention_mode';
    public const string KEEP_WARM = 'keep_warm';
    public const string EVICT_INACTIVE = 'evict_inactive';

    public static function register(): void
    {
        foreach (self::LIMITS as $name => $bounds) {
            register_setting('sympress_nginx_cache', 'sympress_nginx_cache_' . $name, [
                'type'              => 'integer',
                'sanitize_callback' => static fn (mixed $value): int => self::value($value, $bounds),
                'default'           => $bounds['default'],
            ]);
        }
        register_setting('sympress_nginx_cache', self::RETENTION_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => static fn (mixed $value): string => in_array($value, [self::KEEP_WARM, self::EVICT_INACTIVE], true) ? $value : self::KEEP_WARM,
            'default'           => self::KEEP_WARM,
        ]);
    }

    /** @return array<string, int|string> */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::LIMITS as $name => $bounds) {
            $defaults['sympress_nginx_cache_' . $name] = $bounds['default'];
        }
        $defaults[self::RETENTION_OPTION] = self::KEEP_WARM;
        return $defaults;
    }

    public static function retention(): string
    {
        $options = new OptionSource();
        $mode = $options->value(self::RETENTION_OPTION, null);
        if (in_array($mode, [self::KEEP_WARM, self::EVICT_INACTIVE], true)) {
            return $mode;
        }
        // Upgrades: a deliberately changed inactivity timeout keeps its previous eviction behavior.
        $inactive = filter_var($options->value('sympress_nginx_cache_inactive_seconds', null), FILTER_VALIDATE_INT);
        return $inactive !== false && $inactive !== self::LIMITS['inactive_seconds']['default'] ? self::EVICT_INACTIVE : self::KEEP_WARM;
    }

    /** Refresh well inside the inactivity window so each cache lookup renews retention. */
    public static function keepWarmInterval(): int
    {
        return max(60, intdiv(self::values()['inactive_seconds'], 2));
    }

    /** @return array<string, int> */
    public static function values(): array
    {
        $policy = [];
        foreach (self::LIMITS as $name => $bounds) {
            $constant = 'SYMPRESS_NGINX_CACHE_' . strtoupper($name);
            $value = defined($constant) ? constant($constant) : (new OptionSource())->value('sympress_nginx_cache_' . $name, $bounds['default']);
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
