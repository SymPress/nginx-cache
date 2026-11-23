<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Hook\NotFoundCacheSubscriber;

final class NotFoundCacheSubscriberTest extends TestCase
{
    private const array WORDPRESS_NOCACHE = [
        'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
        'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private',
    ];

    public function testAnonymousNotFoundMatchesTheGeneratedOneMinuteNginxRule(): void
    {
        $headers = (new NotFoundCacheSubscriber())->cacheable([...self::WORDPRESS_NOCACHE, 'X-Other' => 'kept']);

        self::assertSame(['Cache-Control' => 'public, max-age=60', 'X-Other' => 'kept'], $headers);
        self::assertStringNotContainsString('private', $headers['Cache-Control']);
        self::assertStringNotContainsString('no-store', $headers['Cache-Control']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNotFoundWithCookiesKeepsTheWordPressNoCacheHeaders(): void
    {
        require __DIR__ . '/Fixtures/anonymous-404-functions.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $subscriber = new NotFoundCacheSubscriber();

        self::assertSame('public, max-age=60', $subscriber->headers(self::WORDPRESS_NOCACHE)['Cache-Control']);

        $_SERVER['HTTP_COOKIE'] = 'woocommerce_items_in_cart=1';
        self::assertSame(self::WORDPRESS_NOCACHE, $subscriber->headers(self::WORDPRESS_NOCACHE));
    }

    public function testHeadersStayUntouchedOutsideAWordPress404(): void
    {
        self::assertSame(self::WORDPRESS_NOCACHE, (new NotFoundCacheSubscriber())->headers(self::WORDPRESS_NOCACHE));
    }
}
