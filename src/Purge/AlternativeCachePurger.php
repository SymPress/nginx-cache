<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class AlternativeCachePurger
{
    public function __construct(
        private CompatibilitySettings $settings,
        private RedisPageCachePurger $redis,
        private HttpUrlCachePurger $http,
        private FullPurgeEndpointDispatcher $full,
        private CacheClock $clock,
    ) {
    }

    public function purge(PurgeRequest $request): ?PurgeResult
    {
        $backend = $this->settings->string('purge_backend');
        if ($backend === 'local_files') {
            return null;
        }
        if ($backend === 'redis') {
            return $this->redis->purge($request);
        }
        if ($backend === 'http' && !$request->requiresFullPurge()) {
            return $this->http->purge($request);
        }
        if ($backend === 'http' && $this->full->enabled()) {
            return $this->full->purge($request, $this->clock->highResolutionTimestamp(), $this->clock->timestamp());
        }
        return PurgeResult::failure($backend, 'Selected purge backend requires a valid full-purge endpoint.', mode: $request->mode, reason: $request->reason, source: $request->source, dryRun: $request->dryRun, createdAt: $this->clock->timestamp());
    }
}
