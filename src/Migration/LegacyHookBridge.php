<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Migration;

use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class LegacyHookBridge
{
    public function __construct(private CacheManager $cache, private CompatibilitySettings $compatibility, private WordPressCacheSettings $settings)
    {
    }

    public function register(): void
    {
        if (!defined('SYMPRESS_NGINX_CACHE_NGINX_HELPER_HOOKS') || constant('SYMPRESS_NGINX_CACHE_NGINX_HELPER_HOOKS') !== true) {
            return;
        }
        add_filter('sympress_nginx_cache_purge_urls', static function (array $urls): array {
            return array_map(static fn (string $url): mixed => apply_filters('rt_nginx_helper_purge_url', $url), array_values(array_filter($urls, is_string(...))));
        });
        add_filter('sympress_nginx_cache_excluded_post_types', static fn (array $types): array => (array) apply_filters('rt_nginx_helper_exclude_post_types', $types));
        add_action('rt_nginx_helper_purge_all', $this->purge(...));
        add_action('sympress_nginx_cache_purged', static function (PurgeResult $result): void {
            if (!$result->successful || $result->dryRun || $result->partial || $result->mode !== PurgeMode::Full) {
                return;
            }

            do_action('rt_nginx_helper_after_purge_all', $result);
        });
    }

    public function purge(): void
    {
        $this->cache->purgeConfiguredPath(PurgeRequest::full('nginx-helper-bridge', 'legacy-hook', prewarm: $this->settings->prewarmEnabled()));
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    public function additionalUrls(array $urls): array
    {
        $extra = preg_split('/[\r\n,]+/', $this->compatibility->string('additional_purge_urls')) ?: [];
        foreach ($extra as $url) {
            $url = trim($url);
            if (str_starts_with($url, '/')) {
                $url = home_url($url);
            }
            if ($url === '') {
                continue;
            }

            $urls[] = $url;
        }
        return $urls;
    }
}
