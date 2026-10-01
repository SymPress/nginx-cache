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

                if (count($tasks) > self::MAX_TASKS || !$this->persist($tasks)) {
                    throw new \RuntimeException('Side-effect queue is full or cannot be saved; pending tasks were retained.');
                }
            },
        );
    }

    /** @return list<array{result: array<string, mixed>, request: array<string, mixed>, queued_at: int}> */
    public function all(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_QUEUE, 'options');
            wp_cache_delete('notoptions', 'options');
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

    /** @param callable(array{result: array<string, mixed>, request: array<string, mixed>, queued_at: int}): bool $execute */
    public function process(callable $execute): bool
    {
        return $this->mutex->synchronized(self::OPTION_QUEUE . '.process', function () use ($execute): bool {
            foreach ($this->all() as $task) {
                if (!$execute($task)) {
                    return false;
                }
                $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($task): void {
                    $pending = $this->all();
                    foreach ($pending as $index => $current) {
                        if ($current === $task) {
                            unset($pending[$index]);
                            if (!$this->persist(array_values($pending))) {
                                throw new \RuntimeException('Unable to acknowledge side effects.');
                            }
                            break;
                        }
                    }
                });
            }
            return $this->count() === 0;
        });
    }

    /** @param list<array{result: array<string, mixed>, request: array<string, mixed>, queued_at: int}> $tasks */
    private function persist(array $tasks): bool
    {
        return function_exists('update_option') && (update_option(self::OPTION_QUEUE, $tasks, false) || get_option(self::OPTION_QUEUE) === $tasks);
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
