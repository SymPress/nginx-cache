<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class PurgeQueueProcessor
{
    public const string HOOK = 'sympress_nginx_cache_process_queue';

    public function __construct(
        private WordPressCacheSettings $settings,
        private PurgeQueueRepository $queue,
        private CacheManager $cache,
        private CacheClock $clock,
    ) {
    }

    public function enqueue(PurgeRequest $request): void
    {
        if ($request->dryRun) {
            return;
        }
        $this->queue->push($request);
        $this->schedule();
    }

    public function process(int $limit = PHP_INT_MAX): int
    {
        $attempted = 0;
        try {
            $successful = $this->queue->process(
                function (PurgeRequest $request) use (&$attempted): PurgeResult {
                    ++$attempted;
                    return $this->cache->purgeConfiguredPath($request);
                },
                $limit,
            );
        } catch (\Throwable $error) {
            // Without the process lock, a contender must not replace the owner's evidence.
            $this->queue->recordFailure('queue', $error, persist: false);
            $successful = false;
        }

        if ($successful) {
            $this->schedule();
            return $attempted;
        }

        $this->schedule(60);
        return $attempted;
    }

    public function schedule(int $minimumDelay = 0): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }

        $next = $this->queue->nextAttemptAt();
        if ($next === null || wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_single_event(max($next, $this->clock->timestamp() + max($minimumDelay, $this->settings->debounceSeconds())), self::HOOK);
    }

    public function count(): int
    {
        return $this->queue->count();
    }

    public function nextAttemptAt(): ?int
    {
        return $this->queue->nextAttemptAt();
    }

    /** @return list<array<string, mixed>> */
    public function inspect(): array
    {
        return $this->queue->inspect();
    }

    /** @return array<string, mixed>|null */
    public function lastFailure(): ?array
    {
        return $this->queue->lastFailure();
    }

    public function retry(): void
    {
        $this->queue->retry();
        $this->schedule();
    }
}
