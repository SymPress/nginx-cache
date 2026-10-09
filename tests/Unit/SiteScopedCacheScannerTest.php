<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Purge\CachePurger;
use SymPress\NginxCache\Purge\FullPurgeEndpointDispatcher;
use SymPress\NginxCache\Purge\SiteScopedCacheScanner;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\SiteKeyMatcher;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;

final class SiteScopedCacheScannerTest extends TestCase
{
    public function testSharedRootPurgeKeepsOtherSitesAndUnknownKeysAndDryRunHasNoWrites(): void
    {
        $fs = new Filesystem();
        $root = sys_get_temp_dir() . '/sympress-site-scan-' . bin2hex(random_bytes(8));
        $contents = [
            'a' => "\0binary\nKEY: https|GET|example.test|/shop/product/\n",
            'b' => "\0binary\nKEY: https|GET|example.test|/shop/child/page/\n",
            'c' => "\0binary\nKEY: https|GET|example.test|/\n",
            'd' => "\0binary\nKEY: https|GET|mapped.test|/product/\n",
            'e' => "\0binary\nKEY: unsupported-key\n",
        ];
        foreach ($contents as $name => $body) {
            $fs->dumpFile($root . '/a/b/' . str_repeat($name, 32), $body);
        }
        $matcher = new SiteKeyMatcher(['example.test' => '/shop/', 'mapped.test' => '/'], ['example.test' => ['/', '/shop/', '/shop/child/']]);
        $settings = new WordPressCacheSettings($root);
        $clock = new CacheClock(new MockClock('2026-10-09 12:00:00'));
        $purger = new CachePurger($fs, new CachePathValidator($fs), new CacheFileResolver($settings, new CacheKeyStrategy()), new FullPurgeEndpointDispatcher(new MockHttpClient(), $settings, new UrlPolicy(), $clock), $clock);
        try {
            $before = $this->snapshot($root);
            $dry = $purger->purgeSite($root, PurgeRequest::full(dryRun: true), $matcher);
            self::assertTrue($dry->successful, $dry->message);
            self::assertSame(2, $dry->removedEntries);
            self::assertSame(1, $dry->unmatched);
            self::assertSame($before, $this->snapshot($root));
            $real = $purger->purgeSite($root, PurgeRequest::full(), $matcher);
            self::assertTrue($real->successful, $real->message);
            self::assertFileDoesNotExist($root . '/a/b/' . str_repeat('a', 32));
            self::assertFileDoesNotExist($root . '/a/b/' . str_repeat('d', 32));
            foreach (['b', 'c', 'e'] as $name) {
                self::assertSame($contents[$name], file_get_contents($root . '/a/b/' . str_repeat($name, 32)));
            }
        } finally {
            $fs->remove($root);
        }
    }

    public function testBudgetCursorResumesWithoutRevisitingFilesOrEscapingRoot(): void
    {
        $fs = new Filesystem();
        $root = sys_get_temp_dir() . '/sympress-site-budget-' . bin2hex(random_bytes(8));
        foreach (['a', 'b', 'c'] as $name) {
            $fs->dumpFile($root . '/a/' . str_repeat($name, 32), "KEY: https|GET|example.test|/\n");
        }
        $scanner = new SiteScopedCacheScanner(new CacheKeyStrategy());
        $matcher = new SiteKeyMatcher(['example.test' => '/']);
        try {
            $first = $scanner->scan($root, $matcher, fileBudget: 1);
            self::assertTrue($first['partial']);
            self::assertSame('a/' . str_repeat('a', 32), $first['cursor']);
            $second = $scanner->scan($root, $matcher, $first['cursor'], fileBudget: 1);
            self::assertTrue($second['partial']);
            $third = $scanner->scan($root, $matcher, $second['cursor'], fileBudget: 1);
            self::assertFalse($third['partial']);
            self::assertSame('', $third['cursor']);
            self::assertCount(3, array_unique([...$first['entries'], ...$second['entries'], ...$third['entries']]));
            $this->expectException(\InvalidArgumentException::class);
            $scanner->scan($root, $matcher, '../escape');
        } finally {
            $fs->remove($root);
        }
    }

    /** @return array<string, array{hash: string, mtime: int|false}> */
    private function snapshot(string $root): array
    {
        $snapshot = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            $snapshot[$path] = ['hash' => (string) hash_file('sha256', $path), 'mtime' => filemtime($path)];
        }
        return $snapshot;
    }
}
