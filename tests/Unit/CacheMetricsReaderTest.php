<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Inspection\CacheMetricsReader;
use SymPress\NginxCache\Time\CacheClock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class CacheMetricsReaderTest extends TestCase
{
    private string $path;
    private Filesystem $filesystem;
    private CacheMetricsReader $reader;
    private int $now;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/sympress-cache-metrics-' . bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem();
        $clock = new CacheClock(new MockClock('2026-10-08 12:00:00 UTC'));
        $this->now = $clock->timestamp();
        $this->reader = new CacheMetricsReader($clock);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->path);
    }

    public function testRatioCountsOnlyThisHostsEligibleRequestsWithinTheWindow(): void
    {
        $data = '';
        foreach (['HIT', 'STALE', 'UPDATING', 'REVALIDATED', 'MISS', 'EXPIRED', 'BYPASS', '-', 'UNKNOWN'] as $state) {
            $data .= $this->record($state);
        }
        $data .= $this->record('MISS', 'other.test');
        $data .= $this->record('MISS', 'example.test', $this->now - 3601);
        $data .= $this->record('MISS', 'example.test', $this->now + 10);
        $data .= "invalid json\n{\"time\":[],\"host\":\"example.test\",\"cache\":\"MISS\"}\n";
        $data .= $this->record('HIT', 'EXAMPLE.TEST');
        $this->filesystem->dumpFile($this->path, $data);
        $before = hash_file('sha256', $this->path);
        $result = $this->reader->read($this->path, 'example.test');

        self::assertSame(7, $result['requests']);
        self::assertSame(5, $result['hits']);
        self::assertSame(['HIT' => 2, 'MISS' => 1, 'EXPIRED' => 1, 'STALE' => 1, 'UPDATING' => 1, 'REVALIDATED' => 1], $result['states']);
        self::assertTrue($result['small_sample']);
        self::assertSame($this->now, $result['measured_at']);
        self::assertEqualsWithDelta(71.428571, $result['hit_rate'], 0.00001);
        self::assertFalse($result['sampled']);
        self::assertSame('ok', $result['log_state']);
        self::assertSame($before, hash_file('sha256', $this->path));
    }

    public function testMissingEmptyOrNonEligibleLogsDoNotReportZeroOrPerfectHits(): void
    {
        self::assertNull($this->reader->read($this->path, 'example.test')['hit_rate']);
        self::assertSame('missing', $this->reader->read($this->path, 'example.test')['log_state']);
        $this->filesystem->dumpFile($this->path, '');
        self::assertNull($this->reader->read($this->path, 'example.test')['hit_rate']);
        self::assertSame('no_requests', $this->reader->read($this->path, 'example.test')['log_state']);
        $this->filesystem->dumpFile($this->path, $this->record('BYPASS'));
        self::assertNull($this->reader->read($this->path, 'example.test')['hit_rate']);
        self::assertSame('no_requests', $this->reader->read($this->path, 'example.test')['log_state']);
        self::assertNull($this->reader->read($this->path, '')['hit_rate']);
    }

    public function testRealMissesReportZeroAndPartialWriterRecordsAreIgnored(): void
    {
        $this->filesystem->dumpFile($this->path, $this->record('MISS') . rtrim($this->record('HIT')));
        $result = $this->reader->read($this->path, 'example.test');
        self::assertSame(1, $result['requests']);
        self::assertSame(0.0, $result['hit_rate']);
    }

    public function testLineBudgetUsesRecentRecordsAndLabelsTheSample(): void
    {
        $this->filesystem->dumpFile($this->path, $this->record('MISS') . str_repeat($this->record('HIT'), 5000));
        $result = $this->reader->read($this->path, 'example.test');
        self::assertSame(5000, $result['requests']);
        self::assertSame(100.0, $result['hit_rate']);
        self::assertTrue($result['sampled']);
        self::assertFalse($result['small_sample']);
    }

    public function testByteBudgetIgnoresThePartialFirstLine(): void
    {
        $this->filesystem->dumpFile($this->path, str_repeat('x', 1_100_000) . "\n" . $this->record('HIT') . $this->record('MISS'));
        $result = $this->reader->read($this->path, 'example.test');
        self::assertSame(2, $result['requests']);
        self::assertSame(50.0, $result['hit_rate']);
        self::assertTrue($result['sampled']);
    }

    public function testUnreadableLogsRemainUnavailableWithoutPhpWarnings(): void
    {
        $this->filesystem->dumpFile($this->path, $this->record('HIT'));
        $this->filesystem->chmod($this->path, 0000);
        self::assertFalse(is_readable($this->path), 'Run this test as an unprivileged user.');
        $error = error_get_last();
        self::assertNull($this->reader->read($this->path, 'example.test')['hit_rate']);
        self::assertSame('unreadable', $this->reader->read($this->path, 'example.test')['log_state']);
        self::assertSame($error, error_get_last());
    }

    private function record(string $state, string $host = 'example.test', ?int $time = null): string
    {
        return json_encode(['time' => (string) ($time ?? $this->now), 'host' => $host, 'cache' => $state], JSON_THROW_ON_ERROR) . "\n";
    }
}
