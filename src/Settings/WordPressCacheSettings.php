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
    public const string OPTION_BYPASS_URIS = 'sympress_nginx_cache_bypass_uris';
    public const string OPTION_BYPASS_COOKIES = 'sympress_nginx_cache_bypass_cookies';
    public const string OPTION_BYPASS_USER_AGENTS = 'sympress_nginx_cache_bypass_user_agents';
    public const string OPTION_QUERY_ALLOWLIST = 'sympress_nginx_cache_query_allowlist';
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

        register_setting('sympress_nginx_cache', self::OPTION_PATH, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizePath(...),
            'default'           => '',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_AUTO_PURGE, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 0,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_PROFILE, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeProfile(...),
            'default'           => CacheProfile::Safe->value,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_SELECTIVE_PURGE, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 1,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_QUEUE_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 1,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_DEBOUNCE_SECONDS, [
            'type'              => 'integer',
            'sanitize_callback' => $this->sanitizeInteger(...),
            'default'           => 10,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_PREWARM_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 0,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_PREWARM_URLS, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeTextarea(...),
            'default'           => '',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_REST_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 1,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_TAG_INDEX_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 1,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_DEBUG_HEADERS_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 0,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_LAYER_SYNC_ENABLED, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 0,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_REMOTE_ENDPOINTS, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeTextarea(...),
            'default'           => '',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_REMOTE_SECRET, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeSecret(...),
            'default'           => '',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_BYPASS_URIS, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeTextarea(...),
            'default'           => '',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_BYPASS_COOKIES, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeTextarea(...),
            'default'           => '',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_BYPASS_USER_AGENTS, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeTextarea(...),
            'default'           => '',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_QUERY_ALLOWLIST, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeTextarea(...),
            'default'           => '',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_HEARTBEAT_MODE, [
            'type'              => 'string',
            'sanitize_callback' => $this->sanitizeHeartbeatMode(...),
            'default'           => 'default',
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_HEARTBEAT_INTERVAL, [
            'type'              => 'integer',
            'sanitize_callback' => $this->sanitizeHeartbeatInterval(...),
            'default'           => 120,
        ]);
        register_setting('sympress_nginx_cache', self::OPTION_ONBOARDING_COMPLETED, [
            'type'              => 'boolean',
            'sanitize_callback' => static fn (mixed $value): int => $value ? 1 : 0,
            'default'           => 0,
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
            self::OPTION_PATH                  => '',
            self::OPTION_AUTO_PURGE            => 0,
            self::OPTION_PROFILE               => CacheProfile::Safe->value,
            self::OPTION_SELECTIVE_PURGE       => 1,
            self::OPTION_QUEUE_ENABLED         => 1,
            self::OPTION_DEBOUNCE_SECONDS      => 10,
            self::OPTION_PREWARM_ENABLED       => 0,
            self::OPTION_PREWARM_URLS          => '',
            self::OPTION_REST_ENABLED          => 1,
            self::OPTION_TAG_INDEX_ENABLED     => 1,
            self::OPTION_DEBUG_HEADERS_ENABLED => 0,
            self::OPTION_LAYER_SYNC_ENABLED    => 0,
            self::OPTION_REMOTE_ENDPOINTS      => '',
            self::OPTION_REMOTE_SECRET         => '',
            self::OPTION_BYPASS_URIS           => '',
            self::OPTION_BYPASS_COOKIES        => '',
            self::OPTION_BYPASS_USER_AGENTS    => '',
            self::OPTION_QUERY_ALLOWLIST       => '',
            self::OPTION_HEARTBEAT_MODE        => 'default',
            self::OPTION_HEARTBEAT_INTERVAL    => 120,
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
            'advanced_rules'   => [
                'bypass_uris'        => count($this->customBypassUris()),
                'bypass_cookies'     => count($this->customBypassCookies()),
                'bypass_user_agents' => count($this->customBypassUserAgents()),
                'query_allowlist'    => count($this->customQueryAllowlist()),
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
        $resolver->setAllowedTypes('advanced_rules', 'array');
        $resolver->setAllowedTypes('heartbeat', 'array');

        return $resolver->resolve();
    }

    /** @return list<string> */
    public function excludedPostTypes(): array
    {
        $postTypes = [];

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
