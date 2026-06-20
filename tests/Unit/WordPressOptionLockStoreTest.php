<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Support\WordPressOptionLockStore;
use SymPress\NginxCache\Time\CacheClock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;

final class WordPressOptionLockStoreTest extends TestCase
{
    public function testItCoordinatesLocksThroughSymfonyLockFactory(): void
    {
        $factory = new LockFactory(new WordPressOptionLockStore(
            new CacheClock(new MockClock('2026-06-20 12:00:00')),
        ));

        $first = $factory->createLock('sympress-nginx-cache-test');
        $second = $factory->createLock('sympress-nginx-cache-test');

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());

        $first->release();

        self::assertTrue($second->acquire());

        $second->release();
    }
}
