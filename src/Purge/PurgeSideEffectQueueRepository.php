<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use SymPress\NginxCache\Value\QueueRetryState;

/** @phpstan-type QueueTask array{result: array<string, mixed>, request: array<string, mixed>, queued_at: int, id: string, completed: list<string>, retry: array{attempts: int, retry_at: int}} */
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
                    'id'        => bin2hex(random_bytes(16)),
                    'completed' => [],
                    'retry'     => (new QueueRetryState())->toArray(),
                ],
                ];

                if (count($tasks) > self::MAX_TASKS || !$this->persist($tasks)) {
                    throw new \RuntimeException('Side-effect queue is full or cannot be saved; pending tasks were retained.');
                }
            },
        );
    }

    /** @return list<QueueTask> */
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

        $tasks = [];
        foreach (array_values($queue) as $index => $task) {
            $normalized = $this->normalizeTask($task, $index);
            if ($normalized === []) {
                continue;
            }

            $tasks[] = $normalized;
        }
        return $tasks;
    }

    /** @return list<QueueTask> */
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

    /** @param callable(QueueTask): bool $execute */
    public function process(callable $execute): bool
    {
        return $this->mutex->synchronized(self::OPTION_QUEUE . '.process', function () use ($execute): bool {
            foreach ($this->all() as $task) {
                $retry = QueueRetryState::fromArray($task['retry']);
                if (!$retry->ready($this->clock->timestamp())) {
                    continue;
                }
                // A persisted reservation bounds retries even if execution crashes.
                $task['retry'] = $retry->reserve($this->clock->timestamp())->toArray();
                $this->replace($task);
                try {
                    $successful = $execute($task);
                } catch (\Throwable) {
                    $successful = false;
                }
                if (!$successful) {
                    continue;
                }
                $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($task): void {
                    $pending = $this->all();
                    foreach ($pending as $index => $current) {
                        if ($current['id'] === $task['id']) {
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

    /** @param QueueTask $task */
    private function replace(array $task): void
    {
        $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($task): void {
            $pending = $this->all();
            foreach ($pending as $index => $current) {
                if ($current['id'] === $task['id']) {
                    $pending[$index] = $task;
                    if (!$this->persist($pending)) {
                        throw new \RuntimeException('Unable to persist side-effect progress.');
                    }
                    return;
                }
            }
            throw new \RuntimeException('Side-effect task is no longer available.');
        });
    }

    public function checkpoint(string $id, string $completed): void
    {
        $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($id, $completed): void {
            $pending = $this->all();
            foreach ($pending as $index => $task) {
                if ($task['id'] === $id) {
                    $pending[$index]['completed'] = array_values(array_unique([...$task['completed'], $completed]));
                    if (!$this->persist($pending)) {
                        throw new \RuntimeException('Unable to persist side-effect progress.');
                    }
                    return;
                }
            }
            throw new \RuntimeException('Side-effect task is no longer available.');
        });
    }

    public function nextAttemptAt(): ?int
    {
        $times = [];
        foreach ($this->all() as $task) {
            $retry = QueueRetryState::fromArray($task['retry']);
            if ($retry->exhausted()) {
                continue;
            }

            $times[] = $retry->retryAt;
        }
        return $times === [] ? null : min($times);
    }

    /** @return list<array<string, mixed>> */
    public function inspect(): array
    {
        return array_map(static function (array $task): array {
            $retry = QueueRetryState::fromArray($task['retry']);
            return [
                'id'        => $task['id'],
                'request'   => $task['request'],
                'queued_at' => $task['queued_at'],
                'completed' => $task['completed'],
                ...$retry->toArray(),
                'exhausted' => $retry->exhausted(),
            ];
        }, $this->all());
    }

    public function retry(): void
    {
        $this->mutex->synchronized(self::OPTION_QUEUE . '.process', fn () => $this->mutex->synchronized(
            self::OPTION_QUEUE,
            function (): void {
                $pending = $this->all();
                foreach ($pending as $index => $task) {
                    $pending[$index]['retry'] = (new QueueRetryState())->toArray();
                }
                if (!$this->persist($pending)) {
                    throw new \RuntimeException('Unable to reset the side-effect retry budget.');
                }
            },
        ));
    }

    /** @param list<QueueTask> $tasks */
    private function persist(array $tasks): bool
    {
        return function_exists('update_option') && (update_option(self::OPTION_QUEUE, $tasks, false) || get_option(self::OPTION_QUEUE) === $tasks);
    }

    public function count(): int
    {
        return count($this->all());
    }

    /** @return QueueTask|array{} */
    private function normalizeTask(mixed $task, int $index): array
    {
        if (!is_array($task) || !is_array($task['result'] ?? null) || !is_array($task['request'] ?? null)) {
            return [];
        }

        return [
            'result'    => $task['result'],
            'request'   => $task['request'],
            'queued_at' => (int) ($task['queued_at'] ?? 0),
            'id'        => is_string($task['id'] ?? null) ? $task['id'] : $this->legacyId($task, $index),
            'completed' => array_values(array_filter(is_array($task['completed'] ?? null) ? $task['completed'] : [], is_string(...))),
            'retry'     => QueueRetryState::fromArray($task['retry'] ?? null)->toArray(),
        ];
    }

    /** @param array<mixed> $task */
    private function legacyId(array $task, int $index): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stable legacy identity without deserialization.
        $payload = json_encode($task, JSON_INVALID_UTF8_SUBSTITUTE);
        return hash('sha256', $index . (string) $payload);
    }
}
