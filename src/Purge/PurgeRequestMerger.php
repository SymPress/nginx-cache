<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeScope;

final readonly class PurgeRequestMerger
{
    public const int MAX_URLS = 500;
    // Hard bound for URL lists of delegated purges; PurgeQueueRepository rejects delegated pushes beyond it.
    public const int MAX_QUEUED_URLS = 10_000;

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
     * Delegated URL requests stay a separate item so their URLs never count towards collapsing
     * privileged work into a full purge; their size is bounded when they are queued.
     *
     * @param list<PurgeRequest> $items
     * @return list<PurgeRequest>
     */
    private function mergeScope(array $items, PurgeScope $scope): array
    {
        $privileged = [];
        $delegated = [];
        foreach ($items as $item) {
            if ($item->allowFullPurge || $item->mode === PurgeMode::Full) {
                $privileged[] = $item;
            } else {
                $delegated[] = $item;
            }
        }

        $privilegedUrls = $this->urls($privileged);
        $requiresFull = array_any($privileged, static fn (PurgeRequest $item): bool => $item->mode === PurgeMode::Full);
        if ($requiresFull || count($privilegedUrls) > self::MAX_URLS) {
            // A full purge covers the delegated URLs as well.
            $full = PurgeRequest::full(
                $this->joined(array_map(static fn (PurgeRequest $item): string => $item->reason, $items)),
                $this->joined(array_map(static fn (PurgeRequest $item): string => $item->source, $items)),
                array_all($items, static fn (PurgeRequest $item): bool => $item->dryRun),
                array_any($items, static fn (PurgeRequest $item): bool => $item->prewarm),
                $scope,
                $this->affected($items),
            );

            return [$full];
        }

        return [
            ...$this->urlRequest($privileged, $privilegedUrls, $scope, true),
            ...$this->urlRequest($delegated, array_values(array_diff($this->urls($delegated), $privilegedUrls)), $scope, false),
        ];
    }

    /**
     * @param list<PurgeRequest> $items
     * @param list<string> $urls
     * @return list<PurgeRequest>
     */
    private function urlRequest(array $items, array $urls, PurgeScope $scope, bool $allowFull): array
    {
        if ($urls === []) {
            return [];
        }

        $request = PurgeRequest::urls(
            $urls,
            $this->joined(array_map(static fn (PurgeRequest $item): string => $item->reason, $items)),
            $this->joined(array_map(static fn (PurgeRequest $item): string => $item->source, $items)),
            array_all($items, static fn (PurgeRequest $item): bool => $item->dryRun),
            array_any($items, static fn (PurgeRequest $item): bool => $item->prewarm),
            array_values(array_unique(array_merge(...array_map(static fn (PurgeRequest $item): array => $item->tags, $items)))),
            $scope,
            $allowFull,
            $this->affected($items),
        );

        return [$request];
    }

    /**
     * @param list<PurgeRequest> $items
     * @return list<string>
     */
    private function urls(array $items): array
    {
        return array_values(array_unique(array_merge(...array_map(static fn (PurgeRequest $item): array => $item->urls, $items))));
    }

    /**
     * @param list<PurgeRequest> $items
     * @return list<string>
     */
    private function affected(array $items): array
    {
        return array_values(array_unique(array_merge(...array_map(static fn (PurgeRequest $item): array => $item->affectedUrls, $items))));
    }

    /** @param list<string> $values */
    private function joined(array $values): string
    {
        return substr(implode(', ', array_values(array_unique($values))), 0, 500);
    }
}
