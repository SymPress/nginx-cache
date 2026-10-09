<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Value\SiteKeyMatcher;

final readonly class SiteScopedCacheScanner
{
    public function __construct(private CacheKeyStrategy $keys)
    {
    }

    /** @return array{entries: list<string>, scanned: int, unmatched: int, partial: bool, cursor: string} */
    public function scan(string $root, SiteKeyMatcher $matcher, string $cursor = '', ?int $fileBudget = null, ?float $timeBudget = null): array
    {
        if ($cursor !== '' && preg_match('~^(?:[a-f0-9]{1,3}/){0,3}[a-f0-9]{32}$~iD', $cursor) !== 1) {
            throw new \InvalidArgumentException('Invalid site scan cursor.');
        }
        $fileBudget ??= defined('SYMPRESS_NGINX_CACHE_SCAN_FILE_BUDGET') ? (int) SYMPRESS_NGINX_CACHE_SCAN_FILE_BUDGET : 20000;
        $timeBudget ??= defined('SYMPRESS_NGINX_CACHE_SCAN_TIME_BUDGET') ? (float) SYMPRESS_NGINX_CACHE_SCAN_TIME_BUDGET : 10.0;
        $fileBudget = max(1, min(200000, $fileBudget));
        $timeBudget = max(0.01, min(60.0, $timeBudget));
        $started = microtime(true);
        $result = ['entries' => [], 'scanned' => 0, 'unmatched' => 0, 'partial' => false, 'cursor' => $cursor];
        foreach ($this->files($root, '', $cursor) as $relative) {
            if ($result['scanned'] >= $fileBudget || microtime(true) - $started >= $timeBudget) {
                $result['partial'] = true;
                return $result;
            }
            ++$result['scanned'];
            $result['cursor'] = $relative;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read at most the Nginx binary/header prefix inside the validated locked cache root.
            $header = @file_get_contents($root . '/' . $relative, false, null, 0, 4096);
            $key = is_string($header) && preg_match('/(?:^|\n)KEY: ([^\r\n]+)\r?\n/', $header, $match) === 1 ? $this->keys->parseKey($match[1]) : null;
            if ($key === null) {
                ++$result['unmatched'];
                continue;
            }
            if (!$matcher->matches($key['host'], $key['uri'])) {
                continue;
            }

            $result['entries'][] = $root . '/' . $relative;
        }
        $result['cursor'] = '';
        return $result;
    }

    /** @return \Generator<int, string> */
    private function files(string $root, string $relative, string $cursor, int $depth = 0): \Generator
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Deterministic bounded directory traversal supports durable lexicographic cursors.
        $names = scandir($root . ($relative === '' ? '' : '/' . $relative));
        if ($names === false) {
            throw new \RuntimeException('The cache directory cannot be scanned.');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $next = ($relative === '' ? '' : $relative . '/') . $name;
            $path = $root . '/' . $next;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path) && $depth < 3 && preg_match('/^[a-f0-9]{1,3}$/iD', $name) === 1) {
                // Skip completed subtrees without revisiting every expired filename.
                if ($cursor === '' || str_starts_with($cursor, $next . '/') || strcmp($next . '/', $cursor) > 0) {
                    yield from $this->files($root, $next, $cursor, $depth + 1);
                }
            } elseif (is_file($path) && preg_match('/^[a-f0-9]{32}$/iD', $name) === 1 && ($cursor === '' || strcmp($next, $cursor) > 0)) {
                yield $next;
            }
        }
    }
}
