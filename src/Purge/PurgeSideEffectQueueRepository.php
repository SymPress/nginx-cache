<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Support\MutationLockUnavailable;
use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Support\SideEffectStorageFailure;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use SymPress\NginxCache\Value\QueueRetryState;
use SymPress\NginxCache\Value\SideEffectOutcome;

/** @phpstan-type QueueTask array{result: array<string, mixed>, request: array<string, mixed>, queued_at: int, id: string, completed: list<string>, retry: array{attempts: int, retry_at: int}} */
final readonly class PurgeSideEffectQueueRepository
{
    private const string OPTION_QUEUE = 'sympress_nginx_cache_side_effect_queue';
    private const string INBOX_PREFIX = self::OPTION_QUEUE . '_inbox_';
    private const string PROGRESS_PREFIX = self::OPTION_QUEUE . '_progress_';
    private const int MAX_TASKS = 50;

    public function __construct(
        private OptionMutex $mutex,
        private CacheClock $clock,
    ) {
    }

    public function push(PurgeResult $result, PurgeRequest $request): bool
    {
        if (!function_exists('update_option')) {
            return false;
        }

        $task = [
            'result'    => $result->toArray(),
            'request'   => $request->toArray(),
            'queued_at' => $this->clock->timestamp(),
            'id'        => bin2hex(random_bytes(16)),
            'completed' => [],
            'retry'     => (new QueueRetryState())->toArray(),
        ];
        try {
            return $this->mutex->synchronized(
                self::OPTION_QUEUE,
                function () use ($task): bool {
                    $this->ingestInbox();
                    $tasks = [...$this->readQueue(), $task];
                    $coalesced = count($tasks) > self::MAX_TASKS;
                    if (!$this->persist($this->boundTasks($tasks))) {
                        throw new \RuntimeException('Side-effect queue cannot be saved; pending tasks were retained.');
                    }
                    return $coalesced;
                },
            );
        } catch (MutationLockUnavailable $error) {
            if (!(($GLOBALS['wpdb'] ?? null) instanceof \wpdb)) {
                throw $error;
            }
            // Each producer owns an immutable record; merging can wait for the lock.
            if (!add_option(self::INBOX_PREFIX . $task['id'], $task, '', false)) {
                throw new \RuntimeException('Unable to persist the side-effect inbox task.');
            }
            return false;
        }
    }

    /** @return list<QueueTask> */
    public function all(): array
    {
        $tasks = $this->readQueue();
        $identities = array_fill_keys(array_column($tasks, 'id'), true);
        foreach ($this->inbox() as $item) {
            if (isset($identities[$item['task']['id']])) {
                continue;
            }

            $tasks[] = $item['task'];
            $identities[$item['task']['id']] = true;
        }
        return $tasks;
    }

    /** @return list<QueueTask> */
    private function readQueue(): array
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
                $this->ingestInbox();
                $queue = $this->readQueue();

                if (function_exists('delete_option')) {
                    delete_option(self::OPTION_QUEUE);
                }

                return $queue;
            },
        );
    }

    /** @param callable(QueueTask): (bool|SideEffectOutcome) $execute */
    public function process(callable $execute, int $limit = self::MAX_TASKS): bool
    {
        return $this->mutex->synchronized(self::OPTION_QUEUE . '.process', function () use ($execute, $limit): bool {
            $snapshot = $this->mutex->synchronized(self::OPTION_QUEUE, function (): array {
                $this->ingestInbox();
                return $this->readQueue();
            });
            $processed = 0;
            foreach ($snapshot as $task) {
                $retry = QueueRetryState::fromArray($task['retry']);
                if (!$retry->ready($this->clock->timestamp())) {
                    continue;
                }
                if ($processed >= max(1, $limit)) {
                    break;
                }
                // A persisted reservation bounds retries even if execution crashes.
                $task['retry'] = $retry->reserve($this->clock->timestamp())->toArray();
                $reserved = $this->replace($task);
                if ($reserved === false) {
                    // A newer full task or pending confirmations fence this snapshot.
                    continue;
                }
                $task = $reserved;
                ++$processed;
                try {
                    $successful = $execute($task);
                } catch (MutationLockUnavailable $error) {
                    throw $error;
                } catch (SideEffectStorageFailure $error) {
                    throw $error;
                } catch (\Throwable) {
                    $successful = false;
                }
                if ($successful === SideEffectOutcome::Continue) {
                    // Successful batches do not consume the failure/crash retry budget.
                    $task['retry'] = (new QueueRetryState($retry->attempts, $this->clock->timestamp() + 1))->toArray();
                    $this->replace($task);
                    continue;
                }
                if (!$successful) {
                    continue;
                }
                try {
                    $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($task): void {
                        $this->ingestInbox();
                        $pending = $this->readQueue();
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
                } catch (MutationLockUnavailable $error) {
                    $this->deferProgress($task['id'], null, $error);
                }
            }
            return $this->count() === 0;
        });
    }

    /**
     * @param QueueTask $task
     * @return QueueTask|false
     */
    private function replace(array $task): array|false
    {
        return $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($task): array|false {
            $this->ingestInbox();
            if ($this->hasDeferredRecords(progressOnly: true)) {
                // Bounded ingestion must reach all confirmations before provider replay.
                return false;
            }
            $pending = $this->readQueue();
            foreach ($pending as $index => $current) {
                if ($current['id'] === $task['id']) {
                    $current['retry'] = $task['retry'];
                    $current['completed'] = array_values(array_unique([...$current['completed'], ...$task['completed']]));
                    $pending[$index] = $current;
                    if (!$this->persist($pending)) {
                        throw new \RuntimeException('Unable to persist side-effect progress.');
                    }
                    return $current;
                }
            }
            return false;
        });
    }

    public function checkpoint(string $id, string $completed): void
    {
        try {
            $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($id, $completed): void {
                $this->ingestInbox();
                $pending = $this->readQueue();
                foreach ($pending as $index => $task) {
                    if ($task['id'] === $id) {
                        $pending[$index]['completed'] = array_values(array_unique([...$task['completed'], $completed]));
                        if (!$this->persist($pending)) {
                            throw new \RuntimeException('Unable to persist side-effect progress.');
                        }
                        return;
                    }
                }
            // A bounded full task now covers a superseded in-flight identity.
            });
        } catch (MutationLockUnavailable $error) {
            $this->deferProgress($id, $completed, $error);
        } catch (\Throwable) {
            throw new SideEffectStorageFailure('Unable to persist side-effect checkpoint.');
        }
    }

    private function deferProgress(string $id, ?string $completed, MutationLockUnavailable $error): void
    {
        if (!(($GLOBALS['wpdb'] ?? null) instanceof \wpdb)) {
            throw $error;
        }
        $name = self::PROGRESS_PREFIX . bin2hex(random_bytes(16));
        if (!add_option($name, ['id' => $id, 'completed' => $completed], '', false)) {
            throw new SideEffectStorageFailure('Unable to persist side-effect completion.');
        }
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
        return $times === [] ? ($this->hasDeferredRecords() ? $this->clock->timestamp() : null) : min($times);
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
                $this->ingestInbox();
                $pending = $this->readQueue();
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
        return max(count($this->all()), $this->hasDeferredRecords() ? 1 : 0);
    }

    private function hasDeferredRecords(bool $progressOnly = false): bool
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (!$db instanceof \wpdb) {
            return false;
        }
        $found = $db->get_var($db->prepare('SELECT 1 FROM %i WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1', $db->options, $db->esc_like($progressOnly ? self::PROGRESS_PREFIX : self::INBOX_PREFIX) . '%', $db->esc_like(self::PROGRESS_PREFIX) . '%'));
        if ($db->last_error !== '') {
            throw new \RuntimeException('Unable to inspect pending side effects.');
        }
        return $found !== null;
    }

    /**
     * @param list<QueueTask> $tasks
     * @return list<QueueTask>
     */
    private function boundTasks(array $tasks): array
    {
        if (count($tasks) <= self::MAX_TASKS) {
            return $tasks;
        }
        $latest = $tasks[array_key_last($tasks)];
        $prewarm = false;
        foreach ($tasks as $task) {
            $prewarm = $prewarm || PurgeRequest::fromArray($task['request'])->prewarm;
        }
        $result = PurgeResult::fromArray($latest['result']);
        return [
        [
            'result'    => PurgeResult::success($result->path, $result->removedEntries, $result->durationSeconds, reason: 'side-effect-overflow', source: 'queue')->toArray(),
            'request'   => PurgeRequest::full('side-effect-overflow', 'queue', prewarm: $prewarm)->toArray(),
            'queued_at' => $this->clock->timestamp(),
            'id'        => bin2hex(random_bytes(16)),
            'completed' => [],
            'retry'     => (new QueueRetryState())->toArray(),
        ],
        ];
    }

    /** @return list<array{name: string, value: string, task: QueueTask}> */
    private function inbox(): array
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (!$db instanceof \wpdb) {
            return [];
        }
        $rows = $db->get_results($db->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id LIMIT 64', $db->options, $db->esc_like(self::INBOX_PREFIX) . '%'), ARRAY_A);
        if ($db->last_error !== '') {
            throw new \RuntimeException('Unable to read the side-effect inbox.');
        }
        $items = [];
        foreach ($rows ?? [] as $row) {
            $value = (string) $row['option_value'];
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Native option format; objects explicitly forbidden.
            $payload = is_serialized($value) ? unserialize($value, ['allowed_classes' => false]) : $value;
            $safe = is_array($payload)
                && is_string($payload['id'] ?? null)
                && preg_match('/^[a-f0-9]{32}$/D', $payload['id']) === 1
                && in_array($payload['request']['mode'] ?? null, ['full', 'urls'], true)
                && is_array($payload['request']['urls'] ?? null)
                && is_bool($payload['result']['successful'] ?? null);
            if (is_array($payload)) {
                array_walk_recursive($payload, static function (mixed $item) use (&$safe): void {
                    $safe = $safe && (is_scalar($item) || $item === null);
                });
            }
            $task = $safe ? $this->normalizeTask($payload, 0) : [];
            if ($task === []) {
                $name = (string) $row['option_name'];
                $quarantine = self::OPTION_QUEUE . '_quarantine_' . substr(hash('sha256', $name), 0, 32);
                if ($db->query((string) $db->prepare('UPDATE %i SET option_name = %s WHERE option_name = %s AND option_value = %s', $db->options, $quarantine, $name, $value)) === false) {
                    throw new \RuntimeException('Unable to quarantine invalid side-effect inbox data.');
                }
                wp_cache_delete($name, 'options');
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Credential-free signal; invalid data retained for inspection.
                error_log('SymPress Nginx Cache: corrupt side-effect inbox item quarantined.');
                continue;
            }
            $items[] = ['name' => (string) $row['option_name'], 'value' => $value, 'task' => $task];
        }
        return $items;
    }

    /** Caller owns the aggregate mutation lock. */
    private function ingestInbox(): void
    {
        $items = $this->inbox();
        if ($items === []) {
            $this->ingestProgress();
            return;
        }
        $tasks = $this->readQueue();
        $identities = array_fill_keys(array_column($tasks, 'id'), true);
        foreach ($items as $item) {
            if (isset($identities[$item['task']['id']])) {
                continue;
            }

            $tasks[] = $item['task'];
            $identities[$item['task']['id']] = true;
        }
        if (!$this->persist($this->boundTasks($tasks))) {
            throw new \RuntimeException('Unable to merge the side-effect inbox.');
        }
        $db = $GLOBALS['wpdb'];
        foreach ($items as $item) {
            if ($db->query((string) $db->prepare('DELETE FROM %i WHERE option_name = %s AND option_value = %s', $db->options, $item['name'], $item['value'])) === false) {
                throw new \RuntimeException('Unable to acknowledge the side-effect inbox.');
            }
            wp_cache_delete($item['name'], 'options');
        }
        $this->ingestProgress();
    }

    /** Caller owns the aggregate mutation lock; provider success never needs another provider attempt. */
    private function ingestProgress(): void
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (!$db instanceof \wpdb) {
            return;
        }
        $rows = $db->get_results($db->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id LIMIT 128', $db->options, $db->esc_like(self::PROGRESS_PREFIX) . '%'), ARRAY_A);
        if ($db->last_error !== '') {
            throw new \RuntimeException('Unable to read side-effect completion.');
        }
        if ($rows === []) {
            return;
        }
        $pending = $this->readQueue();
        foreach ($rows ?? [] as $row) {
            $value = (string) $row['option_value'];
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Native option format; objects explicitly forbidden.
            $event = is_serialized($value) ? unserialize($value, ['allowed_classes' => false]) : null;
            if (!is_array($event) || !is_string($event['id'] ?? null) || !array_key_exists('completed', $event) || ($event['completed'] !== null && !is_string($event['completed']))) {
                $name = (string) $row['option_name'];
                $quarantine = self::OPTION_QUEUE . '_quarantine_' . substr(hash('sha256', $name), 0, 32);
                if ($db->query((string) $db->prepare('UPDATE %i SET option_name = %s WHERE option_name = %s AND option_value = %s', $db->options, $quarantine, $name, $value)) === false) {
                    throw new \RuntimeException('Unable to quarantine invalid side-effect completion.');
                }
                wp_cache_delete($name, 'options');
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Invalid completion data retained for inspection.
                error_log('SymPress Nginx Cache: corrupt side-effect completion quarantined.');
                continue;
            }
            foreach ($pending as $index => $task) {
                if ($task['id'] !== $event['id']) {
                    continue;
                }
                if ($event['completed'] === null) {
                    unset($pending[$index]);
                } else {
                    $pending[$index]['completed'] = array_values(array_unique([...$task['completed'], $event['completed']]));
                }
                break;
            }
        }
        if (!$this->persist(array_values($pending))) {
            throw new \RuntimeException('Unable to merge side-effect completion.');
        }
        foreach ($rows ?? [] as $row) {
            if ($db->query((string) $db->prepare('DELETE FROM %i WHERE option_name = %s AND option_value = %s', $db->options, $row['option_name'], $row['option_value'])) === false) {
                throw new \RuntimeException('Unable to acknowledge side-effect completion.');
            }
            wp_cache_delete((string) $row['option_name'], 'options');
        }
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
