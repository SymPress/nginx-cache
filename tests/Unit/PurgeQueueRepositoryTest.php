<?php

declare(strict_types=1);

namespace {
    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['sympress_nginx_cache_test_options'][$name] ?? $default;
        }
    }
    if (!function_exists('update_option')) {
        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            $GLOBALS['sympress_nginx_cache_test_options'][$name] = $value;

            return true;
        }
    }
}

namespace SymPress\NginxCache\Tests\Unit {
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use SymPress\NginxCache\Purge\PurgeQueueRepository;
    use SymPress\NginxCache\Purge\PurgeRequestMerger;
    use SymPress\NginxCache\Support\OptionMutex;
    use SymPress\NginxCache\Time\CacheClock;
    use SymPress\NginxCache\Value\PurgeRequest;
    use Symfony\Component\Clock\MockClock;
    use Symfony\Component\Lock\LockFactory;
    use Symfony\Component\Lock\Store\InMemoryStore;

    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class PurgeQueueRepositoryTest extends TestCase
    {
        public function testDelegatedPushBeyondTheCapIsRejectedWithoutQueueing(): void
        {
            $queue = new PurgeQueueRepository(new PurgeRequestMerger(), new OptionMutex(new LockFactory(new InMemoryStore())), new CacheClock(new MockClock('2026-11-16 12:00:00')));
            $urls = static fn (int $offset, int $count): array => array_map(static fn (int $id): string => 'https://example.test/?x=' . $id, range($offset, $offset + $count - 1));
            $queue->push(PurgeRequest::urls($urls(0, 10_000), allowFullPurge: false));

            try {
                $queue->push(PurgeRequest::urls($urls(10_000, 1), allowFullPurge: false));
                self::fail('A delegated push beyond the cap must be rejected.');
            } catch (\OverflowException) {
            }
            self::assertCount(10_000, $queue->all()[0]->urls);

            $queue->push(PurgeRequest::urls($urls(10_000, 1)));
            $queued = $queue->all();
            self::assertCount(2, $queued, 'An automatic push must not escalate the delegated URLs into a full purge.');
            self::assertSame($urls(10_000, 1), $queued[0]->urls);
            self::assertCount(10_000, $queued[1]->urls);
        }

        public function testPrivilegedQueuedUrlsDoNotCountTowardsTheDelegatedCap(): void
        {
            $queue = new PurgeQueueRepository(new PurgeRequestMerger(), new OptionMutex(new LockFactory(new InMemoryStore())), new CacheClock(new MockClock('2026-11-16 12:00:00')));
            $urls = static fn (int $offset, int $count): array => array_map(static fn (int $id): string => 'https://example.test/?x=' . $id, range($offset, $offset + $count - 1));
            $queue->push(PurgeRequest::urls($urls(0, PurgeRequestMerger::MAX_URLS)));
            $queue->push(PurgeRequest::urls($urls(PurgeRequestMerger::MAX_URLS, 9_900), allowFullPurge: false));

            self::assertCount(9_900, $queue->all()[1]->urls);
        }
    }
}
