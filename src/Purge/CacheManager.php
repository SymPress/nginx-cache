<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use SymPress\NginxCache\Value\PurgeScope;

final readonly class CacheManager
{
    public function __construct(
        private WordPressCacheSettings $settings,
        private CachePurger $purger,
        private PurgeHistoryRepository $history,
        private PurgeEventEmitter $events,
        private TagIndexRepository $tagIndex,
        private PurgeSideEffectProcessor $sideEffects,
        private ?AlternativeCachePurger $alternative = null,
        private SiteScopeResolver $scope = new SiteScopeResolver(),
    ) {
    }

    public function purgeConfiguredPath(?PurgeRequest $request = null): PurgeResult
    {
        $request ??= PurgeRequest::full(prewarm: $this->settings->prewarmEnabled());
        if ($request->scope === PurgeScope::Site && !$request->requiresFullPurge() && $this->scope->isMultisite()) {
            $matcher = $this->scope->matcher();
            foreach ($request->urls as $url) {
                $parts = wp_parse_url($url);
                if (!is_array($parts) || !$matcher->matches((string) ($parts['host'] ?? ''), (string) ($parts['path'] ?? '/'))) {
                    return PurgeResult::failure($this->settings->cachePath(), 'The URL belongs to another site.')->withScope(PurgeScope::Site);
                }
            }
        }
        $result = $this->alternative?->purge($request)
            ?? $this->purger->purgeRequest($this->settings->cachePath(), $request);
        $result = $result->withScope($request->scope);

        if ($result->successful && !$result->dryRun) {
            if (!$result->partial) {
                $this->syncTagIndex($result);
            }
            $queuedSideEffects = $this->sideEffects->enqueue($result, $request);

            $warning = $this->sideEffects->attentionReason();
            if ($queuedSideEffects !== [] || $warning !== '') {
                $result = $result->withSideEffects(['queued' => $queuedSideEffects, 'warning' => $warning]);
            }
        }

        $this->history->record($result);
        $this->events->emit($result);

        return $result;
    }

    private function syncTagIndex(PurgeResult $result): void
    {
        if (!$this->settings->tagIndexEnabled() && !($result->mode === PurgeMode::Full && $result->scope === PurgeScope::Network)) {
            return;
        }

        if ($result->mode === PurgeMode::Full) {
            if ($result->scope === PurgeScope::Network) {
                $this->tagIndex->clearNetwork();
            } else {
                $this->tagIndex->clear();
            }

            return;
        }

        if ($result->purgedUrls === []) {
            return;
        }

        $this->tagIndex->forgetUrls($result->purgedUrls);
    }
}
