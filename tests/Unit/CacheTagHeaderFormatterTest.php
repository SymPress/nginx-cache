<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\CacheTagHeaderFormatter;
use SymPress\NginxCache\Surrogate\CacheTagResolver;

final class CacheTagHeaderFormatterTest extends TestCase
{
    public function testItCompactsLargeTagHeaders(): void
    {
        $formatter = new CacheTagHeaderFormatter(new CacheTagResolver(), new WordPressCacheSettings('/tmp/cache'));
        $tags = [];

        for ($i = 1; $i <= 7000; ++$i) {
            $tags[] = sprintf('post:%d', $i);
        }

        $compacted = $formatter->tags($tags);

        self::assertContains('post:huge', $compacted);
        self::assertLessThanOrEqual(32512, strlen($formatter->value($tags)));
    }

    public function testItFormatsCloudflareCacheTagsWithCommas(): void
    {
        $formatter = new CacheTagHeaderFormatter(new CacheTagResolver(), new WordPressCacheSettings('/tmp/cache'));

        self::assertSame('post:1,term:2', $formatter->cloudflareValue(['post:1', 'term:2']));
    }

    public function testItCompactsCloudflareCacheTagsAtCloudflareHeaderLimit(): void
    {
        $formatter = new CacheTagHeaderFormatter(new CacheTagResolver(), new WordPressCacheSettings('/tmp/cache'));
        $tags = [];

        for ($i = 1; $i <= 7000; ++$i) {
            $tags[] = sprintf('post:%d', $i);
        }

        $value = $formatter->cloudflareValue($tags);

        self::assertStringContainsString('post:huge', $value);
        self::assertLessThanOrEqual(16000, strlen($value));
    }
}
