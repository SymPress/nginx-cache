<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use SymPress\NginxCache\Value\PurgeScope;
use SymPress\NginxCache\Value\SiteKeyMatcher;

final readonly class RedisPageCachePurger
{
    public function __construct(
        private CompatibilitySettings $settings,
        private RedisPageCacheStore $store,
        private UrlPolicy $urls,
        private CacheKeyStrategy $keys,
        private CacheClock $clock,
        private ?SiteScopeResolver $scope = null,
    ) {
    }

    public function purge(PurgeRequest $request): PurgeResult
    {
        $scope = $this->scope ?? new SiteScopeResolver();
        $matcher = $request->requiresFullPurge() && $request->scope === PurgeScope::Site && $scope->isMultisite() ? $scope->matcher() : null;
        return $this->purgeInScope($request, $matcher)->withScope($request->scope);
    }

    public function purgeSite(PurgeRequest $request, SiteKeyMatcher $matcher): PurgeResult
    {
        if (!$request->requiresFullPurge() || $request->scope !== PurgeScope::Site) {
            return PurgeResult::failure('redis', 'A site scan requires a full site-scoped request.');
        }
        return $this->purgeInScope($request, $matcher)->withScope(PurgeScope::Site);
    }

    private function purgeInScope(PurgeRequest $request, ?SiteKeyMatcher $matcher): PurgeResult
    {
        $started = $this->clock->highResolutionTimestamp();
        $created = $this->clock->timestamp();
        try {
            $prefix = $this->settings->redisPrefix();
            foreach ($request->urls as $url) {
                if ($this->urls->normalizeSameOriginHttpUrl($url) === '') {
                    throw new \RuntimeException('Invalid purge URL.');
                }
            }
            // A dry run never opens a Redis connection, including read commands.
            if ($request->dryRun) {
                return PurgeResult::success('redis', 0, 0.0, $request->mode, $request->reason, $request->source, true, $request->urls, createdAt: $created);
            }
            $removed = 0;
            if ($request->requiresFullPurge()) {
                $batches = 0;
                foreach ($this->scanPrefixes($prefix, $matcher) as $scanPrefix) {
                    $cursor = '0';
                    do {
                        [$cursor, $keys] = $this->store->scan($cursor, $scanPrefix);
                        $keys = array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, $prefix) && ($matcher === null || $this->matchesSite(substr($key, strlen($prefix)), $matcher))));
                        foreach (array_chunk($keys, 200) as $chunk) {
                            $removed += $this->store->delete($chunk);
                        }
                        ++$batches;
                        if ($cursor !== '0' && ($batches >= 1000 || $this->clock->elapsedSince($started) >= 15.0)) {
                            throw new \RuntimeException('Redis scan budget exhausted.');
                        }
                    } while ($cursor !== '0');
                }
            } else {
                foreach ($request->urls as $url) {
                    $removed += $this->store->delete($this->urlKeys($prefix, $url));
                }
            }
            return PurgeResult::success('redis', $removed, $this->clock->elapsedSince($started), $request->mode, $request->reason, $request->source, requestedUrls: $request->urls, purgedUrls: $request->urls, createdAt: $created);
        } catch (\Throwable) {
            return PurgeResult::failure('redis', 'Redis page-cache purge failed; check configuration and server access.', $this->clock->elapsedSince($started), $request->mode, $request->reason, $request->source, $request->dryRun, createdAt: $created);
        }
    }

    /** @return list<string> */
    private function scanPrefixes(string $prefix, ?SiteKeyMatcher $matcher): array
    {
        if ($matcher === null) {
            return [$prefix];
        }
        $prefixes = [];
        foreach ($matcher->roots as $host => $path) {
            foreach (['http', 'https'] as $scheme) {
                foreach (['GET', 'HEAD'] as $method) {
                    $prefixes[] = $prefix . $scheme . '|' . $method . '|' . $host . '|' . $path;
                    $prefixes[] = $prefix . $scheme . $method . $host . $path;
                }
            }
        }
        // Custom key templates may put URI before host. Prefix scan remains safe because every returned key is parsed and matched again.
        return $this->keys->template() === CacheKeyStrategy::TEMPLATE ? array_values(array_unique($prefixes)) : [$prefix];
    }

    private function matchesSite(string $key, SiteKeyMatcher $matcher): bool
    {
        $parsed = $this->keys->parseKey($key);
        if ($parsed === null && preg_match('~^(https?)(GET|HEAD)([a-zA-Z0-9.-]+)(/[^\r\n]*)$~D', $key, $match) === 1) {
            $parsed = ['host' => $match[3], 'uri' => $match[4]];
        }
        return $parsed !== null && $matcher->matches($parsed['host'], $parsed['uri']);
    }

    /** @return list<string> */
    private function urlKeys(string $prefix, string $url): array
    {
        $keys = [];
        foreach ($this->keys->candidates($url) as $candidate) {
            $keys[] = $prefix . $candidate['key'];
            // nginx-srcache / Nginx Helper's conventional $scheme$request_method$host$request_uri key.
            $keys[] = $prefix . $candidate['scheme'] . $candidate['method'] . $candidate['host'] . $candidate['uri'];
        }
        return array_values(array_unique($keys));
    }
}
