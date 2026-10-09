<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\PurgeRequestMerger;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeScope;
use SymPress\NginxCache\Value\SiteKeyMatcher;

final class SiteKeyMatcherTest extends TestCase
{
    public function testLongestSitePathWinsIncludingEncodedSeparators(): void
    {
        $main = new SiteKeyMatcher(['example.test' => '/'], ['example.test' => ['/', '/shop/', '/shop/news/']]);
        $shop = new SiteKeyMatcher(['example.test' => '/shop/'], ['example.test' => ['/', '/shop/', '/shop/news/']]);
        self::assertTrue($main->matches('EXAMPLE.test', '/article/?utm_source=a'));
        self::assertFalse($main->matches('example.test', '/shop/item/'));
        self::assertFalse($main->matches('example.test', '/shop%2Fitem/'));
        self::assertTrue($shop->matches('example.test', '/shop'));
        self::assertTrue($shop->matches('example.test', '/shop/item/'));
        self::assertFalse($shop->matches('example.test', '/shopping/'));
        self::assertFalse($shop->matches('example.test', '/shop/news/article/'));
        self::assertFalse($shop->matches('other.test', '/shop/'));
    }

    public function testMappedDomainAndConfiguredKeyParsing(): void
    {
        $matcher = new SiteKeyMatcher(['mapped.test' => '/', 'network.test' => '/shop/']);
        self::assertTrue($matcher->matches('mapped.test', '/product/'));
        self::assertFalse($matcher->matches('network.test', '/product/'));
        $keys = new CacheKeyStrategy();
        self::assertSame(['scheme' => 'https', 'method' => 'GET', 'host' => 'mapped.test', 'uri' => '/product/?x=1'], $keys->parseKey('https|GET|mapped.test|/product/?x=1'));
        self::assertNull($keys->parseKey('unrecognized cache key'));
        self::assertNull($keys->parseKey('https|POST|mapped.test|/private/'));
    }

    public function testScopeSurvivesQueueRoundTripAndOverflow(): void
    {
        self::assertSame(PurgeScope::Site, PurgeRequest::fromArray(['mode' => 'full'])->scope);
        $network = PurgeRequest::full(scope: PurgeScope::Network);
        self::assertSame(PurgeScope::Network, PurgeRequest::fromArray($network->toArray())->asDryRun()->withPrewarm()->scope);
        $merged = (new PurgeRequestMerger())->merge([PurgeRequest::full(), $network]);
        self::assertCount(2, $merged);
        self::assertSame(PurgeScope::Site, $merged[0]->scope);
        self::assertSame(PurgeScope::Network, $merged[1]->scope);
        $urls = array_map(static fn (int $id): string => 'https://example.test/' . $id, range(0, 501));
        self::assertSame(PurgeScope::Site, (new PurgeRequestMerger())->merge([PurgeRequest::urls($urls)])[0]->scope);
    }
}
