<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Inspection\CacheProbe;
use SymPress\NginxCache\Inspection\PageCoverageInspector;
use SymPress\NginxCache\Inspection\ResponseCacheability;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\CacheStatus;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;

final class PageCoverageInspectorTest extends TestCase
{
    private string $path;
    private Filesystem $filesystem;
    private CacheFileResolver $files;
    private PageCoverageInspector $inspector;
    private int $now;
    private int $requests = 0;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/sympress-page-coverage-' . bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->path);
        $settings = new WordPressCacheSettings($this->path);
        $keys = new CacheKeyStrategy();
        $clock = new CacheClock(new MockClock('2026-10-08 12:00:00 UTC'));
        $this->now = $clock->timestamp();
        $this->files = new CacheFileResolver($settings, $keys);
        $http = new MockHttpClient(function (): never {
            ++$this->requests;
            throw new \LogicException('Coverage must not send HTTP requests.');
        });
        $probe = new CacheProbe($http, $settings, $this->files, new BypassRuleProvider($settings), new UrlPolicy(), $keys, new ResponseCacheability(), $clock);
        $this->inspector = new PageCoverageInspector($settings, $this->files, $probe, $clock);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->path);
    }

    public function testPagesAreClassifiedByTheirCacheFilesWithoutHttpRequests(): void
    {
        $this->entry('https://example.test/', $this->now + 600);
        // A second key variant of the same URL must not create a second page.
        $this->entry('https://example.test/', $this->now + 600, 1);
        $this->entry('https://example.test/de/', $this->now + 600);
        $this->entry('https://example.test/old/', $this->now - 1);
        $this->entry('https://example.test/broken/', null);
        $urls = ['https://example.test/', 'https://example.test/de/', 'https://example.test/old/', 'https://example.test/broken/', 'https://example.test/cold/', 'https://example.test/wp-admin/', 'https://example.test/?p=3'];
        $before = $this->snapshot();

        $result = $this->inspector->classify($this->path, $urls, 3);

        self::assertSame(['state' => 'ok', 'total' => 10, 'checked' => 7, 'cached' => 2, 'expired' => 1, 'missing' => 1, 'excluded' => 2, 'unknown' => 1, 'measured_at' => $this->now], $result);
        self::assertSame(0, $this->requests);
        self::assertSame($before, $this->snapshot(), 'coverage inspection is read-only');
    }

    public function testUnavailableCacheIsNotReportedAsZeroPages(): void
    {
        $result = $this->inspector->inspect(new CacheStatus($this->path . '/missing', false, false, false, 0, 0, 0, true));

        self::assertSame('unavailable', $result['state']);
        self::assertSame(0, $result['missing']);
    }

    private function entry(string $url, ?int $validUntil, int $candidate = 0): void
    {
        $file = $this->files->candidates($this->path, $url)[$candidate * 2];
        $header = $validUntil === null ? 'not an nginx cache header' : pack('PP', 5, $validUntil) . str_repeat("\0", 32);
        $this->filesystem->dumpFile($file, $header . "\nKEY: test\n");
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[(string) $file] = (string) hash_file('sha256', (string) $file);
        }
        ksort($files);

        return $files;
    }
}
