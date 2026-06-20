<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class PurgeSideEffectQueueRepository
{
    private const string OPTION_QUEUE = 'sympress_nginx_cache_side_effect_queue';
    private const int MAX_TASKS = 50;

    public function __construct(
        private OptionMutex $mutex,
        private CacheClock $clock,
    ) {
    }

    public function push(PurgeResult $result, PurgeRequest $request): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $this->mutex->synchronized(
            self::OPTION_QUEUE,
            function () use ($result, $request): void {
                $tasks = [
                ...$this->all(), [
                    'result'    => $result->toArray(),
                    'request'   => $request->toArray(),
                    'queued_at' => $this->clock->timestamp(),
                ],
                ];

                update_option(self::OPTION_QUEUE, array_slice($tasks, -self::MAX_TASKS), false);
            },
        );
    }

    /** @return list<array{result: array<string, mixed>, request: array<string, mixed>, queued_at: int}> */
    public function all(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        $queue = get_option(self::OPTION_QUEUE, []);

        if (!is_array($queue)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map($this->normalizeTask(...), $queue),
                static fn (array $task): bool => $task !== [],
            ),
        );
    }

    /** @return list<array{result: array<string, mixed>, request: array<string, mixed>, queued_at: int}> */
    public function drain(): array
    {
        return $this->mutex->synchronized(
            self::OPTION_QUEUE,
            function (): array {
                $queue = $this->all();

                if (function_exists('delete_option')) {
                    delete_option(self::OPTION_QUEUE);
                }

                return $queue;
            },
        );
    }

    public function count(): int
    {
        return count($this->all());
    }

    /** @return array{result: array<string, mixed>, request: array<string, mixed>, queued_at: int}|array{} */
    private function normalizeTask(mixed $task): array
    {
        if (!is_array($task) || !is_array($task['result'] ?? null) || !is_array($task['request'] ?? null)) {
            return [];
        }

        return [
            'result'    => $task['result'],
            'request'   => $task['request'],
            'queued_at' => (int) ($task['queued_at'] ?? 0),
        ];
    }
}
