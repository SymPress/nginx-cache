<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Remote\RemotePurgeDispatcher;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class PurgeSideEffectProcessor
{
    public const string HOOK = 'sympress_nginx_cache_process_side_effects';

    public function __construct(
        private WordPressCacheSettings $settings,
        private PurgeSideEffectQueueRepository $queue,
        private Prewarmer $prewarmer,
        private CacheLayerCoordinator $layers,
        private RemotePurgeDispatcher $remote,
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
        foreach ($this->queue->drain() as $task) {
            $result = PurgeResult::fromArray($task['result']);
            $request = PurgeRequest::fromArray($task['request']);
            $sideEffects = [];

            if ($this->shouldPrewarm($result, $request)) {
                $prewarm = $this->prewarmer->prewarm($result->requestedUrls !== [] ? $result->requestedUrls : []);
                $sideEffects['prewarm'] = [
                    'attempted'  => $prewarm->attempted(),
                    'successful' => $prewarm->successful(),
                    'failed'     => $prewarm->failed(),
                ];
            }

            $layers = $this->layers->sync($result);

            if ($layers !== []) {
                $sideEffects['layers'] = $layers;
            }

            $remote = $this->remote->dispatch($result, $request);

            if ($remote !== []) {
                $sideEffects['remote'] = $remote;
            }

            if (!function_exists('do_action')) {
                continue;
            }

            do_action('sympress_nginx_cache_side_effects_processed', $result, $request, $sideEffects);
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

        wp_schedule_single_event(time() + 1, self::HOOK);
    }

    public function count(): int
    {
        return $this->queue->count();
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

        return $tasks;
    }

    private function shouldPrewarm(PurgeResult $result, PurgeRequest $request): bool
    {
        return $result->successful
            && !$result->dryRun
            && ($request->prewarm || $this->settings->prewarmEnabled());
    }
}
