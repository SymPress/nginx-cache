<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;

final readonly class PurgeRequestMerger
{
    public const int MAX_URLS = 500;

    /**
     * @param list<PurgeRequest> $items
     * @return list<PurgeRequest>
     */
    public function merge(array $items): array
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
            return [PurgeRequest::full($reasons, $sources, $dryRun, $prewarm)];
        }

        if ($urls === []) {
            return [];
        }

        return [PurgeRequest::urls($urls, $reasons, $sources, $dryRun, $prewarm, $tags)];
    }

    /** @param list<string> $values */
    private function joined(array $values): string
    {
        return substr(implode(', ', array_values(array_unique($values))), 0, 500);
    }
}
