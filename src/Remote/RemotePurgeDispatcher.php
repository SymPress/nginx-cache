<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Remote;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class RemotePurgeDispatcher
{
    public function __construct(
        private HttpClientInterface $http,
        private WordPressCacheSettings $settings,
        private UrlPolicy $urls,
    ) {
    }

    /** @return list<array{endpoint: string, status: int|null, successful: bool, error: string|null}> */
    public function dispatch(PurgeResult $result, PurgeRequest $request): array
    {
        if (!$result->successful || $result->dryRun) {
            return [];
        }

        $endpoints = array_values(
            array_filter(
                array_map($this->urls->normalizeRemoteEndpoint(...), $this->settings->remoteEndpoints()),
                static fn (string $endpoint): bool => $endpoint !== '',
            ),
        );

        if ($endpoints === []) {
            return [];
        }

        if ($this->settings->remoteSecret() === null) {
            return array_map(
                static fn (string $endpoint): array => [
                    'endpoint'   => $endpoint,
                    'status'     => null,
                    'successful' => false,
                    'error'      => 'Remote purge signing secret is required.',
                ],
                $endpoints,
            );
        }

        $payload = $this->payload($result, $request);

        try {
            $body = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return array_map(
                static fn (string $endpoint): array => [
                    'endpoint'   => $endpoint,
                    'status'     => null,
                    'successful' => false,
                    'error'      => $exception->getMessage(),
                ],
                $endpoints,
            );
        }

        $responses = [];

        foreach ($endpoints as $endpoint) {
            $responses[] = $this->dispatchEndpoint($endpoint, $body);
        }

        if (function_exists('do_action')) {
            do_action('sympress_nginx_cache_remote_purge_dispatched', $responses, $payload);
        }

        return $responses;
    }

    /** @return array<string, mixed> */
    private function payload(PurgeResult $result, PurgeRequest $request): array
    {
        $payload = [
            'event'      => 'sympress_nginx_cache.purge',
            'site'       => [
                'home_url' => function_exists('home_url') ? home_url('/') : null,
                'site_url' => function_exists('site_url') ? site_url('/') : null,
                'blog_id'  => function_exists('get_current_blog_id') ? get_current_blog_id() : null,
            ],
            'request'    => $request->toArray(),
            'result'     => $result->toArray(),
            'created_at' => time(),
        ];

        if (function_exists('apply_filters')) {
            $payload = (array) apply_filters('sympress_nginx_cache_remote_payload', $payload, $result, $request);
        }

        return $payload;
    }

    /** @return array{endpoint: string, status: int|null, successful: bool, error: string|null} */
    private function dispatchEndpoint(string $endpoint, string $body): array
    {
        $timestamp = (string) time();
        $headers = [
            'Content-Type'         => 'application/json',
            'User-Agent'           => 'SymPress Nginx Cache Remote Purge',
            'X-SymPress-Timestamp' => $timestamp,
        ];
        $secret = $this->settings->remoteSecret();

        if ($secret !== null) {
            $headers['X-SymPress-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        try {
            $host = $this->host($endpoint);
            $address = $this->urls->resolvedRemoteAddress($endpoint);

            if ($host === '' || $address === null) {
                return [
                    'endpoint'   => $endpoint,
                    'status'     => null,
                    'successful' => false,
                    'error'      => 'Remote endpoint did not pass network safety validation.',
                ];
            }

            $response = $this->http->request('POST', $endpoint, [
                'headers'       => $headers,
                'body'          => $body,
                'timeout'       => 5,
                'max_redirects' => 0,
                'resolve'       => [
                    $host => $address,
                ],
            ]);
            $status = $response->getStatusCode();

            return [
                'endpoint'   => $endpoint,
                'status'     => $status,
                'successful' => $status >= 200 && $status < 300,
                'error'      => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'endpoint'   => $endpoint,
                'status'     => null,
                'successful' => false,
                'error'      => $exception->getMessage(),
            ];
        }
    }

    private function host(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        return is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
    }
}
