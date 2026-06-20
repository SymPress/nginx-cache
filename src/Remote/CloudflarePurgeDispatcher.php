<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Remote;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CloudflarePurgeDispatcher
{
    private const int MAX_TAGS = 500;
    private const int MAX_FILES = 30;

    public function __construct(
        private HttpClientInterface $http,
        private WordPressCacheSettings $settings,
        private CacheTagResolver $tags,
        private UrlPolicy $urls,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->cloudflareConfigured();
    }

    /** @return list<array{endpoint: string, status: int|null, successful: bool, error: string|null}> */
    public function dispatch(PurgeResult $result, PurgeRequest $request): array
    {
        if (!$this->settings->cloudflareEnabled() || !$result->successful || $result->dryRun) {
            return [];
        }

        $zoneId = $this->settings->cloudflareZoneId();
        $token = $this->settings->cloudflareApiToken();

        if ($zoneId === null || $token === null) {
            return [
                [
                    'endpoint'   => 'https://api.cloudflare.com/client/v4/zones/:zone/purge_cache',
                    'status'     => null,
                    'successful' => false,
                    'error'      => 'Cloudflare zone ID and API token are required.',
                ],
            ];
        }

        $endpoint = sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', rawurlencode($zoneId));
        $payload = $this->payload($result, $request);

        if ($payload === []) {
            return [];
        }

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Symfony HttpClient expects an encoded API payload here.
            $body = (string) json_encode($payload, JSON_THROW_ON_ERROR);
            $response = $this->http->request('POST', $endpoint, [
                'headers'       => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'SymPress Nginx Cache Cloudflare Purge',
                ],
                'body'          => $body,
                'timeout'       => 10,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
        } catch (\Throwable $exception) {
            return [
                [
                    'endpoint'   => $endpoint,
                    'status'     => null,
                    'successful' => false,
                    'error'      => $exception->getMessage(),
                ],
            ];
        }

        $responses = [
            [
                'endpoint'   => $endpoint,
                'status'     => $status,
                'successful' => $status >= 200 && $status < 300,
                'error'      => null,
            ],
        ];

        if (function_exists('do_action')) {
            do_action('sympress_nginx_cache_cloudflare_purge_dispatched', $responses, $payload);
        }

        return $responses;
    }

    /** @return array<string, mixed> */
    private function payload(PurgeResult $result, PurgeRequest $request): array
    {
        if ($request->requiresFullPurge()) {
            $payload = ['purge_everything' => true];
        } else {
            $tags = array_values(
                array_slice(
                    array_filter(
                        $this->tags->normalize($request->tags),
                        static fn (string $tag): bool => strlen($tag) <= 1024,
                    ),
                    0,
                    self::MAX_TAGS,
                ),
            );

            $payload = $tags !== [] ? ['tags' => $tags] : $this->filePayload($result, $request);
        }

        if (function_exists('apply_filters')) {
            $payload = (array) apply_filters('sympress_nginx_cache_cloudflare_payload', $payload, $result, $request);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function filePayload(PurgeResult $result, PurgeRequest $request): array
    {
        $urls = $result->requestedUrls !== [] ? $result->requestedUrls : $request->urls;
        $urls = array_values(
            array_slice(
                array_unique(
                    array_filter(
                        array_map($this->urls->normalizeSameOriginHttpUrl(...), $urls),
                        static fn (string $url): bool => $url !== '',
                    ),
                ),
                0,
                self::MAX_FILES,
            ),
        );

        return $urls === [] ? [] : ['files' => $urls];
    }
}
