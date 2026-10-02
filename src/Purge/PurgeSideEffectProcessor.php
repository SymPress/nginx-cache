<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Remote\CloudflarePurgeDispatcher;
use SymPress\NginxCache\Remote\RemotePurgeDispatcher;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;

/** @phpstan-import-type QueueTask from PurgeSideEffectQueueRepository */
final readonly class PurgeSideEffectProcessor
{
    public const string HOOK = 'sympress_nginx_cache_process_side_effects';

    public function __construct(
        private WordPressCacheSettings $settings,
        private PurgeSideEffectQueueRepository $queue,
        private Prewarmer $prewarmer,
        private CacheLayerCoordinator $layers,
        private RemotePurgeDispatcher $remote,
        private CloudflarePurgeDispatcher $cloudflare,
        private CacheClock $clock,
    ) {
    }

    /** @return list<string> */
    public function enqueue(PurgeResult $result, PurgeRequest $request): array
    {
        $tasks = $this->plannedTasks($result, $request);

        if ($tasks === []) {
            return [];
        }

        $this->queue->push($result, $request);
        $this->schedule();

        return $tasks;
    }

    public function process(): void
    {
        try {
            $this->queue->process($this->executeTask(...));
        } catch (\Throwable) {
            // Leave the stored task for retry, without logging provider credentials.
        }
        if ($this->queue->count() <= 0) {
            return;
        }

        $this->schedule(60);
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

    /** @return list<array<string, mixed>> */
    public function inspect(): array
    {
        return $this->queue->inspect();
    }

    public function retry(): void
    {
        $this->queue->retry();
        $this->schedule();
    }

    /** @param QueueTask $task */
    private function executeTask(array $task): bool
    {
        $result = PurgeResult::fromArray($task['result']);
        $request = PurgeRequest::fromArray($task['request']);
        if ($result->dryRun || !$result->successful) {
            return true;
        }
        $sideEffects = [];
        if ($this->shouldPrewarm($result, $request) && !in_array('prewarm', $task['completed'], true)) {
            $prewarm = $this->prewarmer->prewarm($result->requestedUrls !== [] ? $result->requestedUrls : []);
            $sideEffects['prewarm'] = [
                'attempted' => $prewarm->attempted(),
            'successful'    => $prewarm->successful(),
            'failed'        => $prewarm->failed(),
            ];
            if ($prewarm->failed() === 0) {
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
        return ($sideEffects['prewarm']['failed'] ?? 0) === 0
            && $this->successfulResponses($sideEffects['layers'])
            && $this->successfulResponses($sideEffects['remote'])
            && $this->successfulResponses($sideEffects['cloudflare'] ?? []);
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
