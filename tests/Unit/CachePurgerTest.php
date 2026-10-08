<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Purge\CachePurger;
use SymPress\NginxCache\Purge\FullPurgeEndpointDispatcher;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;

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
            $purger = $this->purger($filesystem, $path);
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
            $purger = $this->purger($filesystem, $path);
            $result = $purger->purgeRequest($path, PurgeRequest::full(dryRun: true));

            self::assertTrue($result->successful);
            self::assertTrue($result->dryRun);
            self::assertDirectoryExists($path . '/a');
            self::assertFileDoesNotExist($path . '/.sympress-nginx-cache.lock');
            self::assertFileDoesNotExist($path . '/' . CachePathValidator::SENTINEL_FILE);
            self::assertSame('cached', file_get_contents($path . '/a/b/' . str_repeat('a', 32)));
        } finally {
            $filesystem->remove($path);
        }
    }

    public function testDryRunAcceptsExpiredCacheWithOnlyEmptyHashDirectories(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-expired-' . bin2hex(random_bytes(8));
        $filesystem->mkdir([$path . '/7/81', $path . '/e/9b']);
        try {
            $result = $this->purger($filesystem, $path)->purgeRequest($path, PurgeRequest::full(dryRun: true));
            self::assertTrue($result->successful, $result->message);
            self::assertSame(2, $result->removedEntries);
            self::assertDirectoryExists($path . '/7/81');
            self::assertDirectoryExists($path . '/e/9b');
            self::assertFileDoesNotExist($path . '/.sympress-nginx-cache.lock');
            self::assertFileDoesNotExist($path . '/' . CachePathValidator::SENTINEL_FILE);
        } finally {
            $filesystem->remove($path);
        }
    }

    public function testDryRunDoesNotCreateMissingCacheRoots(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-missing-' . bin2hex(random_bytes(8));
        $result = $this->purger($filesystem, $path)->purgeRequest($path, PurgeRequest::full(dryRun: true));
        self::assertFalse($result->successful);
        self::assertSame('Cache directory does not exist.', $result->message);
        self::assertDirectoryDoesNotExist($path);
    }

    public function testSelectiveDryRunDoesNotCreateLockOrRemoveMatchingFile(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-url-preview-' . bin2hex(random_bytes(8));
        $settings = new WordPressCacheSettings($path);
        $resolver = new CacheFileResolver($settings, new CacheKeyStrategy());
        $candidate = $resolver->candidates($path, 'https://example.test/')[0];
        $filesystem->dumpFile($candidate, 'cached');
        try {
            $result = $this->purger($filesystem, $path)->purgeRequest($path, PurgeRequest::urls(['https://example.test/'], dryRun: true));
            self::assertTrue($result->successful, $result->message);
            self::assertSame(1, $result->removedEntries);
            self::assertFileExists($candidate);
            self::assertFileDoesNotExist($path . '/.sympress-nginx-cache.lock');
        } finally {
            $filesystem->remove($path);
        }
    }

    public function testContendedFilesystemLockReturnsImmediatelyAndRetainsCacheFiles(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-contended-' . bin2hex(random_bytes(8));
        $filesystem->mkdir($path . '/a/b');
        $cache = $path . '/a/b/' . str_repeat('a', 32);
        file_put_contents($cache, 'cached');
        $lock = fopen($path . '/.sympress-nginx-cache.lock', 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $started = microtime(true);
            $result = $this->purger($filesystem, $path)->purgeRequest($path, PurgeRequest::full(dryRun: true));
            self::assertFalse($result->successful);
            self::assertLessThan(0.5, microtime(true) - $started);
            self::assertFileExists($cache);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            $filesystem->remove($path);
        }
    }

    private function purger(Filesystem $filesystem, string $path): CachePurger
    {
        $settings = new WordPressCacheSettings($path);
        $clock = new CacheClock(new MockClock('2026-06-20 12:00:00'));

        return new CachePurger(
            $filesystem,
            new CachePathValidator($filesystem),
            new CacheFileResolver($settings, new CacheKeyStrategy()),
            new FullPurgeEndpointDispatcher(new MockHttpClient(), $settings, new UrlPolicy(), $clock),
            $clock,
        );
    }
}
