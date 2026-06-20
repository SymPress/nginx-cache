<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;

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
        $this->queue->push($request);
        $this->schedule();
    }

    public function process(): void
    {
        foreach ($this->queue->drain() as $request) {
            $this->cache->purgeConfiguredPath($request);
        }
    }

    public function schedule(): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }

        if (wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_single_event($this->clock->timestamp() + $this->settings->debounceSeconds(), self::HOOK);
    }

    public function count(): int
    {
        return $this->queue->count();
    }
}
