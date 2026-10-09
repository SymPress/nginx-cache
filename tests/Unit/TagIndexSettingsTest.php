<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Settings\TagIndexSettings;

final class TagIndexSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['sympress_nginx_cache_test_options']);
    }

    public function testDefaultsBoundsAndInactiveTtlResolveThroughTheSharedOptionSource(): void
    {
        $limits = new TagIndexSettings();
        self::assertSame(50, $limits->urlsPerTag());
        self::assertSame(1000, $limits->maxTags());
        self::assertSame(3600, $limits->ttl());
        $GLOBALS['sympress_nginx_cache_test_options'] = ['sympress_nginx_cache_tag_urls_per_tag' => 99999, 'sympress_nginx_cache_tag_max_tags' => 999999, 'sympress_nginx_cache_tag_ttl_seconds' => 0];
        self::assertSame(5000, $limits->urlsPerTag());
        self::assertSame(200000, $limits->maxTags());
        self::assertSame(1, $limits->ttl());
        $GLOBALS['sympress_nginx_cache_test_options'] = ['sympress_nginx_cache_inactive_seconds' => 7200];
        self::assertSame(7200, $limits->ttl());
    }
}
