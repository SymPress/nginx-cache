<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

final readonly class LegacyConstants
{
    private const array ALIASES = [
        'SYMPRESS_NGINX_CACHE_PATH'           => 'RT_WP_NGINX_HELPER_CACHE_PATH',
        'SYMPRESS_NGINX_CACHE_REDIS_HOST'     => 'RT_WP_NGINX_HELPER_REDIS_HOSTNAME',
        'SYMPRESS_NGINX_CACHE_REDIS_PORT'     => 'RT_WP_NGINX_HELPER_REDIS_PORT',
        'SYMPRESS_NGINX_CACHE_REDIS_DATABASE' => 'RT_WP_NGINX_HELPER_REDIS_DATABASE',
        'SYMPRESS_NGINX_CACHE_REDIS_PREFIX'   => 'RT_WP_NGINX_HELPER_REDIS_PREFIX',
        'SYMPRESS_NGINX_CACHE_REDIS_SOCKET'   => 'RT_WP_NGINX_HELPER_REDIS_UNIX_SOCKET',
        'SYMPRESS_NGINX_CACHE_REDIS_USERNAME' => 'RT_WP_NGINX_HELPER_REDIS_USERNAME',
        'SYMPRESS_NGINX_CACHE_REDIS_PASSWORD' => 'RT_WP_NGINX_HELPER_REDIS_PASSWORD',
    ];

    public static function value(string $name): mixed
    {
        if (defined($name)) {
            return constant($name);
        }
        $alias = self::ALIASES[$name] ?? null;
        return $alias !== null && defined($alias) ? constant($alias) : null;
    }
}
