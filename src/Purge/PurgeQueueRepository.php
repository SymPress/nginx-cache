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
    private const int INBOX_SLOTS = 64;
    private const int INBOX_BATCH = self::INBOX_SLOTS + 4;

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
            $this->pushInbox($request);
            (new NetworkPendingRepository($this->mutex))->mark();
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
        (new NetworkPendingRepository($this->mutex))->mark();
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
    private function readQueue(bool $includeInbox = true): array
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
        return $this->merger->merge([...$requests, ...($includeInbox ? array_column($this->inbox(), 'request') : [])]);
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
    public function process(callable $purge, int $limit = PHP_INT_MAX): bool
    {
        return $this->mutex->synchronized(self::OPTION_QUEUE . '.process', function () use ($purge, $limit): bool {
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
            foreach (array_slice($snapshot[0], 0, max(0, $limit)) as $request) {
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
                            return $this->persist(array_values($pending), new QueueRetryState(), $snapshot[1]);
                        }
                    }
                    // A concurrent producer merged new work into this item. Retain that work.
                    return true;
                });
                if (!$acknowledged) {
                    return false;
                }
            }
            return true;
        });
    }

    public function nextAttemptAt(): ?int
    {
        // An exhausted queue has no automatic worker. Do not materialize its
        // inbox on every content-change hook merely to discover that fact.
        $retry = $this->retryState();
        if ($retry->exhausted() || $this->all() === []) {
            return null;
        }
        return $retry->retryAt;
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
        $rows = $db->get_results($db->prepare('SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id LIMIT %d', $db->options, $db->esc_like(self::OPTION_QUEUE . '_inbox_') . '%', self::INBOX_BATCH), ARRAY_A);
        $items = [];
        foreach ($rows ?? [] as $row) {
            $value = (string) $row['option_value'];
            $request = $this->decodeInbox($value);
            if ($request === null) {
                $name = (string) $row['option_name'];
                $quarantine = self::OPTION_QUEUE . '_quarantine_' . substr(hash('sha256', $name), 0, 32);
                if ($db->query((string) $db->prepare('UPDATE %i SET option_name = %s WHERE option_name = %s AND option_value = %s', $db->options, $quarantine, $name, $value)) === false) {
                    throw new \RuntimeException('Unable to quarantine invalid purge inbox payload.');
                }
                wp_cache_delete($name, 'options');
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Credential-free signal; corrupt input retained outside active queue.
                error_log('SymPress Nginx Cache: corrupt purge inbox item quarantined; other purges remain active.');
                continue;
            }
            $items[] = ['name' => (string) $row['option_name'], 'value' => $value, 'request' => $request];
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
        wp_cache_delete(self::OPTION_QUEUE, 'options');
        wp_cache_delete('notoptions', 'options');
        // Persist exactly the captured batch; an arriving producer's nonce
        // prevents its update from being acknowledged by this older snapshot.
        $merged = $this->merger->merge([...$this->readQueue(false), ...array_column($items, 'request')]);
        if (!$this->persist($merged, $this->retryState())) {
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

    private function pushInbox(PurgeRequest $request): void
    {
        $db = $GLOBALS['wpdb'];
        $slot = hexdec(substr(hash('sha256', maybe_serialize($request->toArray())), 0, 4)) % self::INBOX_SLOTS;
        $name = self::OPTION_QUEUE . '_inbox_slot_' . $request->scope->value . '_' . $slot;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $previous = $db->get_var($db->prepare('SELECT option_value FROM %i WHERE option_name = %s', $db->options, $name));
            $oldRequest = is_string($previous) ? $this->decodeInbox($previous) : null;
            if (is_string($previous) && $oldRequest === null && !$request->allowFullPurge) {
                throw new \RuntimeException('The URL purge inbox requires operator repair. Retry later.');
            }
            $merged = is_string($previous) && $oldRequest === null
                ? PurgeRequest::full('Invalid inbox payload', 'queue', $request->dryRun, $request->prewarm, $request->scope)
                : ($this->merger->merge([$request, ...($oldRequest !== null ? [$oldRequest] : [])])[0] ?? $request);
            $value = maybe_serialize([...$merged->toArray(), '_id' => bin2hex(random_bytes(16))]);
            $query = $previous === null
                ? $db->prepare('INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $db->options, $name, $value, 'off')
                : $db->prepare('UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $db->options, $value, $name, $previous);
            $result = $db->query($query);
            if ($result === false) {
                throw new \RuntimeException('Unable to persist the purge inbox request.');
            }
            if ($result === 1) {
                wp_cache_delete($name, 'options');
                return;
            }
        }
        // Bounded contention fallback: an atomic full-purge marker covers all
        // affected URLs. Separate flags preserve dry-run and prewarm semantics.
        if (!$request->allowFullPurge) {
            throw new \RuntimeException('The URL purge inbox is busy. Retry later.');
        }
        $name = self::OPTION_QUEUE . '_inbox_overflow_' . $request->scope->value . '_' . (int) $request->dryRun . (int) $request->prewarm;
        $full = PurgeRequest::full('Inbox contention', 'queue', $request->dryRun, $request->prewarm, $request->scope);
        $value = maybe_serialize([...$full->toArray(), '_id' => bin2hex(random_bytes(16))]);
        if ($db->query($db->prepare('INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)', $db->options, $name, $value, 'off')) === false) {
            throw new \RuntimeException('Unable to persist the purge overflow marker.');
        }
        wp_cache_delete($name, 'options');
    }

    private function decodeInbox(string $value): ?PurgeRequest
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress option format; objects forbidden.
        $payload = is_serialized($value) ? unserialize($value, ['allowed_classes' => false]) : $value;
        $safe = is_array($payload) && in_array($payload['mode'] ?? null, ['full', 'urls'], true) && is_array($payload['urls'] ?? null);
        if (is_array($payload)) {
            array_walk_recursive($payload, static function (mixed $item) use (&$safe): void {
                $safe = $safe && (is_scalar($item) || $item === null);
            });
        }
        return $safe && is_array($payload) ? PurgeRequest::fromArray($payload) : null;
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
