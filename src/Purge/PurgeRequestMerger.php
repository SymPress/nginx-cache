<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeScope;

final readonly class PurgeRequestMerger
{
    public const int MAX_URLS = 500;

    /**
     * @param list<PurgeRequest> $items
     * @return list<PurgeRequest>
     */
    public function merge(array $items): array
    {
        $site = array_values(array_filter($items, static fn (PurgeRequest $request): bool => $request->scope === PurgeScope::Site));
        $network = array_values(array_filter($items, static fn (PurgeRequest $request): bool => $request->scope === PurgeScope::Network));
        return [...$this->mergeScope($site, PurgeScope::Site), ...$this->mergeScope($network, PurgeScope::Network)];
    }

    /**
     * @param list<PurgeRequest> $items
     * @return list<PurgeRequest>
     */
    private function mergeScope(array $items, PurgeScope $scope): array
    {
        $urls = [];
        $tags = [];
        $reasons = [];
        $sources = [];
        $prewarm = false;
        $dryRun = true;
        $requiresFull = false;

        foreach ($items as $item) {
            $reasons[] = $item->reason;
            $sources[] = $item->source;
            $prewarm = $prewarm || $item->prewarm;
            $dryRun = $dryRun && $item->dryRun;
            $tags = [...$tags, ...$item->tags];

            if ($item->mode === PurgeMode::Full) {
                $requiresFull = true;

                continue;
            }

            $urls = [...$urls, ...$item->urls];
        }

        $reasons = $this->joined($reasons);
        $sources = $this->joined($sources);
        $urls = array_values(array_unique($urls));
        $tags = array_values(array_unique($tags));

        if ($requiresFull || count($urls) > self::MAX_URLS) {
            return [PurgeRequest::full($reasons, $sources, $dryRun, $prewarm, $scope)];
        }

        if ($urls === []) {
            return [];
        }

        return [PurgeRequest::urls($urls, $reasons, $sources, $dryRun, $prewarm, $tags, $scope)];
    }

    /** @param list<string> $values */
    private function joined(array $values): string
    {
        return substr(implode(', ', array_values(array_unique($values))), 0, 500);
    }
}
