<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use SymPress\NginxCache\Value\PurgeScope;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class FullPurgeEndpointDispatcher
{
    public function __construct(
        private HttpClientInterface $http,
        private WordPressCacheSettings $settings,
        private UrlPolicy $urls,
        private CacheClock $clock,
        private ?SiteScopeResolver $scope = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->fullPurgeMode() === 'endpoint';
    }

    public function purge(PurgeRequest $request, float $startedAt, int $createdAt): PurgeResult
    {
        $endpoint = $this->endpoint();
        $scope = $this->scope ?? new SiteScopeResolver();
        if ($scope->isMultisite() && $request->scope === PurgeScope::Site && (!defined('SYMPRESS_NGINX_CACHE_ENDPOINT_SUPPORTS_SITE_SCOPE') || SYMPRESS_NGINX_CACHE_ENDPOINT_SUPPORTS_SITE_SCOPE !== true)) {
            return PurgeResult::failure('endpoint', 'The full-purge endpoint must explicitly support site scope in Multisite.', reason: $request->reason, source: $request->source, dryRun: $request->dryRun, createdAt: $createdAt);
        }

        if ($endpoint === null || $this->settings->remoteSecret() === null) {
            return PurgeResult::failure(
                'endpoint',
                'Full purge endpoint mode is enabled, but a valid endpoint and signing secret are required.',
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

            $signed = $timestamp . '.full-purge';
            if ($scope->isMultisite() || $request->scope === PurgeScope::Network) {
                $headers['X-SymPress-Purge-Scope'] = $request->scope->value;
                $signed .= '.' . $request->scope->value;
                if ($request->scope === PurgeScope::Site) {
                    $matcher = $scope->matcher();
                    if ($matcher->roots === []) {
                        throw new \RuntimeException('No site root is available.');
                    }
                    $host = (string) array_key_first($matcher->roots);
                    $headers['X-SymPress-Purge-Host'] = $host;
                    $headers['X-SymPress-Purge-Path'] = $matcher->roots[$host];
                    // All ownership boundaries are signed, including child sites on the same host.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Signed canonical endpoint protocol metadata.
                    $metadata = (string) json_encode($matcher->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    if (strlen($metadata) > 6144) {
                        throw new \RuntimeException('Site metadata exceeds the endpoint header budget.');
                    }
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Header-safe encoding of signed public site ownership metadata.
                    $headers['X-SymPress-Site-Boundaries'] = base64_encode($metadata);
                    $signed .= '.' . $host . '.' . $matcher->roots[$host] . '.' . $headers['X-SymPress-Site-Boundaries'];
                }
            }
            $headers['X-SymPress-Signature'] = 'sha256=' . hash_hmac('sha256', $signed, $secret);

            $response = $this->http->request($this->settings->fullPurgeHttpMethod(), $endpoint, [
                'headers'       => $headers,
                'max_redirects' => 0,
                'timeout'       => 15,
            ]);
            $statusCode = $response->getStatusCode();
        } catch (\Throwable) {
            return PurgeResult::failure(
                $endpoint,
                sprintf('Full purge endpoint request failed: %s', 'Provider request failed.'),
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

        if ($endpoint === null || $this->settings->remoteSecret() === null) {
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
