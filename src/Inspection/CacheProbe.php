<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Inspection;

use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CacheProbe
{
    public function __construct(
        private HttpClientInterface $http,
        private WordPressCacheSettings $settings,
        private CacheFileResolver $files,
        private BypassRuleProvider $rules,
        private UrlPolicy $urls,
        private CacheKeyStrategy $keys,
        private ResponseCacheability $cacheability,
        private CacheClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function probe(string $url, string $cookieHeader = ''): array
    {
        $startedAt = $this->clock->highResolutionTimestamp();
        $url = $this->urls->normalizeSameOriginHttpUrl($url);

        if ($url === '') {
            return [
                'url'                  => '',
                'status'               => null,
                'duration_ms'          => $this->durationMilliseconds($startedAt),
                'profile'              => $this->settings->profile()->value,
                'cache_key_template'   => $this->keys->template(),
                'cache'                => [
                    'state'                 => 'unknown',
                    'x_nginx_cache'         => null,
                    'x_cache'               => null,
                    'x_sympress_cache_tags' => null,
                    'surrogate_key'         => null,
                    'x_sympress_cache_skip' => null,
                    'cache_control'         => null,
                    'response_cacheable'    => false,
                    'response_blockers'     => ['invalid-url'],
                ],
                'bypass_reasons'       => [],
                'candidate_files'      => [],
                'cache_key_candidates' => [],
                'error'                => 'URL is not allowed for cache probing.',
            ];
        }

        $headers = [];
        $status = null;
        $error = null;
        $requestHeaders = [
            'User-Agent' => 'SymPress Nginx Cache Probe',
        ];

        if ($cookieHeader !== '') {
            $requestHeaders['Cookie'] = $cookieHeader;
        }

        try {
            $response = $this->http->request('GET', $url, [
                'headers'       => $requestHeaders,
                'max_redirects' => 0,
                'timeout'       => 8,
            ]);
            $status = $response->getStatusCode();
            $headers = $this->normalizeHeaders($response->getHeaders(false));
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $xNginxCache = $this->header($headers, 'x-nginx-cache');
        $xCache = $this->header($headers, 'x-cache');
        $responseBlockers = $this->cacheability->blockingReasons($headers, $status);

        return [
            'url'                  => $url,
            'status'               => $status,
            'duration_ms'          => $this->durationMilliseconds($startedAt),
            'profile'              => $this->settings->profile()->value,
            'cache_key_template'   => $this->keys->template(),
            'cache'                => [
                'state'                 => $this->cacheState($xNginxCache, $xCache),
                'x_nginx_cache'         => $xNginxCache,
                'x_cache'               => $xCache,
                'x_sympress_cache_tags' => $this->header($headers, 'x-sympress-cache-tags'),
                'surrogate_key'         => $this->header($headers, 'surrogate-key'),
                'x_sympress_cache_skip' => $this->header($headers, 'x-sympress-cache-skip'),
                'cache_control'         => $this->header($headers, 'cache-control'),
                'response_cacheable'    => $responseBlockers === [],
                'response_blockers'     => $responseBlockers,
            ],
            'bypass_reasons'       => $this->bypassReasons($url, $cookieHeader),
            'candidate_files'      => $this->files->candidates($this->settings->cachePath(), $url),
            'cache_key_candidates' => $this->fileCandidates($url),
            'error'                => $error,
        ];
    }

    private function durationMilliseconds(float $startedAt): int
    {
        return (int) round($this->clock->elapsedSince($startedAt) * 1000);
    }

    /** @param array<string, list<string>> $headers */
    private function header(array $headers, string $name): ?string
    {
        $values = $headers[strtolower($name)] ?? null;

        return is_array($values) && isset($values[0]) ? $values[0] : null;
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = $values;
        }

        return $normalized;
    }

    private function cacheState(?string $xNginxCache, ?string $xCache): string
    {
        $value = strtoupper(trim((string) ($xNginxCache ?? $xCache ?? '')));

        foreach (['HIT', 'MISS', 'BYPASS', 'STALE', 'EXPIRED', 'UPDATING', 'REVALIDATED'] as $state) {
            if (str_contains($value, $state)) {
                return strtolower($state);
            }
        }

        return $value === '' ? 'unknown' : strtolower($value);
    }

    /** @return list<array{key: string, hash: string, files: list<array{path: string, exists: bool}>}> */
    private function fileCandidates(string $url): array
    {
        return array_map(
            static fn (array $candidate): array => [
                'key'   => $candidate['key'],
                'hash'  => $candidate['hash'],
                'files' => array_map(
                    static fn (string $file): array => [
                        'path'   => $file,
                        'exists' => is_file($file),
                    ],
                    $candidate['files'],
                ),
            ],
            $this->files->candidateDetails($this->settings->cachePath(), $url),
        );
    }

    /** @return list<string> */
    private function bypassReasons(string $url, string $cookieHeader): array
    {
        $reasons = [];
        $parts = $this->parseUrl($url);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        $query = is_array($parts) && is_string($parts['query'] ?? null) ? $parts['query'] : '';
        $rules = $this->rules->rules($this->settings->profile());

        if ($query !== '' && !$this->matchesAny($query, $rules['query_allowlist'])) {
            $reasons[] = 'query-string';
        }

        if ($this->matchesAny($path, $rules['uris'])) {
            $reasons[] = 'uri-rule';
        }

        if ($cookieHeader !== '' && $this->matchesAny($cookieHeader, $rules['cookies'])) {
            $reasons[] = 'cookie-rule';
        }

        return $reasons;
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match(sprintf('~%s~i', str_replace('~', '\~', $pattern)), $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|false */
    private function parseUrl(string $url): array|false
    {
        if (function_exists('wp_parse_url')) {
            return wp_parse_url($url);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when WordPress is not loaded.
        return parse_url($url);
    }
}
