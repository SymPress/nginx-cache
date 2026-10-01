<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Value\PurgeRequest;

final readonly class PurgeQueueRepository
{
    private const string OPTION_QUEUE = 'sympress_nginx_cache_queue';

    public function __construct(
        private PurgeRequestMerger $merger,
        private OptionMutex $mutex,
    ) {
    }

    public function push(PurgeRequest $request): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $this->mutex->synchronized(
            self::OPTION_QUEUE,
            function () use ($request): void {
                $merged = $this->merger->merge([...$this->all(), $request]);
                if (!$this->persist($merged)) {
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

        return array_values(
            array_map(
                static fn (array $item): PurgeRequest => PurgeRequest::fromArray($item),
                array_filter($queue, static fn (mixed $item): bool => is_array($item)),
            ),
        );
    }

    /** @return list<PurgeRequest> */
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

    /** @param callable(PurgeRequest): bool $purge */
    public function process(callable $purge): bool
    {
        return $this->mutex->synchronized(self::OPTION_QUEUE . '.process', function () use ($purge): bool {
            $snapshot = $this->mutex->synchronized(self::OPTION_QUEUE, fn (): array => [$this->all(), $this->generation()]);
            foreach ($snapshot[0] as $request) {
                if (!$purge($request)) {
                    return false;
                }
                $acknowledged = $this->mutex->synchronized(self::OPTION_QUEUE, function () use ($request, $snapshot): bool {
                    $pending = $this->all();
                    if ($this->generation() !== $snapshot[1]) {
                        return true;
                    }
                    foreach ($pending as $index => $current) {
                        if ($current->toArray() === $request->toArray()) {
                            unset($pending[$index]);
                            return $this->persist(array_values($pending));
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

    private function generation(): ?string
    {
        $queue = get_option(self::OPTION_QUEUE, []);
        return is_array($queue) && is_string($queue[0]['_generation'] ?? null) ? $queue[0]['_generation'] : null;
    }

    /** @param list<PurgeRequest> $requests */
    private function persist(array $requests): bool
    {
        if (!function_exists('update_option')) {
            return false;
        }

        $generation = bin2hex(random_bytes(16));
        $payload = array_map(static fn (PurgeRequest $item): array => [...$item->toArray(), '_generation' => $generation], $requests);

        return update_option(self::OPTION_QUEUE, $payload, false) || get_option(self::OPTION_QUEUE) === $payload;
    }
}
