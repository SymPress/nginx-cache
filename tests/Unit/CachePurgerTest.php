<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Purge\CachePurger;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeRequest;
use Symfony\Component\Filesystem\Filesystem;

final class CachePurgerTest extends TestCase
{
    public function testItRemovesCacheContentsButKeepsCacheDirectory(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-' . bin2hex(random_bytes(8));
        $filesystem->mkdir([$path . '/a/b', $path . '/c']);
        file_put_contents($path . '/a/b/' . str_repeat('a', 32), 'cached');
        file_put_contents($path . '/c/' . str_repeat('b', 32), 'cached');

        try {
            $purger = new CachePurger(
                $filesystem,
                new CachePathValidator($filesystem),
                new CacheFileResolver(new WordPressCacheSettings($path), new CacheKeyStrategy()),
            );
            $result = $purger->purge($path);

            self::assertTrue($result->successful);
            self::assertDirectoryExists($path);
            self::assertFileExists($path . '/.sympress-nginx-cache.lock');
            self::assertDirectoryDoesNotExist($path . '/a');
            self::assertDirectoryDoesNotExist($path . '/c');
        } finally {
            $filesystem->remove($path);
        }
    }

    public function testItCanDryRunWithoutRemovingEntries(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-dry-run-' . bin2hex(random_bytes(8));
        $filesystem->mkdir($path . '/a/b');
        file_put_contents($path . '/a/b/' . str_repeat('a', 32), 'cached');

        try {
            $purger = new CachePurger(
                $filesystem,
                new CachePathValidator($filesystem),
                new CacheFileResolver(new WordPressCacheSettings($path), new CacheKeyStrategy()),
            );
            $result = $purger->purgeRequest($path, PurgeRequest::full(dryRun: true));

            self::assertTrue($result->successful);
            self::assertTrue($result->dryRun);
            self::assertDirectoryExists($path . '/a');
        } finally {
            $filesystem->remove($path);
        }
    }
}
