<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Inspection;

use SymPress\NginxCache\Time\CacheClock;

final readonly class CacheMetricsReader
{
    private const int MAX_BYTES = 1_048_576;
    private const int MAX_LINES = 5000;
    private const int WINDOW_SECONDS = 3600;
    private const array CACHE_STATES = ['HIT', 'MISS', 'EXPIRED', 'STALE', 'UPDATING', 'REVALIDATED'];
    private const array HIT_STATES = ['HIT', 'STALE', 'UPDATING', 'REVALIDATED'];

    public function __construct(private CacheClock $clock)
    {
    }

    public static function logPath(): string
    {
        $path = defined('SYMPRESS_NGINX_CACHE_METRICS_LOG') ? constant('SYMPRESS_NGINX_CACHE_METRICS_LOG') : null;

        return is_string($path) && trim($path) !== '' ? trim($path) : '/var/log/nginx/sympress-cache-metrics.jsonl';
    }

    /** @return array{hits: int, requests: int, hit_rate: ?float, sampled: bool, coverage_gap: bool, window_seconds: int, log_state: string, states: array<string, int>, small_sample: bool, measured_at: int} */
    public function read(string $path, string $host): array
    {
        $result = ['hits' => 0, 'requests' => 0, 'hit_rate' => null, 'sampled' => false, 'coverage_gap' => false, 'window_seconds' => self::WINDOW_SECONDS, 'log_state' => 'unavailable', 'states' => array_fill_keys(self::CACHE_STATES, 0), 'small_sample' => true, 'measured_at' => $this->clock->timestamp()];
        if ($host === '' || !is_file($path) || !is_readable($path)) {
            if ($host !== '') {
                $result['log_state'] = !is_file($path) ? 'missing' : 'unreadable';
            }
            return $result;
        }
        $active = $this->slice($path, self::MAX_BYTES);
        if ($active === null) {
            return $result;
        }
        $result['log_state'] = 'no_requests';
        $now = $this->clock->timestamp();

        // Logrotate moves still-current records into "<log>.1"; read them within the shared byte budget.
        $chunks = [$active];
        $rotated = 0;
        $previousPath = $path . '.1';
        if ($active['offset'] === 0 && is_file($previousPath) && is_readable($previousPath)) {
            $previous = $this->slice($previousPath, self::MAX_BYTES - strlen($active['data']));
            if ($previous !== null) {
                $rotated = 1;
                // Between copytruncate's copy and truncate both files hold the same bytes at the same offsets; count them once.
                if ($previous['data'] === '' || ($this->slice($path, strlen($previous['data']), $previous['offset'])['data'] ?? null) !== $previous['data']) {
                    array_unshift($chunks, $previous);
                }
            }
        }
        // An unread older generation (compressed, unreadable or beyond .1) written within the window leaves a gap.
        if ($chunks[0]['offset'] === 0) {
            $older = $path . '.' . ($rotated + 1);
            foreach ([$older, $older . '.gz'] as $candidate) {
                $result['coverage_gap'] = $result['coverage_gap'] || (is_file($candidate) && (int) filemtime($candidate) >= $now - self::WINDOW_SECONDS);
            }
        }

        $lines = [];
        foreach ($chunks as $chunk) {
            $chunkLines = explode("\n", $chunk['data']);
            // Ignore both a truncated first record and the incomplete record of an active writer.
            array_pop($chunkLines);
            if ($chunk['offset'] > 0) {
                array_shift($chunkLines);
                $result['sampled'] = true;
            }
            $lines = [...$lines, ...$chunkLines];
        }
        $result['sampled'] = $result['sampled'] || count($lines) > self::MAX_LINES;
        $lines = array_slice($lines, -self::MAX_LINES);
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || !is_string($record['host'] ?? null) || strtolower($record['host']) !== strtolower($host)) {
                continue;
            }
            $time = $record['time'] ?? null;
            if (!is_numeric($time) || !is_finite((float) $time) || (float) $time < $now - self::WINDOW_SECONDS || (float) $time > $now + 1) {
                continue;
            }
            $state = $record['cache'] ?? null;
            if (!in_array($state, self::CACHE_STATES, true)) {
                continue;
            }
            ++$result['requests'];
            ++$result['states'][$state];
            if (!in_array($state, self::HIT_STATES, true)) {
                continue;
            }
            ++$result['hits'];
        }
        if ($result['requests'] > 0) {
            $result['small_sample'] = $result['requests'] < 100;
            $result['hit_rate'] = 100.0 * $result['hits'] / $result['requests'];
            $result['log_state'] = 'ok';
        }

        return $result;
    }

    /** @return ?array{offset: int, data: string} The last $length bytes, or $length bytes from $offset. */
    private function slice(string $path, int $length, ?int $offset = null): ?array
    {
        // Convert I/O races into exceptions without leaving a PHP warning in error_get_last().
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Handle an expected log rotation/read race, restoring the previous handler below.
        set_error_handler(static function (): never {
            throw new \RuntimeException('Cannot read the cache metrics log.');
        });
        try {
            $file = new \SplFileObject($path, 'rb');
            $stat = $file->fstat();
            if ($stat === false) {
                return null;
            }
            $offset ??= max(0, $stat['size'] - $length);
            if ($length <= 0 || $offset >= $stat['size']) {
                return ['offset' => $offset, 'data' => ''];
            }
            if ($file->fseek($offset) !== 0) {
                return null;
            }
            $data = $file->fread($length);
            return is_string($data) ? ['offset' => $offset, 'data' => $data] : null;
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
