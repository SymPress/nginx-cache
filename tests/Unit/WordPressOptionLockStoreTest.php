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
    public function testExpiredOwnerCannotExtendItsLease(): void
    {
        $clock = new MockClock('2026-10-01');
        $store = new WordPressOptionLockStore(new CacheClock($clock));
        $key = new \Symfony\Component\Lock\Key('expired-owner');
        $store->save($key);
        $clock->sleep(31);
        $this->expectException(\Symfony\Component\Lock\Exception\LockConflictedException::class);
        $store->putOffExpiration($key, 60);
    }

}
