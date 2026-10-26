<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Remote\CloudflarePurgeDispatcher;
use SymPress\NginxCache\Remote\RemotePurgeDispatcher;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Support\MutationLockUnavailable;
use SymPress\NginxCache\Surrogate\TagIndexMaintenance;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use SymPress\NginxCache\Value\PurgeScope;
use SymPress\NginxCache\Value\SideEffectOutcome;

/** @phpstan-import-type QueueTask from PurgeSideEffectQueueRepository */
final readonly class PurgeSideEffectProcessor
{
    public const string HOOK = 'sympress_nginx_cache_process_side_effects';
    public const string HEALTH_OPTION = 'sympress_nginx_cache_side_effect_health';
    public const string SCAN_RECOVERY_PREFIX = 'sympress_nginx_cache_scan_recovery_';
    public const string PREWARM_ISSUES_OPTION = 'sympress_nginx_cache_prewarm_issues';
    private const int MAX_PREWARM_ISSUES = 20;

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
        private ?TagIndexMaintenance $maintenance = null,
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
            $scanRecovery = (bool) get_option(self::SCAN_RECOVERY_PREFIX . $request->scope->value, false);
            if ($this->attentionReason() === 'storage-error') {
                // A full invalidation covers work that could not previously be enqueued.
                $request = PurgeRequest::full('side-effect-recovery', 'queue', prewarm: $request->prewarm, scope: $request->scope);
                $result = PurgeResult::success($result->path, 0, 0.0, reason: 'side-effect-recovery', source: 'queue')->withScope($request->scope)->withScan($result->partial || $scanRecovery, $result->unmatched, $scanRecovery ? '' : $result->cursor);
            }
            if ($this->queue->push($result, $request)) {
                $this->signal('coalesced');
            } elseif ($request->reason === 'side-effect-recovery') {
                $this->signal('recovering');
            }
            if ($scanRecovery) {
                delete_option(self::SCAN_RECOVERY_PREFIX . $request->scope->value);
            }
        } catch (\Throwable) {
            // A successful local purge must not become a failed local retry because
            // an optional external queue cannot accept follow-up work.
            if ($result->partial) {
                update_option(self::SCAN_RECOVERY_PREFIX . $request->scope->value, ['prewarm' => $request->prewarm], false);
            }
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
            $this->maintenance?->prune();
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
        $issues = self::prewarmIssues();
        $discovery = SitemapUrlProvider::lastFailure();
        return array_map(static fn (array $task): array => [...$task, 'prewarm_issues' => $issues, 'sitemap_discovery_failure' => $discovery], $this->queue->inspect());
    }

    /** @return list<array{url: string, reason: string, stale: bool, at: int}> */
    public static function prewarmIssues(): array
    {
        $issues = function_exists('get_option') ? get_option(self::PREWARM_ISSUES_OPTION, []) : [];
        return is_array($issues) ? array_values(array_filter($issues, static fn (mixed $issue): bool => is_array($issue) && is_string($issue['url'] ?? null) && is_string($issue['reason'] ?? null) && is_bool($issue['stale'] ?? null) && is_int($issue['at'] ?? null))) : [];
    }

    public function retry(): void
    {
        $scanRecovered = false;
        foreach (PurgeScope::cases() as $scope) {
            $recovery = get_option(self::SCAN_RECOVERY_PREFIX . $scope->value, false);
            if (!is_array($recovery)) {
                continue;
            }
            $request = PurgeRequest::full('scan-recovery', 'queue', prewarm: !empty($recovery['prewarm']), scope: $scope);
            $result = PurgeResult::success($this->settings->cachePath(), 0, 0.0)->withScope($scope)->withScan(true, 0, '');
            $this->queue->push($result, $request);
            delete_option(self::SCAN_RECOVERY_PREFIX . $scope->value);
            $scanRecovered = true;
        }
        if ($scanRecovered) {
            $this->signal('recovering');
        } elseif ($this->attentionReason() === 'storage-error') {
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

    /**
     * @param list<string> $batch
     * @param array<string, array{reason: string, stale: bool}> $issues
     */
    private function recordPrewarmIssues(array $batch, array $issues): void
    {
        if (!function_exists('update_option')) {
            return;
        }
        $now = $this->clock->timestamp();
        $recorded = array_map(static fn (string $url, array $issue): array => ['url' => $url, ...$issue, 'at' => $now], array_keys($issues), $issues);
        // Attempted URLs replace their older entries, so a later success clears a transient issue.
        $kept = array_filter(self::prewarmIssues(), static fn (array $issue): bool => !in_array($issue['url'], $batch, true));
        $current = array_slice([...$recorded, ...$kept], 0, self::MAX_PREWARM_ISSUES);
        try {
            update_option(self::PREWARM_ISSUES_OPTION, $current, false);
        } catch (\Throwable) {
            // Diagnostics must never undo checkpointed prewarm progress.
        }
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
                $next = $request->scope === PurgeScope::Network
                    ? $this->purger->purgeRequest($result->path, $request)
                    : $this->purger->purgeSite($result->path, $request, $this->scope->matcher(), $result->cursor);
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
        $discoveryIncomplete = false;
        if ($this->shouldPrewarm($result, $request) && !in_array('prewarm', $task['completed'], true)) {
            $plans = $this->completed($task, 'prewarm-plan:');
            // Without a complete plan, targets already known while discovery failed are warmed first.
            $discoveryIncomplete = $plans === [];
            $urls = $this->snapshotTargets($plans !== [] ? [$plans[0]] : $this->completed($task, 'prewarm-known:'));
            if ($urls === null) {
                return false;
            }
            $completed = $this->completed($task, 'prewarm-url:');
            $pendingOf = static fn (array $urls): array => array_values(array_filter($urls, static fn (string $url): bool => !in_array(hash('sha256', $url), $completed, true)));
            $pending = $pendingOf($urls);
            $discoveries = $this->completed($task, 'prewarm-discovery:');
            // Successful batches keep the reserved attempt count, so a change means an invocation failed since.
            $discoveredAt = $discoveries === [] ? null : (int) $discoveries[array_key_last($discoveries)];
            if ($discoveryIncomplete && ($pending === [] || $discoveredAt !== $task['retry']['attempts'])) {
                // First run, or a retry: discover again without waiting for failing known targets.
                $plan = $this->prewarmer->plan($result->requestedUrls, withRelated: true, affectedUrls: $request->affectedUrls);
                $discoveryIncomplete = $plan->errors !== [];
                if (!$discoveryIncomplete) {
                    // Snapshot discovery once; later batches retain the same targets.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Private queue payload, not an HTTP response.
                    $this->queue->checkpoint($task['id'], 'prewarm-plan:' . json_encode($plan->urls, JSON_THROW_ON_ERROR));
                    $urls = $plan->urls;
                } else {
                    $this->queue->checkpoint($task['id'], sprintf('prewarm-discovery:%d@%d', $task['retry']['attempts'], $this->clock->timestamp()));
                    $known = array_slice(array_values(array_diff($plan->urls, $urls)), 0, max(0, $this->settings->maxPrewarmUrls() - count($urls)));
                    if ($known !== []) {
                        // Later batches reuse known targets instead of repeating failed discovery.
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Private queue payload, not an HTTP response.
                        $this->queue->checkpoint($task['id'], 'prewarm-known:' . json_encode($known, JSON_THROW_ON_ERROR));
                        $urls = [...$urls, ...$known];
                    }
                }
                $pending = $pendingOf($urls);
            }
            $batch = array_slice($pending, 0, Prewarmer::BATCH_SIZE);
            $prewarm = $this->prewarmer->warmUrls($batch);
            // Stale targets are settled without warming so they cannot exhaust the retry budget.
            $settled = [...array_keys($prewarm->responses), ...$prewarm->stale()];
            foreach ($settled as $url) {
                $this->queue->checkpoint($task['id'], 'prewarm-url:' . hash('sha256', $url));
            }
            $this->recordPrewarmIssues($batch, $prewarm->issues);
            $prewarmPending = count($pending) > count($settled);
            $sideEffects['prewarm'] = [
                'attempted'          => $prewarm->attempted(),
                'successful'         => $prewarm->successful(),
                'failed'             => $prewarm->failed(),
                'skipped'            => count($prewarm->stale()),
                'discovery_complete' => !$discoveryIncomplete,
            ];
            if (!$prewarmPending && $prewarm->failed() === 0 && !$discoveryIncomplete) {
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
        if ($successful && $prewarmPending) {
            return SideEffectOutcome::Continue;
        }
        // Failed discovery spends the bounded retry budget only once every known target settled.
        return $successful && !$discoveryIncomplete;
    }

    /**
     * @param list<string> $snapshots
     * @return list<string>|null
     */
    private function snapshotTargets(array $snapshots): ?array
    {
        $targets = [];
        foreach ($snapshots as $snapshot) {
            $urls = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($urls) || count($urls) > 200 || count(array_filter($urls, is_string(...))) !== count($urls)) {
                return null;
            }
            $targets = [...$targets, ...array_values($urls)];
        }
        return $targets;
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
