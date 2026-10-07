<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class RedisPageCachePurger
{
    public function __construct(
        private CompatibilitySettings $settings,
        private RedisPageCacheStore $store,
        private UrlPolicy $urls,
        private CacheKeyStrategy $keys,
        private CacheClock $clock,
    ) {
    }

    public function purge(PurgeRequest $request): PurgeResult
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
                $cursor = '0';
                $batches = 0;
                do {
                    [$cursor, $keys] = $this->store->scan($cursor, $prefix);
                    $keys = array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, $prefix)));
                    foreach (array_chunk($keys, 200) as $chunk) {
                        $removed += $this->store->delete($chunk);
                    }
                    ++$batches;
                    if ($cursor !== '0' && ($batches >= 1000 || $this->clock->elapsedSince($started) >= 15.0)) {
                        throw new \RuntimeException('Redis scan budget exhausted.');
                    }
                } while ($cursor !== '0');
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
