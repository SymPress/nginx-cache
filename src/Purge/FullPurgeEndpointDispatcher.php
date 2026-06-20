<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class FullPurgeEndpointDispatcher
{
    public function __construct(
        private HttpClientInterface $http,
        private WordPressCacheSettings $settings,
        private UrlPolicy $urls,
        private CacheClock $clock,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->fullPurgeMode() === 'endpoint';
    }

    public function purge(PurgeRequest $request, float $startedAt, int $createdAt): PurgeResult
    {
        $endpoint = $this->endpoint();

        if ($endpoint === null) {
            return PurgeResult::failure(
                'endpoint',
                'Full purge endpoint mode is enabled, but no valid endpoint is configured.',
                $this->clock->elapsedSince($startedAt),
                PurgeMode::Full,
                $request->reason,
                $request->source,
                $request->dryRun,
                createdAt: $createdAt,
            );
        }

        if ($request->dryRun) {
            return PurgeResult::success(
                $endpoint,
                0,
                $this->clock->elapsedSince($startedAt),
                PurgeMode::Full,
                $request->reason,
                $request->source,
                true,
                createdAt: $createdAt,
            );
        }

        try {
            $timestamp = (string) $this->clock->timestamp();
            $headers = [
                'Accept'               => 'application/json, text/plain;q=0.8, */*;q=0.1',
                'User-Agent'           => 'SymPress Nginx Cache Full Purger',
                'X-SymPress-Timestamp' => $timestamp,
            ];
            $secret = $this->settings->remoteSecret();

            if ($secret !== null) {
                $headers['X-SymPress-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.full-purge', $secret);
            }

            $response = $this->http->request($this->settings->fullPurgeHttpMethod(), $endpoint, [
                'headers'       => $headers,
                'max_redirects' => 0,
                'timeout'       => 15,
            ]);
            $statusCode = $response->getStatusCode();
        } catch (\Throwable $exception) {
            return PurgeResult::failure(
                $endpoint,
                sprintf('Full purge endpoint request failed: %s', $exception->getMessage()),
                $this->clock->elapsedSince($startedAt),
                PurgeMode::Full,
                $request->reason,
                $request->source,
                false,
                createdAt: $createdAt,
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return PurgeResult::failure(
                $endpoint,
                sprintf('Full purge endpoint returned HTTP %d.', $statusCode),
                $this->clock->elapsedSince($startedAt),
                PurgeMode::Full,
                $request->reason,
                $request->source,
                false,
                createdAt: $createdAt,
            );
        }

        return PurgeResult::success(
            $endpoint,
            0,
            $this->clock->elapsedSince($startedAt),
            PurgeMode::Full,
            $request->reason,
            $request->source,
            false,
            createdAt: $createdAt,
        );
    }

    private function endpoint(): ?string
    {
        $endpoint = $this->settings->fullPurgeEndpoint();

        if ($endpoint === null) {
            return null;
        }

        $sameOrigin = $this->urls->normalizeSameOriginHttpUrl($endpoint);

        if ($sameOrigin !== '') {
            return $sameOrigin;
        }

        $remote = $this->urls->normalizeRemoteEndpoint($endpoint);

        return $remote !== '' ? $remote : null;
    }
}
