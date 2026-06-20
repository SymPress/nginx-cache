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
                update_option(self::OPTION_QUEUE, array_map(static fn (PurgeRequest $item): array => $item->toArray(), $merged), false);
            },
        );
    }

    /** @return list<PurgeRequest> */
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
}
