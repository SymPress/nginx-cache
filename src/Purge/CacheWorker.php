<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Support\MutationLockUnavailable;
use SymPress\NginxCache\Time\CacheClock;

final readonly class CacheWorker
{
    public const string HEARTBEAT = 'sympress_nginx_cache_worker_heartbeat';

    public function __construct(private PurgeQueueProcessor $purges, private PurgeSideEffectProcessor $effects, private NetworkPendingRepository $pending, private CacheClock $clock)
    {
    }

    /** @return array{tasks: int, runtime_seconds: float, exhausted: int, sites: array<int, array{tasks: int, pending: int, exhausted: int}>} */
    public function run(float $maxRuntime = 50.0, int $maxTasks = 500, int $sleepMs = 0, bool $once = false, bool $network = false): array
    {
        $started = $this->clock->highResolutionTimestamp();
        $tasks = 0;
        $sites = [];
        $multisite = function_exists('is_multisite') && is_multisite();
        $network = $network && $multisite;
        $maxRuntime = max(0.01, min(3600.0, $maxRuntime));
        $maxTasks = max(1, min(100000, $maxTasks));
        $sleepMs = max(0, min(60000, $sleepMs));
        if ($network) {
            update_site_option(self::HEARTBEAT, $this->clock->timestamp());
        }
        do {
            $progress = 0;
            $nextAttempts = [];
            $ids = $network ? $this->pending->all() : [(function_exists('get_current_blog_id') ? get_current_blog_id() : 1) => ''];
            foreach ($ids as $id => $generation) {
                if ($tasks >= $maxTasks || $this->clock->elapsedSince($started) >= $maxRuntime) {
                    break;
                }
                if ($network && get_site($id) === null) {
                    $this->pending->clear($id, $generation, static fn (): bool => true);
                    continue;
                }
                if ($network) {
                    switch_to_blog($id);
                }
                try {
                    update_option(self::HEARTBEAT, $this->clock->timestamp(), false);
                    $done = $this->purges->process(limit: 1);
                    if ($tasks + $done < $maxTasks && $this->clock->elapsedSince($started) < $maxRuntime) {
                        $done += $this->effects->process();
                    }
                    $tasks += $done;
                    $progress += $done;
                    $exhausted = count(array_filter([...$this->purges->inspect(), ...$this->effects->inspect()], static fn (array $task): bool => ($task['exhausted'] ?? false) === true));
                    $sites[$id] = ['tasks' => ($sites[$id]['tasks'] ?? 0) + $done, 'pending' => $this->purges->count() + $this->effects->count(), 'exhausted' => $exhausted];
                    foreach ([$this->purges->nextAttemptAt(), $this->effects->nextAttemptAt()] as $next) {
                        if ($next === null) {
                            continue;
                        }
                        $nextAttempts[] = $next;
                    }
                    if ($network) {
                        $this->pending->clear($id, $generation, fn (): bool => $this->purges->count() + $this->effects->count() === 0);
                    }
                } catch (MutationLockUnavailable) {
                    // Another worker owns this site or network snapshot.
                } finally {
                    if ($network) {
                        restore_current_blog();
                    }
                }
            }
            if ($once || $tasks >= $maxTasks || $this->clock->elapsedSince($started) >= $maxRuntime) {
                break;
            }
            if ($progress === 0) {
                $delay = $nextAttempts === [] ? 0 : min($nextAttempts) - $this->clock->timestamp();
                if ($delay <= 0 || $this->clock->elapsedSince($started) + $delay >= $maxRuntime) {
                    break;
                }
                $this->clock->sleepMicroseconds($delay * 1000000);
            }
            $remaining = max(0.0, $maxRuntime - $this->clock->elapsedSince($started));
            $this->clock->sleepMicroseconds((int) min($sleepMs * 1000, $remaining * 1000000));
        } while (true);
        return ['tasks' => $tasks, 'runtime_seconds' => $this->clock->elapsedSince($started), 'exhausted' => array_sum(array_column($sites, 'exhausted')), 'sites' => $sites];
    }
}
