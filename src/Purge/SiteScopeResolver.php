<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Value\SiteKeyMatcher;

final class SiteScopeResolver
{
    /** @var array<string, list<string>> */
    private array $pathCache = [];

    public function reset(): void
    {
        $this->pathCache = [];
    }

    public function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    public function isIsolated(): bool
    {
        return !$this->isMultisite() || (defined('SYMPRESS_NGINX_CACHE_SITE_ISOLATED_PATH') && SYMPRESS_NGINX_CACHE_SITE_ISOLATED_PATH === true);
    }

    public function matcher(): SiteKeyMatcher
    {
        $roots = [];
        $home = function_exists('home_url') ? wp_parse_url(home_url('/')) : [];
        if (is_array($home) && is_string($home['host'] ?? null)) {
            $roots[strtolower($home['host'])] = is_string($home['path'] ?? null) ? $home['path'] : '/';
        }
        $site = $this->isMultisite() && function_exists('get_site') ? get_site() : null;
        if ($site instanceof \WP_Site) {
            $roots[strtolower($site->domain)] = $site->path;
        }
        $hosts = function_exists('apply_filters') ? (array) apply_filters('sympress_nginx_cache_site_hosts', array_keys($roots)) : array_keys($roots);
        foreach ($hosts as $host) {
            if (!is_string($host) || preg_match('/^[a-zA-Z0-9.-]+$/D', $host) !== 1) {
                continue;
            }

            $roots[strtolower($host)] ??= is_array($home) && is_string($home['path'] ?? null) ? $home['path'] : '/';
        }
        $paths = [];
        foreach ($roots as $host => $path) {
            $network = function_exists('get_current_network_id') ? get_current_network_id() : 0;
            $cacheKey = $network . ':' . $host;
            if (!isset($this->pathCache[$cacheKey])) {
                $sites = $this->isMultisite() && function_exists('get_sites') ? get_sites(['network_id' => $network, 'domain' => $host, 'number' => 0, 'orderby' => 'path', 'order' => 'DESC']) : [];
                $this->pathCache[$cacheKey] = array_values(array_map(static fn (\WP_Site $entry): string => $entry->path, $sites));
            }
            $paths[$host] = [...$this->pathCache[$cacheKey], $path];
        }
        return new SiteKeyMatcher($roots, $paths);
    }
}
