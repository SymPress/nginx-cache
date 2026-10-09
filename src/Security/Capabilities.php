<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Security;

use SymPress\NginxCache\Settings\OptionSource;

final readonly class Capabilities
{
    public const string PURGE_URL = 'sympress_nginx_cache_purge_url';
    public const string PURGE_SITE = 'sympress_nginx_cache_purge_site';
    public const string MANAGE = 'sympress_nginx_cache_manage';
    public const string PURGE_NETWORK = 'sympress_nginx_cache_purge_network';
    public const string ROLES_OPTION = 'sympress_nginx_cache_purge_roles';

    public function __construct(private OptionSource $options = new OptionSource())
    {
    }

    /**
     * @param list<string> $caps
     * @param array<mixed> $args
     * @return list<string>
     */
    public function map(array $caps, string $capability, int $userId, array $args = []): array
    {
        if (!in_array($capability, [self::PURGE_URL, self::PURGE_SITE, self::MANAGE, self::PURGE_NETWORK], true)) {
            return $caps;
        }
        $mapped = [$capability === self::PURGE_NETWORK && function_exists('is_multisite') && is_multisite() ? 'manage_network_options' : 'manage_options'];
        if ($capability === self::PURGE_URL && function_exists('get_userdata')) {
            $user = get_userdata($userId);
            $roles = defined('SYMPRESS_NGINX_CACHE_PURGE_ROLES') ? constant('SYMPRESS_NGINX_CACHE_PURGE_ROLES') : $this->options->value(self::ROLES_OPTION, []);
            if (is_array($roles) && $user !== false && array_intersect($user->roles, $roles) !== []) {
                $mapped = ['exist'];
            }
        }
        $mapped = function_exists('apply_filters') ? (array) apply_filters('sympress_nginx_cache_map_capability', $mapped, $capability, $userId, $args) : $mapped;
        $mapped = array_values(array_filter($mapped, is_string(...)));
        return $mapped !== [] ? $mapped : ['do_not_allow'];
    }
}
