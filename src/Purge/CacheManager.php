<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class CacheManager
{
    public function __construct(
        private WordPressCacheSettings $settings,
        private CachePurger $purger,
        private PurgeHistoryRepository $history,
        private PurgeEventEmitter $events,
        private TagIndexRepository $tagIndex,
        private PurgeSideEffectProcessor $sideEffects,
    ) {
    }

    public function purgeConfiguredPath(?PurgeRequest $request = null): PurgeResult
    {
        $request ??= PurgeRequest::full(prewarm: $this->settings->prewarmEnabled());
        $result = $this->purger->purgeRequest($this->settings->cachePath(), $request);

        if ($result->successful && !$result->dryRun) {
            $this->syncTagIndex($result);
            $queuedSideEffects = $this->sideEffects->enqueue($result, $request);

            if ($queuedSideEffects !== []) {
                $result = $result->withSideEffects(['queued' => $queuedSideEffects]);
            }
        }

        $this->history->record($result);
        $this->events->emit($result);

        return $result;
    }

    private function syncTagIndex(PurgeResult $result): void
    {
        if (!$this->settings->tagIndexEnabled()) {
            return;
        }

        if ($result->mode === PurgeMode::Full) {
            $this->tagIndex->clear();

            return;
        }

        if ($result->purgedUrls === []) {
            return;
        }

        $this->tagIndex->forgetUrls($result->purgedUrls);
    }
}
