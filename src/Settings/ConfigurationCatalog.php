<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

use SymPress\NginxCache\Key\CacheKeyStrategy;

final readonly class ConfigurationCatalog
{
    /** @return array<string, int|string|list<string>> */
    public static function defaults(): array
    {
        $defaults = [
            WordPressCacheSettings::OPTION_PATH                   => '',
            WordPressCacheSettings::OPTION_AUTO_PURGE             => 0,
            WordPressCacheSettings::OPTION_PROFILE                => 'safe',
            WordPressCacheSettings::OPTION_SELECTIVE_PURGE        => 1,
            WordPressCacheSettings::OPTION_QUEUE_ENABLED          => 1,
            WordPressCacheSettings::OPTION_DEBOUNCE_SECONDS       => 10,
            WordPressCacheSettings::OPTION_PREWARM_ENABLED        => 0,
            WordPressCacheSettings::OPTION_PREWARM_URLS           => '',
            WordPressCacheSettings::OPTION_REST_ENABLED           => 1,
            WordPressCacheSettings::OPTION_TAG_INDEX_ENABLED      => 1,
            WordPressCacheSettings::OPTION_DEBUG_HEADERS_ENABLED  => 0,
            WordPressCacheSettings::OPTION_LAYER_SYNC_ENABLED     => 0,
            WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS       => '',
            WordPressCacheSettings::OPTION_REMOTE_SECRET          => '',
            WordPressCacheSettings::OPTION_CLOUDFLARE_ENABLED     => 0,
            WordPressCacheSettings::OPTION_CLOUDFLARE_ZONE_ID     => '',
            WordPressCacheSettings::OPTION_CLOUDFLARE_API_TOKEN   => '',
            WordPressCacheSettings::OPTION_FULL_PURGE_MODE        => 'local_files',
            WordPressCacheSettings::OPTION_FULL_PURGE_ENDPOINT    => '',
            WordPressCacheSettings::OPTION_FULL_PURGE_HTTP_METHOD => 'PURGE',
            WordPressCacheSettings::OPTION_BYPASS_URIS            => '',
            WordPressCacheSettings::OPTION_BYPASS_COOKIES         => '',
            WordPressCacheSettings::OPTION_BYPASS_USER_AGENTS     => '',
            WordPressCacheSettings::OPTION_QUERY_ALLOWLIST        => '',
            WordPressCacheSettings::OPTION_PURGE_FEEDS            => 1,
            WordPressCacheSettings::OPTION_FEED_VARIANTS          => "feed/\nfeed/atom/\nfeed/rdf/",
            WordPressCacheSettings::OPTION_ARCHIVE_PAGE_LIMIT     => 1,
            WordPressCacheSettings::OPTION_PURGE_AMP              => 0,
            WordPressCacheSettings::OPTION_HEARTBEAT_MODE         => 'default',
            WordPressCacheSettings::OPTION_HEARTBEAT_INTERVAL     => 120,
            'sympress_nginx_cache_purge_roles'                    => [],
            'sympress_nginx_cache_key_template'                   => CacheKeyStrategy::TEMPLATE,
        ];
        foreach (CompatibilitySettings::defaults() as $name => $value) {
            $defaults[CompatibilitySettings::PREFIX . $name] = $value;
        }
        $defaults[CompatibilitySettings::REDIS_PASSWORD] = '';
        return $defaults + CachePolicy::defaults();
    }

    public static function secret(string $option): bool
    {
        return in_array($option, [WordPressCacheSettings::OPTION_REMOTE_SECRET, WordPressCacheSettings::OPTION_CLOUDFLARE_API_TOKEN, CompatibilitySettings::REDIS_PASSWORD], true);
    }
}
