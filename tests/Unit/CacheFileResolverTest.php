<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final class CacheFileResolverTest extends TestCase
{
    public function testItBuildsKeysMatchingGeneratedFastcgiTemplate(): void
    {
        $resolver = new CacheFileResolver(
            new WordPressCacheSettings('/var/cache/nginx/wordpress'),
            new CacheKeyStrategy(),
        );

        $keys = array_column($resolver->keyCandidates('https://example.test/path?x=1'), 'key');

        self::assertContains('https|GET|example.test|/path?x=1', $keys);
        self::assertContains('https|HEAD|example.test|/path?x=1', $keys);
        self::assertNotContains('https', $keys);
    }

    public function testItResolvesCacheFileForPrimaryKey(): void
    {
        $resolver = new CacheFileResolver(
            new WordPressCacheSettings('/var/cache/nginx/wordpress'),
            new CacheKeyStrategy(),
        );
        $hash = md5('https|GET|example.test|/');

        self::assertContains(
            sprintf('/var/cache/nginx/wordpress/%s/%s/%s', substr($hash, -1), substr($hash, -3, 2), $hash),
            $resolver->candidates('/var/cache/nginx/wordpress', 'https://example.test/'),
        );
    }
}
