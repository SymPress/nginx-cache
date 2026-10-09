<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\RedisPageCachePurger;
use SymPress\NginxCache\Purge\RedisPageCacheStore;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\SiteKeyMatcher;
use Symfony\Component\Clock\MockClock;

final class RedisPageCachePurgerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['sympress_nginx_cache_test_options']);
    }

    private function purger(RedisPageCacheStore $store): RedisPageCachePurger
    {
        return new RedisPageCachePurger(new CompatibilitySettings(new WordPressCacheSettings('/unused')), $store, new UrlPolicy(), new CacheKeyStrategy(), new CacheClock(new MockClock()));
    }

    public function testDryRunDoesNotConnectAndInvalidPrefixFailsClosed(): void
    {
        $store = $this->createMock(RedisPageCacheStore::class);
        $store->expects(self::never())->method('scan');
        $store->expects(self::never())->method('delete');
        self::assertTrue($this->purger($store)->purge(PurgeRequest::full(dryRun: true))->successful);
        $GLOBALS['sympress_nginx_cache_test_options'][CompatibilitySettings::PREFIX . 'redis_prefix'] = '*';
        self::assertFalse($this->purger($store)->purge(PurgeRequest::full())->successful);
    }

    public function testFullPurgeOnlyDeletesItsPrefixAndScansUntilComplete(): void
    {
        $store = $this->createMock(RedisPageCacheStore::class);
        $store->expects(self::exactly(2))->method('scan')->willReturnOnConsecutiveCalls(['9', ['nginx-cache:a', 'object-cache:other']], ['0', ['nginx-cache:b']]);
        $store->expects(self::exactly(2))->method('delete')->willReturnCallback(static function (array $keys): int {
            foreach ($keys as $key) {
                self::assertStringStartsWith('nginx-cache:', $key);
            }
            return count($keys);
        });
        self::assertSame(2, $this->purger($store)->purge(PurgeRequest::full())->removedEntries);
    }

    public function testSelectivePurgeIncludesHelperKeysAndRejectsForeignOrigins(): void
    {
        $store = $this->createMock(RedisPageCacheStore::class);
        $store->expects(self::once())->method('delete')->willReturnCallback(static function (array $keys): int {
            self::assertContains('nginx-cache:httpsGETexample.test/post/', $keys);
            self::assertContains('nginx-cache:https|GET|example.test|/post/', $keys);
            return 1;
        });
        self::assertTrue($this->purger($store)->purge(PurgeRequest::urls(['https://example.test/post/']))->successful);
        self::assertFalse($this->purger($store)->purge(PurgeRequest::urls(['https://foreign.test/post/']))->successful);
    }

    public function testSiteScanParsesKeysAndKeepsNestedSitesAndUnknownKeys(): void
    {
        $store = $this->createMock(RedisPageCacheStore::class);
        $store->expects(self::exactly(8))->method('scan')->willReturn(['0', [
            'nginx-cache:https|GET|example.test|/shop/product/',
            'nginx-cache:httpsGETexample.test/shop/item/',
            'nginx-cache:httpsGETexample.test/shop/child/',
            'nginx-cache:httpsGETexample.test/',
            'nginx-cache:unknown',
            'object-cache:other',
        ]]);
        $store->expects(self::exactly(8))->method('delete')->willReturnCallback(static function (array $keys): int {
            self::assertSame(['nginx-cache:https|GET|example.test|/shop/product/', 'nginx-cache:httpsGETexample.test/shop/item/'], $keys);
            return count($keys);
        });
        $matcher = new SiteKeyMatcher(['example.test' => '/shop/'], ['example.test' => ['/', '/shop/', '/shop/child/']]);
        self::assertTrue($this->purger($store)->purgeSite(PurgeRequest::full(), $matcher)->successful);
    }
}
