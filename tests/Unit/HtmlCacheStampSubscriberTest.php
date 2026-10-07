<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Hook\HtmlCacheStampSubscriber;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use Symfony\Component\Clock\MockClock;

final class HtmlCacheStampSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['sympress_nginx_cache_test_options']);
    }

    public function testStampIsOptInAndNeverAppendedToJsonXmlOrPrivateResponses(): void
    {
        $stamp = new HtmlCacheStampSubscriber(new CompatibilitySettings(new WordPressCacheSettings('/unused')), new CacheClock(new MockClock('2026-10-07 12:00:00 UTC')));
        self::assertSame('', $stamp->comment(['Content-Type: text/html'], true, 4, 0.25));
        $GLOBALS['sympress_nginx_cache_test_options'][CompatibilitySettings::PREFIX . 'html_stamp'] = 1;
        self::assertStringContainsString('2026-10-07 12:00:00 UTC; 4 queries in 0.250 seconds.', $stamp->comment(['Content-Type: text/html; charset=UTF-8'], true, 4, 0.25));
        self::assertSame('', $stamp->comment(['Content-Type: application/json'], true, 4, 0.25));
        self::assertSame('', $stamp->comment(['Content-Type: text/xml'], true, 4, 0.25));
        self::assertSame('', $stamp->comment(['Content-Type: text/html'], false, 4, 0.25));
    }
}
