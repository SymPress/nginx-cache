<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Value\CacheProfile;
use Symfony\Component\OptionsResolver\OptionsResolver;

final readonly class WordPressCacheSettings
{
    public const string OPTION_PATH = 'nginx_cache_path';
    public const string OPTION_AUTO_PURGE = 'nginx_auto_purge';
    public const string OPTION_PROFILE = 'sympress_nginx_cache_profile';
    public const string OPTION_SELECTIVE_PURGE = 'sympress_nginx_cache_selective_purge';
    public const string OPTION_QUEUE_ENABLED = 'sympress_nginx_cache_queue_enabled';
    public const string OPTION_DEBOUNCE_SECONDS = 'sympress_nginx_cache_debounce_seconds';
    public const string OPTION_PREWARM_ENABLED = 'sympress_nginx_cache_prewarm_enabled';
    public const string OPTION_PREWARM_URLS = 'sympress_nginx_cache_prewarm_urls';
    public const string OPTION_REST_ENABLED = 'sympress_nginx_cache_rest_enabled';
    public const string OPTION_TAG_INDEX_ENABLED = 'sympress_nginx_cache_tag_index_enabled';
    public const string OPTION_DEBUG_HEADERS_ENABLED = 'sympress_nginx_cache_debug_headers_enabled';
    public const string OPTION_LAYER_SYNC_ENABLED = 'sympress_nginx_cache_layer_sync_enabled';
    public const string OPTION_REMOTE_ENDPOINTS = 'sympress_nginx_cache_remote_endpoints';
    public const string OPTION_REMOTE_SECRET = 'sympress_nginx_cache_remote_secret';
    public const string OPTION_CLOUDFLARE_ENABLED = 'sympress_nginx_cache_cloudflare_enabled';
    public const string OPTION_CLOUDFLARE_ZONE_ID = 'sympress_nginx_cache_cloudflare_zone_id';
    public const string OPTION_CLOUDFLARE_API_TOKEN = 'sympress_nginx_cache_cloudflare_api_token';
    public const string OPTION_FULL_PURGE_MODE = 'sympress_nginx_cache_full_purge_mode';
    public const string OPTION_FULL_PURGE_ENDPOINT = 'sympress_nginx_cache_full_purge_endpoint';
    public const string OPTION_FULL_PURGE_HTTP_METHOD = 'sympress_nginx_cache_full_purge_http_method';
    public const string OPTION_BYPASS_URIS = 'sympress_nginx_cache_bypass_uris';
    public const string OPTION_BYPASS_COOKIES = 'sympress_nginx_cache_bypass_cookies';
    public const string OPTION_BYPASS_USER_AGENTS = 'sympress_nginx_cache_bypass_user_agents';
    public const string OPTION_QUERY_ALLOWLIST = 'sympress_nginx_cache_query_allowlist';
    public const string OPTION_PURGE_FEEDS = 'sympress_nginx_cache_purge_feeds';
    public const string OPTION_FEED_VARIANTS = 'sympress_nginx_cache_feed_variants';
    public const string OPTION_ARCHIVE_PAGE_LIMIT = 'sympress_nginx_cache_archive_page_limit';
    public const string OPTION_PURGE_AMP = 'sympress_nginx_cache_purge_amp';
    public const string OPTION_HEARTBEAT_MODE = 'sympress_nginx_cache_heartbeat_mode';
    public const string OPTION_HEARTBEAT_INTERVAL = 'sympress_nginx_cache_heartbeat_interval';
    public const string OPTION_ONBOARDING_COMPLETED = 'sympress_nginx_cache_onboarding_completed';
    public const string TEXT_DOMAIN = 'sympress-nginx-cache';

    public function __construct(
        private string $defaultPath,
        private ?UrlPolicy $urlPolicy = null,
    ) {
    }

    public function register(): void
    {
        if (!function_exists('register_setting')) {
            return;
        }

        $boolean = static fn (mixed $value): int => $value ? 1 : 0;

        $this->registerSetting(self::OPTION_PATH, 'string', $this->sanitizePath(...), '');
        $this->registerSetting(self::OPTION_AUTO_PURGE, 'boolean', $boolean, 0);
        $this->registerSetting(self::OPTION_PROFILE, 'string', $this->sanitizeProfile(...), CacheProfile::Safe->value);
        $this->registerSetting(self::OPTION_SELECTIVE_PURGE, 'boolean', $boolean, 1);
        $this->registerSetting(self::OPTION_QUEUE_ENABLED, 'boolean', $boolean, 1);
        $this->registerSetting(self::OPTION_DEBOUNCE_SECONDS, 'integer', $this->sanitizeInteger(...), 10);
        $this->registerSetting(self::OPTION_PREWARM_ENABLED, 'boolean', $boolean, 0);
        $this->registerSetting(self::OPTION_PREWARM_URLS, 'string', $this->sanitizeTextarea(...), '');
        $this->registerSetting(self::OPTION_REST_ENABLED, 'boolean', $boolean, 1);
        $this->registerSetting(self::OPTION_TAG_INDEX_ENABLED, 'boolean', $boolean, 1);
        $this->registerSetting(self::OPTION_DEBUG_HEADERS_ENABLED, 'boolean', $boolean, 0);
        $this->registerSetting(self::OPTION_LAYER_SYNC_ENABLED, 'boolean', $boolean, 0);
        $this->registerSetting(self::OPTION_REMOTE_ENDPOINTS, 'string', $this->sanitizeTextarea(...), '');
        $this->registerSetting(self::OPTION_REMOTE_SECRET, 'string', $this->sanitizeSecret(...), '');
        $this->registerSetting(self::OPTION_CLOUDFLARE_ENABLED, 'boolean', $boolean, 0);
        $this->registerSetting(self::OPTION_CLOUDFLARE_ZONE_ID, 'string', $this->sanitizeSecret(...), '');
        $this->registerSetting(self::OPTION_CLOUDFLARE_API_TOKEN, 'string', $this->sanitizeSecret(...), '');
        $this->registerSetting(self::OPTION_FULL_PURGE_MODE, 'string', $this->sanitizeFullPurgeMode(...), 'local_files');
        $this->registerSetting(self::OPTION_FULL_PURGE_ENDPOINT, 'string', $this->sanitizeSecret(...), '');
        $this->registerSetting(self::OPTION_FULL_PURGE_HTTP_METHOD, 'string', $this->sanitizeFullPurgeHttpMethod(...), 'PURGE');
        $this->registerSetting(self::OPTION_BYPASS_URIS, 'string', $this->sanitizeTextarea(...), '');
        $this->registerSetting(self::OPTION_BYPASS_COOKIES, 'string', $this->sanitizeTextarea(...), '');
        $this->registerSetting(self::OPTION_BYPASS_USER_AGENTS, 'string', $this->sanitizeTextarea(...), '');
        $this->registerSetting(self::OPTION_QUERY_ALLOWLIST, 'string', $this->sanitizeTextarea(...), '');
        $this->registerSetting(self::OPTION_PURGE_FEEDS, 'boolean', $boolean, 1);
        $this->registerSetting(self::OPTION_FEED_VARIANTS, 'string', $this->sanitizeTextarea(...), "feed/\nfeed/atom/\nfeed/rdf/");
        $this->registerSetting(self::OPTION_ARCHIVE_PAGE_LIMIT, 'integer', $this->sanitizeInteger(...), 1);
        $this->registerSetting(self::OPTION_PURGE_AMP, 'boolean', $boolean, 0);
        $this->registerSetting(self::OPTION_HEARTBEAT_MODE, 'string', $this->sanitizeHeartbeatMode(...), 'default');
        $this->registerSetting(self::OPTION_HEARTBEAT_INTERVAL, 'integer', $this->sanitizeHeartbeatInterval(...), 120);
        $this->registerSetting(self::OPTION_ONBOARDING_COMPLETED, 'boolean', $boolean, 0);
    }

    /** @param callable(mixed): mixed $sanitize */
    private function registerSetting(string $option, string $type, callable $sanitize, mixed $default): void
    {
        register_setting('sympress_nginx_cache', $option, [
            'type'              => $type,
            'sanitize_callback' => $sanitize,
            'default'           => $default,
        ]);
    }

    public function cachePath(): string
    {
        $path = $this->constantValue('SYMPRESS_NGINX_CACHE_PATH')
            ?? $this->constantValue('NGINX_CACHE_PATH')
            ?? $this->optionString(self::OPTION_PATH)
            ?? $this->defaultPath;

        if (function_exists('apply_filters')) {
            $path = (string) apply_filters('sympress_nginx_cache_path', $path);
        }

        return $this->normalizePath($path);
    }

    public function defaultPath(): string
    {
        return $this->normalizePath($this->defaultPath);
    }

    public function pathSource(): string
    {
        if ($this->constantValue('SYMPRESS_NGINX_CACHE_PATH') !== null) {
            return 'SYMPRESS_NGINX_CACHE_PATH';
        }

        if ($this->constantValue('NGINX_CACHE_PATH') !== null) {
            return 'NGINX_CACHE_PATH';
        }

        if ($this->optionString(self::OPTION_PATH) !== null) {
            return 'database';
        }

        return 'default';
    }

    public function pathManagedByConstant(): bool
    {
        return $this->constantValue('SYMPRESS_NGINX_CACHE_PATH') !== null
            || $this->constantValue('NGINX_CACHE_PATH') !== null;
    }

    public function autoPurgeEnabled(): bool
    {
        $constant = $this->constantValue('SYMPRESS_NGINX_CACHE_AUTO_PURGE')
            ?? $this->constantValue('NGINX_AUTO_PURGE');

        if ($constant !== null) {
            return filter_var($constant, FILTER_VALIDATE_BOOL);
        }

        if (!function_exists('get_option')) {
            return false;
        }

        return (bool) get_option(self::OPTION_AUTO_PURGE, false);
    }

    public function profile(): CacheProfile
    {
        $profile = $this->constantValue('SYMPRESS_NGINX_CACHE_PROFILE')
            ?? $this->rawOptionString(self::OPTION_PROFILE)
            ?? CacheProfile::Safe->value;

        if (function_exists('apply_filters')) {
            $profile = (string) apply_filters('sympress_nginx_cache_profile', $profile);
        }

        return CacheProfile::fromString($profile);
    }

    public function selectivePurgeEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_SELECTIVE_PURGE,
            $this->constantBool('SYMPRESS_NGINX_CACHE_SELECTIVE_PURGE', true),
        );
    }

    public function queueEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_QUEUE_ENABLED,
            $this->constantBool('SYMPRESS_NGINX_CACHE_QUEUE', true),
        );
    }

    public function debounceSeconds(): int
    {
        $value = $this->integerOption(
            self::OPTION_DEBOUNCE_SECONDS,
            $this->constantInt('SYMPRESS_NGINX_CACHE_DEBOUNCE_SECONDS', 10),
        );

        return max(0, min(300, $value));
    }

    public function prewarmEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_PREWARM_ENABLED,
            $this->constantBool('SYMPRESS_NGINX_CACHE_PREWARM', false),
        );
    }

    public function restEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_REST_ENABLED,
            $this->constantBool('SYMPRESS_NGINX_CACHE_REST', true),
        );
    }

    public function tagIndexEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_TAG_INDEX_ENABLED,
            $this->constantBool('SYMPRESS_NGINX_CACHE_TAG_INDEX', true),
        );
    }

    public function debugHeadersEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_DEBUG_HEADERS_ENABLED,
            $this->constantBool('SYMPRESS_NGINX_CACHE_DEBUG_HEADERS', false),
        );
    }

    public function layerSyncEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_LAYER_SYNC_ENABLED,
            $this->constantBool('SYMPRESS_NGINX_CACHE_LAYER_SYNC', false),
        );
    }

    /** @return list<string> */
    public function remoteEndpoints(): array
    {
        $endpoints = $this->urlsFromText(
            $this->constantValue('SYMPRESS_NGINX_CACHE_REMOTE_ENDPOINTS')
            ?? $this->rawOptionString(self::OPTION_REMOTE_ENDPOINTS)
            ?? '',
        );

        if (function_exists('apply_filters')) {
            $endpoints = (array) apply_filters('sympress_nginx_cache_remote_endpoints', $endpoints);
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map($this->urlPolicy()->normalizeRemoteEndpoint(...), $endpoints),
                    static fn (string $url): bool => $url !== '',
                ),
            ),
        );
    }

    public function remoteSecret(): ?string
    {
        $secret = $this->constantValue('SYMPRESS_NGINX_CACHE_REMOTE_SECRET')
            ?? $this->rawOptionString(self::OPTION_REMOTE_SECRET);

        if (!is_string($secret)) {
            return null;
        }

        $secret = trim($secret);

        return $secret !== '' ? $secret : null;
    }

    public function cloudflareEnabled(): bool
    {
        $enabled = $this->booleanOption(
            self::OPTION_CLOUDFLARE_ENABLED,
            $this->constantBool('SYMPRESS_NGINX_CACHE_CLOUDFLARE', false),
        );

        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('sympress_nginx_cache_cloudflare_enabled', $enabled);
        }

        return $enabled;
    }

    public function cloudflareConfigured(): bool
    {
        return $this->cloudflareEnabled()
            && $this->cloudflareZoneId() !== null
            && $this->cloudflareApiToken() !== null;
    }

    public function cloudflareZoneId(): ?string
    {
        $zoneId = $this->constantValue('SYMPRESS_NGINX_CACHE_CLOUDFLARE_ZONE_ID')
            ?? $this->rawOptionString(self::OPTION_CLOUDFLARE_ZONE_ID);

        if (!is_string($zoneId)) {
            return null;
        }

        $zoneId = (string) preg_replace('/[^A-Za-z0-9_-]+/', '', trim($zoneId));

        if (function_exists('apply_filters')) {
            $zoneId = (string) apply_filters('sympress_nginx_cache_cloudflare_zone_id', $zoneId);
            $zoneId = (string) preg_replace('/[^A-Za-z0-9_-]+/', '', trim($zoneId));
        }

        return $zoneId !== '' ? $zoneId : null;
    }

    public function cloudflareApiToken(): ?string
    {
        $token = $this->constantValue('SYMPRESS_NGINX_CACHE_CLOUDFLARE_API_TOKEN')
            ?? $this->rawOptionString(self::OPTION_CLOUDFLARE_API_TOKEN);

        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);

        if (function_exists('apply_filters')) {
            $token = trim((string) apply_filters('sympress_nginx_cache_cloudflare_api_token', $token));
        }

        return $token !== '' ? $token : null;
    }

    public function fullPurgeMode(): string
    {
        $mode = $this->constantValue('SYMPRESS_NGINX_CACHE_FULL_PURGE_MODE')
            ?? $this->rawOptionString(self::OPTION_FULL_PURGE_MODE)
            ?? 'local_files';

        if (function_exists('apply_filters')) {
            $mode = (string) apply_filters('sympress_nginx_cache_full_purge_mode', $mode);
        }

        return in_array($mode, ['local_files', 'endpoint'], true) ? $mode : 'local_files';
    }

    public function fullPurgeEndpoint(): ?string
    {
        $endpoint = $this->constantValue('SYMPRESS_NGINX_CACHE_FULL_PURGE_ENDPOINT')
            ?? $this->rawOptionString(self::OPTION_FULL_PURGE_ENDPOINT);

        if (!is_string($endpoint) || trim($endpoint) === '') {
            return null;
        }

        $endpoint = trim($endpoint);

        if (function_exists('apply_filters')) {
            $endpoint = (string) apply_filters('sympress_nginx_cache_full_purge_endpoint', $endpoint);
        }

        return $endpoint !== '' ? $endpoint : null;
    }

    public function fullPurgeHttpMethod(): string
    {
        $method = $this->constantValue('SYMPRESS_NGINX_CACHE_FULL_PURGE_HTTP_METHOD')
            ?? $this->rawOptionString(self::OPTION_FULL_PURGE_HTTP_METHOD)
            ?? 'PURGE';

        $method = strtoupper(trim($method));

        return in_array($method, ['DELETE', 'POST', 'PURGE'], true) ? $method : 'PURGE';
    }

    /** @return list<string> */
    public function customBypassUris(): array
    {
        return $this->stringsFromText($this->rawOptionString(self::OPTION_BYPASS_URIS) ?? '');
    }

    /** @return list<string> */
    public function customBypassCookies(): array
    {
        return $this->stringsFromText($this->rawOptionString(self::OPTION_BYPASS_COOKIES) ?? '');
    }

    /** @return list<string> */
    public function customBypassUserAgents(): array
    {
        return $this->stringsFromText($this->rawOptionString(self::OPTION_BYPASS_USER_AGENTS) ?? '');
    }

    /** @return list<string> */
    public function customQueryAllowlist(): array
    {
        return $this->stringsFromText($this->rawOptionString(self::OPTION_QUERY_ALLOWLIST) ?? '');
    }

    public function purgeFeedsEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_PURGE_FEEDS,
            $this->constantBool('SYMPRESS_NGINX_CACHE_PURGE_FEEDS', true),
        );
    }

    /** @return list<string> */
    public function feedVariants(): array
    {
        $variants = $this->stringsFromText(
            $this->constantValue('SYMPRESS_NGINX_CACHE_FEED_VARIANTS')
            ?? $this->rawOptionString(self::OPTION_FEED_VARIANTS)
            ?? "feed/\nfeed/atom/\nfeed/rdf/",
        );

        if (function_exists('apply_filters')) {
            $variants = (array) apply_filters('sympress_nginx_cache_feed_variants', $variants);
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map($this->normalizeFeedVariant(...), $variants),
                    static fn (string $variant): bool => $variant !== '',
                ),
            ),
        );
    }

    public function maxFeedUrls(): int
    {
        $value = $this->constantInt('SYMPRESS_NGINX_CACHE_FEED_URL_LIMIT', 60);

        if (function_exists('apply_filters')) {
            $value = (int) apply_filters('sympress_nginx_cache_feed_url_limit', $value);
        }

        return max(0, min(500, $value));
    }

    public function archivePageLimit(): int
    {
        $value = $this->integerOption(
            self::OPTION_ARCHIVE_PAGE_LIMIT,
            $this->constantInt('SYMPRESS_NGINX_CACHE_ARCHIVE_PAGE_LIMIT', 1),
        );

        if (function_exists('apply_filters')) {
            $value = (int) apply_filters('sympress_nginx_cache_archive_page_limit', $value);
        }

        return max(1, min(50, $value));
    }

    public function purgeAmpEnabled(): bool
    {
        return $this->booleanOption(
            self::OPTION_PURGE_AMP,
            $this->constantBool('SYMPRESS_NGINX_CACHE_PURGE_AMP', false),
        );
    }

    public function maxSurrogateHeaderLength(): int
    {
        $value = $this->constantInt('SYMPRESS_NGINX_CACHE_SURROGATE_HEADER_LIMIT', 32512);

        if (function_exists('apply_filters')) {
            $value = (int) apply_filters('sympress_nginx_cache_surrogate_header_limit', $value);
        }

        return max(512, min(65536, $value));
    }

    public function maxCloudflareHeaderLength(): int
    {
        $value = $this->constantInt('SYMPRESS_NGINX_CACHE_CLOUDFLARE_HEADER_LIMIT', 16000);

        if (function_exists('apply_filters')) {
            $value = (int) apply_filters('sympress_nginx_cache_cloudflare_header_limit', $value);
        }

        return max(512, min(16000, $value));
    }

    public function heartbeatMode(): string
    {
        $mode = $this->constantValue('SYMPRESS_NGINX_CACHE_HEARTBEAT_MODE')
            ?? $this->rawOptionString(self::OPTION_HEARTBEAT_MODE)
            ?? 'default';

        return in_array($mode, ['default', 'reduce', 'disable'], true) ? $mode : 'default';
    }

    public function heartbeatInterval(): int
    {
        $value = $this->integerOption(
            self::OPTION_HEARTBEAT_INTERVAL,
            $this->constantInt('SYMPRESS_NGINX_CACHE_HEARTBEAT_INTERVAL', 120),
        );

        return max(60, min(300, $value));
    }

    public function onboardingCompleted(): bool
    {
        return $this->booleanOption(self::OPTION_ONBOARDING_COMPLETED, false);
    }

    public function hasCustomizedOptions(): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }

        $defaults = [
            self::OPTION_PATH                   => '',
            self::OPTION_AUTO_PURGE             => 0,
            self::OPTION_PROFILE                => CacheProfile::Safe->value,
            self::OPTION_SELECTIVE_PURGE        => 1,
            self::OPTION_QUEUE_ENABLED          => 1,
            self::OPTION_DEBOUNCE_SECONDS       => 10,
            self::OPTION_PREWARM_ENABLED        => 0,
            self::OPTION_PREWARM_URLS           => '',
            self::OPTION_REST_ENABLED           => 1,
            self::OPTION_TAG_INDEX_ENABLED      => 1,
            self::OPTION_DEBUG_HEADERS_ENABLED  => 0,
            self::OPTION_LAYER_SYNC_ENABLED     => 0,
            self::OPTION_REMOTE_ENDPOINTS       => '',
            self::OPTION_REMOTE_SECRET          => '',
            self::OPTION_CLOUDFLARE_ENABLED     => 0,
            self::OPTION_CLOUDFLARE_ZONE_ID     => '',
            self::OPTION_CLOUDFLARE_API_TOKEN   => '',
            self::OPTION_FULL_PURGE_MODE        => 'local_files',
            self::OPTION_FULL_PURGE_ENDPOINT    => '',
            self::OPTION_FULL_PURGE_HTTP_METHOD => 'PURGE',
            self::OPTION_BYPASS_URIS            => '',
            self::OPTION_BYPASS_COOKIES         => '',
            self::OPTION_BYPASS_USER_AGENTS     => '',
            self::OPTION_QUERY_ALLOWLIST        => '',
            self::OPTION_PURGE_FEEDS            => 1,
            self::OPTION_FEED_VARIANTS          => "feed/\nfeed/atom/\nfeed/rdf/",
            self::OPTION_ARCHIVE_PAGE_LIMIT     => 1,
            self::OPTION_PURGE_AMP              => 0,
            self::OPTION_HEARTBEAT_MODE         => 'default',
            self::OPTION_HEARTBEAT_INTERVAL     => 120,
        ];

        foreach ($defaults as $option => $default) {
            $value = get_option($option, $default);

            if (is_int($default)) {
                if ((int) $value !== $default) {
                    return true;
                }

                continue;
            }

            if (is_string($default) && trim((string) $value) !== $default) {
                return true;
            }
        }

        return false;
    }

    public function cacheLevels(): string
    {
        $levels = $this->constantValue('SYMPRESS_NGINX_CACHE_LEVELS') ?? '1:2';

        if (function_exists('apply_filters')) {
            $levels = (string) apply_filters('sympress_nginx_cache_levels', $levels);
        }

        return preg_match('/^\d+(?::\d+)*$/', $levels) === 1 ? $levels : '1:2';
    }

    public function maxPrewarmUrls(): int
    {
        $value = $this->constantInt('SYMPRESS_NGINX_CACHE_PREWARM_LIMIT', 20);

        if (function_exists('apply_filters')) {
            $value = (int) apply_filters('sympress_nginx_cache_prewarm_limit', $value);
        }

        return max(0, min(200, $value));
    }

    public function prewarmDelayMilliseconds(): int
    {
        $value = $this->constantInt('SYMPRESS_NGINX_CACHE_PREWARM_DELAY_MS', 100);

        if (function_exists('apply_filters')) {
            $value = (int) apply_filters('sympress_nginx_cache_prewarm_delay_ms', $value);
        }

        return max(0, min(5000, $value));
    }

    /** @return list<string> */
    public function prewarmUrls(): array
    {
        $urls = $this->urlsFromText($this->rawOptionString(self::OPTION_PREWARM_URLS) ?? '');

        if (function_exists('home_url')) {
            array_unshift($urls, home_url('/'));
        }

        if (function_exists('apply_filters')) {
            $urls = (array) apply_filters('sympress_nginx_cache_prewarm_urls', $urls);
        }

        return array_values(
            array_slice(
                array_unique(
                    array_filter(
                        array_map($this->urlPolicy()->normalizeSameOriginHttpUrl(...), $urls),
                        static fn (string $url): bool => $url !== '',
                    ),
                ),
                0,
                $this->maxPrewarmUrls(),
            ),
        );
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'path'             => $this->cachePath(),
            'path_source'      => $this->pathSource(),
            'auto_purge'       => $this->autoPurgeEnabled(),
            'profile'          => $this->profile()->value,
            'selective_purge'  => $this->selectivePurgeEnabled(),
            'queue'            => $this->queueEnabled(),
            'debounce_seconds' => $this->debounceSeconds(),
            'prewarm'          => $this->prewarmEnabled(),
            'prewarm_limit'    => $this->maxPrewarmUrls(),
            'rest'             => $this->restEnabled(),
            'levels'           => $this->cacheLevels(),
            'tag_index'        => $this->tagIndexEnabled(),
            'debug_headers'    => $this->debugHeadersEnabled(),
            'layer_sync'       => $this->layerSyncEnabled(),
            'remote_endpoints' => count($this->remoteEndpoints()),
            'cloudflare'       => [
                'enabled'    => $this->cloudflareEnabled(),
                'configured' => $this->cloudflareConfigured(),
                'zone_id'    => $this->cloudflareZoneId() !== null,
                'api_token'  => $this->cloudflareApiToken() !== null,
                'header_max' => $this->maxCloudflareHeaderLength(),
            ],
            'full_purge'       => [
                'mode'        => $this->fullPurgeMode(),
                'endpoint'    => $this->fullPurgeEndpoint() !== null,
                'http_method' => $this->fullPurgeHttpMethod(),
            ],
            'advanced_rules'   => [
                'bypass_uris'        => count($this->customBypassUris()),
                'bypass_cookies'     => count($this->customBypassCookies()),
                'bypass_user_agents' => count($this->customBypassUserAgents()),
                'query_allowlist'    => count($this->customQueryAllowlist()),
                'purge_feeds'        => $this->purgeFeedsEnabled(),
                'feed_variants'      => count($this->feedVariants()),
                'archive_page_limit' => $this->archivePageLimit(),
                'purge_amp'          => $this->purgeAmpEnabled(),
            ],
            'heartbeat'        => [
                'mode'     => $this->heartbeatMode(),
                'interval' => $this->heartbeatInterval(),
            ],
        ]);
        $resolver->setAllowedTypes('path', 'string');
        $resolver->setAllowedTypes('path_source', 'string');
        $resolver->setAllowedTypes('auto_purge', 'bool');
        $resolver->setAllowedTypes('profile', 'string');
        $resolver->setAllowedTypes('selective_purge', 'bool');
        $resolver->setAllowedTypes('queue', 'bool');
        $resolver->setAllowedTypes('debounce_seconds', 'int');
        $resolver->setAllowedTypes('prewarm', 'bool');
        $resolver->setAllowedTypes('prewarm_limit', 'int');
        $resolver->setAllowedTypes('rest', 'bool');
        $resolver->setAllowedTypes('levels', 'string');
        $resolver->setAllowedTypes('tag_index', 'bool');
        $resolver->setAllowedTypes('debug_headers', 'bool');
        $resolver->setAllowedTypes('layer_sync', 'bool');
        $resolver->setAllowedTypes('remote_endpoints', 'int');
        $resolver->setAllowedTypes('cloudflare', 'array');
        $resolver->setAllowedTypes('full_purge', 'array');
        $resolver->setAllowedTypes('advanced_rules', 'array');
        $resolver->setAllowedTypes('heartbeat', 'array');

        return $resolver->resolve();
    }

    /** @return list<string> */
    public function excludedPostTypes(): array
    {
        $postTypes = ['nav_menu_item', 'revision', 'customize_changeset', 'oembed_cache'];

        if (function_exists('apply_filters')) {
            $postTypes = (array) apply_filters('nginx_cache_excluded_post_types', $postTypes);
            $postTypes = (array) apply_filters('sympress_nginx_cache_excluded_post_types', $postTypes);
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $postType): string => is_string($postType) ? trim($postType) : '',
                        $postTypes,
                    ),
                    static fn (string $postType): bool => $postType !== '',
                ),
            ),
        );
    }

    public function sanitizePath(mixed $value): string
    {
        $path = is_string($value) ? $value : '';

        if (function_exists('wp_unslash')) {
            $path = (string) wp_unslash($path);
        }

        if (function_exists('sanitize_text_field')) {
            return (string) sanitize_text_field($path);
        }

        return trim(strip_tags($path));
    }

    public function sanitizeTextarea(mixed $value): string
    {
        $text = is_string($value) ? $value : '';

        if (function_exists('wp_unslash')) {
            $text = (string) wp_unslash($text);
        }

        if (function_exists('sanitize_textarea_field')) {
            return (string) sanitize_textarea_field($text);
        }

        return trim(strip_tags($text));
    }

    public function sanitizeProfile(mixed $value): string
    {
        $profile = is_string($value) ? $value : '';

        if (function_exists('sanitize_key')) {
            $profile = sanitize_key($profile);
        }

        return CacheProfile::fromString($profile)->value;
    }

    public function sanitizeSecret(mixed $value): string
    {
        $secret = is_string($value) ? $value : '';

        if (function_exists('wp_unslash')) {
            $secret = (string) wp_unslash($secret);
        }

        return trim(strip_tags($secret));
    }

    public function sanitizeInteger(mixed $value): int
    {
        return max(0, (int) $value);
    }

    public function sanitizeHeartbeatMode(mixed $value): string
    {
        $mode = is_string($value) ? $value : 'default';

        if (function_exists('sanitize_key')) {
            $mode = sanitize_key($mode);
        }

        return in_array($mode, ['default', 'reduce', 'disable'], true) ? $mode : 'default';
    }

    public function sanitizeFullPurgeMode(mixed $value): string
    {
        $mode = is_string($value) ? $value : 'local_files';

        if (function_exists('sanitize_key')) {
            $mode = sanitize_key($mode);
        }

        return in_array($mode, ['local_files', 'endpoint'], true) ? $mode : 'local_files';
    }

    public function sanitizeFullPurgeHttpMethod(mixed $value): string
    {
        $method = is_string($value) ? strtoupper(trim($value)) : 'PURGE';

        return in_array($method, ['DELETE', 'POST', 'PURGE'], true) ? $method : 'PURGE';
    }

    public function sanitizeHeartbeatInterval(mixed $value): int
    {
        return max(60, min(300, (int) $value));
    }

    private function optionString(string $option): ?string
    {
        $value = $this->rawOptionString($option);

        if ($value === null) {
            return null;
        }

        $value = $this->normalizePath($value);

        return $value !== '' ? $value : null;
    }

    private function rawOptionString(string $option): ?string
    {
        if (!function_exists('get_option')) {
            return null;
        }

        $value = get_option($option, '');

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function constantValue(string $constant): ?string
    {
        if (!defined($constant)) {
            return null;
        }

        $value = constant($constant);

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function booleanOption(string $option, bool $default): bool
    {
        if (!function_exists('get_option')) {
            return $default;
        }

        return (bool) get_option($option, $default);
    }

    private function integerOption(string $option, int $default): int
    {
        if (!function_exists('get_option')) {
            return $default;
        }

        return (int) get_option($option, $default);
    }

    private function constantBool(string $constant, bool $default): bool
    {
        $value = $this->constantValue($constant);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function constantInt(string $constant, int $default): int
    {
        $value = $this->constantValue($constant);

        return $value === null ? $default : (int) $value;
    }

    /** @return list<string> */
    private function urlsFromText(string $text): array
    {
        return array_values(
            array_filter(
                array_map('trim', preg_split('/[\r\n,]+/', $text) ?: []),
                static fn (string $url): bool => $url !== '',
            ),
        );
    }

    /** @return list<string> */
    private function stringsFromText(string $text): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map('trim', preg_split('/[\r\n]+/', $text) ?: []),
                    static fn (string $value): bool => $value !== '',
                ),
            ),
        );
    }

    private function normalizeFeedVariant(string $variant): string
    {
        $variant = trim(str_replace('\\', '/', $variant));
        $variant = trim($variant, '/');

        if ($variant === '' || preg_match('/[^A-Za-z0-9._-]/', $variant) === 1) {
            return '';
        }

        return $variant . '/';
    }

    private function urlPolicy(): UrlPolicy
    {
        return $this->urlPolicy ?? new UrlPolicy();
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }
}
