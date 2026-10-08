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

    /** @return array{hits: int, requests: int, hit_rate: ?float, sampled: bool, window_seconds: int, log_state: string, states: array<string, int>, small_sample: bool, measured_at: int} */
    public function read(string $path, string $host): array
    {
        $result = ['hits' => 0, 'requests' => 0, 'hit_rate' => null, 'sampled' => false, 'window_seconds' => self::WINDOW_SECONDS, 'log_state' => 'unavailable', 'states' => array_fill_keys(self::CACHE_STATES, 0), 'small_sample' => true, 'measured_at' => $this->clock->timestamp()];
        if ($host === '' || !is_file($path) || !is_readable($path)) {
            if ($host !== '') {
                $result['log_state'] = !is_file($path) ? 'missing' : 'unreadable';
            }
            return $result;
        }

        // Convert I/O races into exceptions without leaving a PHP warning in error_get_last().
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Handle an expected log rotation/read race, restoring the previous handler below.
        set_error_handler(static function (): never {
            throw new \RuntimeException('Cannot read the cache metrics log.');
        });
        try {
            $file = new \SplFileObject($path, 'rb');
            $stat = $file->fstat();
            if ($stat === false || $stat['size'] === 0) {
                $result['log_state'] = $stat === false ? 'unavailable' : 'no_requests';
                return $result;
            }
            $offset = max(0, $stat['size'] - self::MAX_BYTES);
            if ($file->fseek($offset) !== 0) {
                return $result;
            }
            $data = $file->fread(self::MAX_BYTES);
        } catch (\Throwable) {
            return $result;
        } finally {
            restore_error_handler();
        }
        if (!is_string($data)) {
            return $result;
        }
        $result['log_state'] = 'no_requests';

        $lines = explode("\n", $data);
        // Ignore both a truncated first record and the incomplete record of an active writer.
        array_pop($lines);
        if ($offset > 0) {
            array_shift($lines);
        }
        $result['sampled'] = $offset > 0 || count($lines) > self::MAX_LINES;
        $lines = array_slice($lines, -self::MAX_LINES);
        $now = $this->clock->timestamp();
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
}
