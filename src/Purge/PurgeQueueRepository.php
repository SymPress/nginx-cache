<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\QueueRetryState;

final readonly class PurgeQueueRepository
{
    private const string OPTION_QUEUE = 'sympress_nginx_cache_queue';

    public function __construct(
        private PurgeRequestMerger $merger,
        private OptionMutex $mutex,
        private CacheClock $clock,
    ) {
    }

    public function push(PurgeRequest $request): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        if (($GLOBALS['wpdb'] ?? null) instanceof \wpdb) {
            // Each producer owns a unique immutable inbox item: no contended merge lock.
            // Processing merges and removes it only after the aggregate is durable.
            $name = self::OPTION_QUEUE . '_inbox_' . bin2hex(random_bytes(16));
            if (!add_option($name, $request->toArray(), '', false)) {
                throw new \RuntimeException('Unable to persist the purge inbox request.');
            }
            return;
        }

        $this->mutex->synchronized(
            self::OPTION_QUEUE,
            function () use ($request): void {
                $merged = $this->merger->merge([...$this->all(), $request]);
                if (!$this->persist($merged, $this->retryState())) {
                    throw new \RuntimeException('Unable to persist the purge request.');
                }
            },
        );
    }

    /** @return list<PurgeRequest> */
    public function all(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        if (!function_exists('wp_cache_delete')) {
            return $this->readQueue();
        }

        wp_cache_delete(self::OPTION_QUEUE, 'options');
        wp_cache_delete('notoptions', 'options');

        return $this->readQueue();
    }

    /** @return list<PurgeRequest> */
    private function readQueue(): array
    {
        $queue = get_option(self::OPTION_QUEUE, []);

        if (!is_array($queue)) {
            return [];
        }

        $requests = array_values(
            array_map(
                static fn (array $item): PurgeRequest => PurgeRequest::fromArray($item),
                array_filter($queue, static fn (mixed $item): bool => is_array($item)),
            ),
        );
        return $this->merger->merge([...$requests, ...array_column($this->inbox(), 'request')]);
    }

    /** @return list<PurgeRequest> */
    public function drain(): array
    {
        return $this->mutex->synchronized(
            self::OPTION_QUEUE,
            function (): array {
                $this->ingestInbox();
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

    /** @param callable(PurgeRequest): bool $purge */
    public function process(callable $purge): bool
    {
        return $this->mutex->synchronized(self::OPTION_QUEUE . '.process', function () use ($purge): bool {
            $snapshot = $this->mutex->synchronized(self::OPTION_QUEUE, function (): array {
                $this->ingestInbox();
                $requests = $this->all();
                $retry = $this->retryState();
                if ($requests === [] || !$retry->ready($this->clock->timestamp())) {
                    return [[], null];
                }
                $generation = $this->generation() ?? bin2hex(random_bytes(16));
                // Reserve before side effects so a crash also consumes this attempt.
                if (!$this->persist($requests, $retry->reserve($this->clock->timestamp()), $generation)) {
                    throw new \RuntimeException('Unable to reserve the purge attempt.');
                }
                return [$requests, $generation];
            });
            foreach ($snapshot[0] as $request) {
                try {
                    $successful = $purge($request);
                } catch (\Throwable) {
                    $successful = false;
                }
                if (!$successful) {
                    return false;
                }
                $acknowledged = $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($request, $snapshot): bool {
                    $this->ingestInbox();
                    $pending = $this->all();
                    if ($this->generation() !== $snapshot[1]) {
                        // The purge succeeded; new concurrent work gets a fresh budget.
                        return $this->persist($pending, new QueueRetryState(), $this->generation());
                    }
                    foreach ($pending as $index => $current) {
                        if ($current->toArray() === $request->toArray()) {
                            unset($pending[$index]);
                            return $this->persist(array_values($pending), $this->retryState(), $snapshot[1]);
                        }
                    }
                    // A concurrent producer merged new work into this item. Retain that work.
                    return true;
                });
                if (!$acknowledged) {
                    return false;
                }
            }
            return $this->count() === 0;
        });
    }

    public function nextAttemptAt(): ?int
    {
        if ($this->all() === []) {
            return null;
        }
        $retry = $this->retryState();
        return $retry->exhausted() ? null : $retry->retryAt;
    }

    /** @return list<array<string, mixed>> */
    public function inspect(): array
    {
        $requests = $this->all();
        $retry = $this->retryState();
        return array_map(static fn (PurgeRequest $request): array => [
            'request'   => $request->toArray(),
            ...$retry->toArray(),
            'exhausted' => $retry->exhausted(),
        ], $requests);
    }

    public function retry(): void
    {
        $this->mutex->synchronized(self::OPTION_QUEUE . '.process', fn () => $this->mutex->synchronized(
            self::OPTION_QUEUE,
            function (): void {
                if (!$this->persist($this->all(), new QueueRetryState())) {
                    throw new \RuntimeException('Unable to reset the purge retry budget.');
                }
            },
        ));
    }

    private function retryState(): QueueRetryState
    {
        if (!function_exists('get_option')) {
            return new QueueRetryState();
        }
        $queue = get_option(self::OPTION_QUEUE, []);
        return QueueRetryState::fromArray(is_array($queue) ? ($queue[0]['_retry'] ?? null) : null);
    }

    /** @return list<array{name: string, value: string, request: PurgeRequest}> */
    private function inbox(): array
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (!$db instanceof \wpdb) {
            return [];
        }
        $rows = $db->get_results($db->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id', $db->options, $db->esc_like(self::OPTION_QUEUE . '_inbox_') . '%'), ARRAY_A);
        $items = [];
        foreach ($rows ?? [] as $row) {
            $payload = maybe_unserialize($row['option_value']);
            if (!is_array($payload)) {
                throw new \RuntimeException('Invalid retained purge inbox payload.');
            }
            $items[] = ['name' => (string) $row['option_name'], 'value' => (string) $row['option_value'], 'request' => PurgeRequest::fromArray($payload)];
        }
        return $items;
    }

    /** Caller owns the aggregate mutation lock. */
    private function ingestInbox(): void
    {
        $items = $this->inbox();
        if ($items === []) {
            return;
        }
        if (!$this->persist($this->all(), $this->retryState())) {
            throw new \RuntimeException('Unable to merge the purge inbox.');
        }
        $db = $GLOBALS['wpdb'];
        foreach ($items as $item) {
            if ($db->query((string) $db->prepare('DELETE FROM %i WHERE option_name = %s AND option_value = %s', $db->options, $item['name'], $item['value'])) === false) {
                throw new \RuntimeException('Unable to acknowledge the purge inbox.');
            }
            wp_cache_delete($item['name'], 'options');
        }
    }

    private function generation(): ?string
    {
        $queue = get_option(self::OPTION_QUEUE, []);
        return is_array($queue) && is_string($queue[0]['_generation'] ?? null) ? $queue[0]['_generation'] : null;
    }

    /** @param list<PurgeRequest> $requests */
    private function persist(array $requests, QueueRetryState $retry, ?string $generation = null): bool
    {
        if (!function_exists('update_option')) {
            return false;
        }

        $generation ??= bin2hex(random_bytes(16));
        $payload = array_map(static fn (PurgeRequest $item): array => [
            ...$item->toArray(),
            '_generation' => $generation,
            '_retry'      => $retry->toArray(),
        ], $requests);

        return update_option(self::OPTION_QUEUE, $payload, false) || get_option(self::OPTION_QUEUE) === $payload;
    }
}
