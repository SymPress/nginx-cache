<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpUrlCachePurger
{
    public function __construct(
        private HttpClientInterface $http,
        private CompatibilitySettings $compatibility,
        private WordPressCacheSettings $settings,
        private UrlPolicy $urls,
        private CacheClock $clock,
    ) {
    }

    public function purge(PurgeRequest $request): PurgeResult
    {
        $started = $this->clock->highResolutionTimestamp();
        $created = $this->clock->timestamp();
        try {
            $prefix = $this->compatibility->string('http_purge_prefix');
            $secret = $this->settings->remoteSecret();
            if ($secret === null || preg_match('#^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$#D', $prefix) !== 1) {
                throw new \RuntimeException('A valid purge prefix and signing secret are required.');
            }
            $endpoints = [];
            foreach ($request->urls as $url) {
                $normalized = $this->urls->normalizeSameOriginHttpUrl($url);
                if ($normalized === '') {
                    throw new \RuntimeException('Invalid purge origin.');
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Parse the already validated URL without requiring WordPress in unit tests.
                $parts = parse_url($normalized);
                if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                    throw new \RuntimeException('Invalid purge URL.');
                }
                $endpoints[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $prefix . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }
            if (!$request->dryRun) {
                foreach ($endpoints as $endpoint) {
                    $timestamp = (string) $this->clock->timestamp();
                    $response = $this->http->request('GET', $endpoint, [
                        'headers'       => [
                            'X-SymPress-Timestamp' => $timestamp,
                            'X-SymPress-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $endpoint, $secret),
                        ],
                        'max_redirects' => 0,
                        'timeout'       => 3,
                        'max_duration'  => 3,
                    ]);
                    $status = $response->getStatusCode();
                    if (($status < 200 || $status >= 300) && $status !== 404) {
                        throw new \RuntimeException('Purge endpoint rejected the request.');
                    }
                }
            }
            return PurgeResult::success('http-purge', 0, $this->clock->elapsedSince($started), $request->mode, $request->reason, $request->source, $request->dryRun, $request->urls, $request->urls, createdAt: $created);
        } catch (\Throwable) {
            return PurgeResult::failure('http-purge', 'HTTP URL purge failed; check the protected Nginx purge location and signing secret.', $this->clock->elapsedSince($started), $request->mode, $request->reason, $request->source, $request->dryRun, createdAt: $created);
        }
    }
}
