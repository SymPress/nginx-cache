<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Remote\CloudflarePurgeDispatcher;
use SymPress\NginxCache\Remote\RemotePurgeDispatcher;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Support\MutationLockUnavailable;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use SymPress\NginxCache\Value\SideEffectOutcome;

/** @phpstan-import-type QueueTask from PurgeSideEffectQueueRepository */
final readonly class PurgeSideEffectProcessor
{
    public const string HOOK = 'sympress_nginx_cache_process_side_effects';
    public const string HEALTH_OPTION = 'sympress_nginx_cache_side_effect_health';

    public function __construct(
        private WordPressCacheSettings $settings,
        private PurgeSideEffectQueueRepository $queue,
        private Prewarmer $prewarmer,
        private CacheLayerCoordinator $layers,
        private RemotePurgeDispatcher $remote,
        private CloudflarePurgeDispatcher $cloudflare,
        private CacheClock $clock,
        private ?CachePurger $purger = null,
        private ?SiteScopeResolver $scope = null,
        private ?TagIndexRepository $tagIndex = null,
        private ?PurgeHistoryRepository $history = null,
        private ?PurgeEventEmitter $events = null,
    ) {
    }

    /** @return list<string> */
    public function enqueue(PurgeResult $result, PurgeRequest $request): array
    {
        $tasks = $this->plannedTasks($result, $request);

        if ($tasks === []) {
            return [];
        }

        try {
            if ($this->attentionReason() === 'storage-error') {
                // A full invalidation covers work that could not previously be enqueued.
                $request = PurgeRequest::full('side-effect-recovery', 'queue', prewarm: $request->prewarm, scope: $request->scope);
                $result = PurgeResult::success($result->path, 0, 0.0, reason: 'side-effect-recovery', source: 'queue')->withScope($request->scope)->withScan($result->partial, $result->unmatched, $result->cursor);
            }
            if ($this->queue->push($result, $request)) {
                $this->signal('coalesced');
            } elseif ($request->reason === 'side-effect-recovery') {
                $this->signal('recovering');
            }
        } catch (\Throwable) {
            // A successful local purge must not become a failed local retry because
            // an optional external queue cannot accept follow-up work.
            $this->signal('storage-error');
            return [];
        }
        $this->schedule();

        return $tasks;
    }

    public function process(): int
    {
        $attempted = 0;
        try {
            $this->queue->process(function (array $task) use (&$attempted): bool|SideEffectOutcome {
                ++$attempted;
                return $this->executeTask($task);
            }, limit: 1);
        } catch (MutationLockUnavailable) {
            // Another connection owns normal queue work; retain its scope and retry.
            $this->schedule(60);
            return $attempted;
        } catch (\Throwable) {
            // Leave the stored task for retry, without logging provider credentials.
            $this->signal('storage-error');
        }
        if ($this->queue->count() <= 0) {
            if ($this->attentionReason() !== 'storage-error' && function_exists('delete_option')) {
                delete_option(self::HEALTH_OPTION);
            }
            return $attempted;
        }

        foreach ($this->queue->inspect() as $task) {
            if ($task['exhausted'] === true) {
                $this->signal('exhausted');
                break;
            }
        }

        $this->schedule();
        return $attempted;
    }

    public function schedule(int $minimumDelay = 1): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }

        $next = $this->queue->nextAttemptAt();
        if ($next === null || wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_single_event(max($next, $this->clock->timestamp() + $minimumDelay), self::HOOK);
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

    public function retry(): void
    {
        if ($this->attentionReason() === 'storage-error') {
            $request = PurgeRequest::full('side-effect-recovery', 'queue');
            $this->queue->push(PurgeResult::success($this->settings->cachePath(), 0, 0.0), $request);
            $this->signal('recovering');
        }
        $this->queue->retry();
        $this->schedule();
    }

    public function attentionReason(): string
    {
        $reason = function_exists('get_option') ? get_option(self::HEALTH_OPTION, '') : '';
        return is_string($reason) ? $reason : '';
    }

    private function signal(string $reason): void
    {
        if (function_exists('get_option') && get_option(self::HEALTH_OPTION) === $reason) {
            return;
        }
        if (function_exists('update_option')) {
            update_option(self::HEALTH_OPTION, $reason, false);
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed credential-free operational signal, deduplicated in persistent state.
        error_log('SymPress Nginx Cache: external follow-up queue requires attention (' . $reason . '); local purges remain active.');
    }

    /** @param QueueTask $task */
    private function executeTask(array $task): bool|SideEffectOutcome
    {
        $result = PurgeResult::fromArray($task['result']);
        $request = PurgeRequest::fromArray($task['request']);
        if ($result->dryRun || !$result->successful) {
            return true;
        }
        if ($result->partial) {
            $snapshots = $this->completed($task, 'site-scan:');
            if ($snapshots !== []) {
                $snapshot = json_decode($snapshots[array_key_last($snapshots)], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($snapshot)) {
                    return false;
                }
                $result = PurgeResult::fromArray($snapshot);
            }
            if ($result->partial) {
                if ($this->purger === null || $this->scope === null) {
                    return false;
                }
                $next = $this->purger->purgeSite($result->path, $request, $this->scope->matcher(), $result->cursor);
                if (!$next->successful) {
                    return false;
                }
                $result = $next->withScan($next->partial, $result->unmatched + $next->unmatched, $next->cursor, $result->removedEntries + $next->removedEntries);
                // Keep one durable scan snapshot; successful chunks do not spend the retry budget.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Internal bounded queue checkpoint.
                $this->queue->checkpoint($task['id'], 'site-scan:' . json_encode($result->toArray(), JSON_THROW_ON_ERROR));
                if ($result->partial) {
                    return SideEffectOutcome::Continue;
                }
            }
            if (!in_array('site-scan-finalized', $task['completed'], true)) {
                if ($this->settings->tagIndexEnabled()) {
                    $this->tagIndex?->clear();
                }
                $this->history?->record($result);
                $this->events?->emit($result);
                $this->queue->checkpoint($task['id'], 'site-scan-finalized');
            }
        }
        $sideEffects = [];
        $prewarmPending = false;
        if ($this->shouldPrewarm($result, $request) && !in_array('prewarm', $task['completed'], true)) {
            $plans = $this->completed($task, 'prewarm-plan:');
            if ($plans === []) {
                $plan = $this->prewarmer->plan($result->requestedUrls);
                if ($plan->errors !== []) {
                    return false;
                }
                // Snapshot discovery once; later batches retain the same targets.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Private queue payload, not an HTTP response.
                $this->queue->checkpoint($task['id'], 'prewarm-plan:' . json_encode($plan->urls, JSON_THROW_ON_ERROR));
                $urls = $plan->urls;
            } else {
                $urls = json_decode($plans[0], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($urls) || count($urls) > 200 || count(array_filter($urls, is_string(...))) !== count($urls)) {
                    return false;
                }
                $urls = array_values($urls);
            }
            $completed = $this->completed($task, 'prewarm-url:');
            $pending = array_values(array_filter($urls, static fn (string $url): bool => !in_array(hash('sha256', $url), $completed, true)));
            $prewarm = $this->prewarmer->warmUrls(array_slice($pending, 0, Prewarmer::BATCH_SIZE));
            foreach (array_keys($prewarm->responses) as $url) {
                $this->queue->checkpoint($task['id'], 'prewarm-url:' . hash('sha256', $url));
            }
            $prewarmPending = count($pending) > $prewarm->successful();
            $sideEffects['prewarm'] = [
                'attempted' => $prewarm->attempted(),
            'successful'    => $prewarm->successful(),
            'failed'        => $prewarm->failed(),
            ];
            if (!$prewarmPending && $prewarm->failed() === 0) {
                $this->queue->checkpoint($task['id'], 'prewarm');
            }
        }
        $sideEffects['layers'] = $this->layers->sync(
            $result,
            $this->completed($task, 'layer:'),
            fn (string $layer) => $this->queue->checkpoint($task['id'], 'layer:' . $layer),
        );
        $sideEffects['remote'] = $this->remote->dispatch(
            $result,
            $request,
            $this->completed($task, 'remote:'),
            fn (string $endpoint) => $this->queue->checkpoint($task['id'], 'remote:' . $endpoint),
        );
        if (!in_array('cloudflare', $task['completed'], true)) {
            $sideEffects['cloudflare'] = $this->cloudflare->dispatch($result, $request);
            if ($this->successfulResponses($sideEffects['cloudflare'])) {
                $this->queue->checkpoint($task['id'], 'cloudflare');
            }
        }
        if (function_exists('do_action')) {
            do_action('sympress_nginx_cache_side_effects_processed', $result, $request, $sideEffects);
        }
        $successful = ($sideEffects['prewarm']['failed'] ?? 0) === 0
            && $this->successfulResponses($sideEffects['layers'])
            && $this->successfulResponses($sideEffects['remote'])
            && $this->successfulResponses($sideEffects['cloudflare'] ?? []);
        return $successful && $prewarmPending ? SideEffectOutcome::Continue : $successful;
    }

    /**
     * @param QueueTask $task
     * @return list<string>
     */
    private function completed(array $task, string $prefix): array
    {
        return array_values(array_map(
            static fn (string $value): string => substr($value, strlen($prefix)),
            array_filter($task['completed'], static fn (string $value): bool => str_starts_with($value, $prefix)),
        ));
    }

    /** @param list<array<string, mixed>> $responses */
    private function successfulResponses(array $responses): bool
    {
        foreach ($responses as $response) {
            if (($response['successful'] ?? $response['flushed'] ?? true) === false) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private function plannedTasks(PurgeResult $result, PurgeRequest $request): array
    {
        if (!$result->successful || $result->dryRun) {
            return [];
        }

        $tasks = [];

        if ($result->partial) {
            $tasks[] = 'site-scan';
        }

        if ($this->shouldPrewarm($result, $request)) {
            $tasks[] = 'prewarm';
        }

        if ($this->settings->layerSyncEnabled()) {
            $tasks[] = 'layers';
        }

        if ($this->settings->remoteEndpoints() !== []) {
            $tasks[] = 'remote';
        }

        if ($this->settings->cloudflareEnabled()) {
            $tasks[] = 'cloudflare';
        }

        return $tasks;
    }

    private function shouldPrewarm(PurgeResult $result, PurgeRequest $request): bool
    {
        return $result->successful
            && !$result->dryRun
            && ($request->prewarm || $this->settings->prewarmEnabled());
    }
}
