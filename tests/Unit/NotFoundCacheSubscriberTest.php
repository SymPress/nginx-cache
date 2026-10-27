<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

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

    public function testHeadersStayUntouchedOutsideAWordPress404(): void
    {
        self::assertSame(self::WORDPRESS_NOCACHE, (new NotFoundCacheSubscriber())->headers(self::WORDPRESS_NOCACHE));
    }
}
