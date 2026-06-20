<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class CacheFileResolver
{
    public function __construct(
        private WordPressCacheSettings $settings,
        private CacheKeyStrategy $keys,
    ) {
    }

    /** @return list<string> */
    public function candidates(string $cachePath, string $url): array
    {
        $candidates = [];

        foreach ($this->keyCandidates($url) as $candidate) {
            $hash = md5($candidate['key']);
            $candidates[] = $this->pathFromEndLevels($cachePath, $hash);
            $candidates[] = $this->pathFromStartLevels($cachePath, $hash);
        }

        return array_values(array_unique($candidates));
    }

    /** @return list<array{key: string, hash: string, files: list<string>}> */
    public function candidateDetails(string $cachePath, string $url): array
    {
        $details = [];

        foreach ($this->keyCandidates($url) as $candidate) {
            $hash = md5($candidate['key']);
            $details[] = [
                'key'   => $candidate['key'],
                'hash'  => $hash,
                'files' => array_values(array_unique([
                    $this->pathFromEndLevels($cachePath, $hash),
                    $this->pathFromStartLevels($cachePath, $hash),
                ])),
            ];
        }

        return $details;
    }

    /** @return list<array{scheme: string, forwarded_protocol: string, method: string, host: string, uri: string, key: string}> */
    public function keyCandidates(string $url): array
    {
        return $this->keys->candidates($url);
    }

    private function pathFromEndLevels(string $cachePath, string $hash): string
    {
        $offset = strlen($hash);
        $segments = [];

        foreach ($this->levels() as $level) {
            $offset -= $level;
            $segments[] = substr($hash, $offset, $level);
        }

        $segments[] = $hash;

        return sprintf('%s/%s', rtrim($cachePath, '/'), implode('/', $segments));
    }

    private function pathFromStartLevels(string $cachePath, string $hash): string
    {
        $offset = 0;
        $segments = [];

        foreach ($this->levels() as $level) {
            $segments[] = substr($hash, $offset, $level);
            $offset += $level;
        }

        $segments[] = $hash;

        return sprintf('%s/%s', rtrim($cachePath, '/'), implode('/', $segments));
    }

    /** @return list<int> */
    private function levels(): array
    {
        $levels = array_map('intval', explode(':', $this->settings->cacheLevels()));

        return array_values(
            array_filter($levels, static fn (int $level): bool => $level > 0 && $level < 32),
        ) ?: [1, 2];
    }
}
