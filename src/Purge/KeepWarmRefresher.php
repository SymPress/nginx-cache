<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Settings\CachePolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Support\MutationLockUnavailable;
use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Time\CacheClock;

/**
 * Re-requests prewarm targets inside the Nginx inactivity window. Each cache
 * lookup renews the entry's inactivity timer; stale entries refresh through the
 * generated background update, so retention never extends freshness.
 */
final readonly class KeepWarmRefresher
{
    public const string HOOK = 'sympress_nginx_cache_keep_warm';
    public const string STATE = 'sympress_nginx_cache_keep_warm_state';
    private const int MAX_ERRORS = 5;

    public function __construct(
        private WordPressCacheSettings $settings,
        private Prewarmer $prewarmer,
        private CacheClock $clock,
        private OptionMutex $mutex,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->prewarmEnabled() && CachePolicy::retention() === CachePolicy::KEEP_WARM;
    }

    public function schedule(): void
    {
        if (!$this->enabled() || !function_exists('wp_next_scheduled') || wp_next_scheduled(self::HOOK) !== false) {
            return;
        }
        $state = $this->state();
        $this->scheduleAt(is_array($state['urls']) ? $this->clock->timestamp() + 1 : ($state['started_at'] ?? 0) + CachePolicy::keepWarmInterval());
    }

    public function process(): int
    {
        if (!$this->enabled()) {
            delete_option(self::STATE);
            return 0;
        }
        try {
            return $this->mutex->synchronized(self::STATE, $this->refresh(...));
        } catch (MutationLockUnavailable) {
            // Another runner owns this cycle.
            return 0;
        } catch (\Throwable) {
            try {
                update_option(self::STATE, [...$this->state(), 'last_failure' => ['failed_at' => $this->clock->timestamp(), 'failed' => 0, 'targets' => 0, 'errors' => ['Keep-warm refresh could not run.']]], false);
            } catch (\Throwable) {
                // The log signal below remains when diagnostic storage is unavailable.
            }
            $this->signal('worker error');
            $this->scheduleAt($this->clock->timestamp() + 60);
            return 0;
        }
    }

    /** @return array{mode: string, enabled: bool, interval: int, next_run: int|false, pending: int, started_at: int|null, completed_at: int|null, last_failure: array<string, mixed>|null, overdue: bool} */
    public function inspect(): array
    {
        $state = $this->state();
        $enabled = $this->enabled();
        return [
            'mode'         => CachePolicy::retention(),
            'enabled'      => $enabled,
            'interval'     => CachePolicy::keepWarmInterval(),
            'next_run'     => function_exists('wp_next_scheduled') ? wp_next_scheduled(self::HOOK) : false,
            'pending'      => is_array($state['urls']) ? count($state['urls']) - count($state['done']) : 0,
            'started_at'   => $state['started_at'],
            'completed_at' => $state['completed_at'],
            'last_failure' => $state['last_failure'],
            // Targets may already be cold once a full inactivity window passed without a completed cycle.
            'overdue'      => $enabled && $state['completed_at'] !== null && $state['completed_at'] < $this->clock->timestamp() - CachePolicy::values()['inactive_seconds'],
        ];
    }

    private function refresh(): int
    {
        $state = $this->state();
        $now = $this->clock->timestamp();
        if (!is_array($state['urls'])) {
            // Discovery errors are reported while the targets that did resolve stay warm.
            $plan = $this->prewarmer->plan();
            $state = [...$state, 'urls' => $plan->urls, 'done' => [], 'errors' => array_slice($plan->errors, 0, self::MAX_ERRORS), 'failed' => 0, 'started_at' => $now];
        }
        $pending = array_values(array_filter($state['urls'], static fn (string $url): bool => !in_array(hash('sha256', $url), $state['done'], true)));
        $batch = array_slice($pending, 0, Prewarmer::BATCH_SIZE);
        $result = $this->prewarmer->warmUrls($batch);
        // Failed targets are reported, not retried within the cycle, so one bad URL cannot stall the others.
        $state['done'] = [...$state['done'], ...array_map(static fn (string $url): string => hash('sha256', $url), $batch)];
        $state['errors'] = array_slice([...$state['errors'], ...$result->errors], 0, self::MAX_ERRORS);
        $state['failed'] += $result->failed();
        if (count($pending) > count($batch)) {
            update_option(self::STATE, $state, false);
            $this->scheduleAt($now + 1);
            return $result->attempted();
        }
        $failure = $state['errors'] !== [] ? ['failed_at' => $now, 'failed' => $state['failed'], 'targets' => count($state['urls']), 'errors' => $state['errors']] : null;
        update_option(self::STATE, ['urls' => null, 'started_at' => $state['started_at'], 'completed_at' => $now, 'last_failure' => $failure], false);
        if ($failure !== null) {
            $this->signal(sprintf('%d of %d targets', $failure['failed'], $failure['targets']));
        }
        $this->scheduleAt($state['started_at'] + CachePolicy::keepWarmInterval());
        return $result->attempted();
    }

    private function signal(string $detail): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed credential-free operational signal.
        error_log('SymPress Nginx Cache: keep-warm refresh failed (' . $detail . '); see diagnostics.');
    }

    private function scheduleAt(int $timestamp): void
    {
        if (!function_exists('wp_schedule_single_event') || wp_next_scheduled(self::HOOK) !== false) {
            return;
        }
        wp_schedule_single_event(max($timestamp, $this->clock->timestamp() + 1), self::HOOK);
    }

    /** @return array{urls: list<string>|null, done: list<string>, errors: list<string>, failed: int, started_at: int|null, completed_at: int|null, last_failure: array<string, mixed>|null} */
    private function state(): array
    {
        $state = get_option(self::STATE, []);
        $state = is_array($state) ? $state : [];
        $urls = $state['urls'] ?? null;
        return [
            'urls'         => is_array($urls) ? array_values(array_filter($urls, is_string(...))) : null,
            'done'         => array_values(array_filter((array) ($state['done'] ?? []), is_string(...))),
            'errors'       => array_values(array_filter((array) ($state['errors'] ?? []), is_string(...))),
            'failed'       => (int) ($state['failed'] ?? 0),
            'started_at'   => isset($state['started_at']) ? (int) $state['started_at'] : null,
            'completed_at' => isset($state['completed_at']) ? (int) $state['completed_at'] : null,
            'last_failure' => is_array($state['last_failure'] ?? null) ? $state['last_failure'] : null,
        ];
    }
}
