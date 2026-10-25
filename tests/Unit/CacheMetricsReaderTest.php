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
        $this->filesystem->remove([$this->path, $this->path . '.1', $this->path . '.1.gz', $this->path . '.2']);
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

    public function testCopytruncateRotationKeepsCurrentRecordsInTheWindow(): void
    {
        $this->filesystem->dumpFile($this->path, str_repeat($this->record('MISS', time: $this->now - 120), 90) . str_repeat($this->record('HIT', time: $this->now - 60), 10));
        self::assertSame([100, 10], $this->counts());

        $this->filesystem->copy($this->path, $this->path . '.1');
        $before = file_get_contents($this->path);
        $this->filesystem->appendToFile($this->path, $this->record('HIT'));
        self::assertSame([101, 11], $this->counts(), 'copied bytes still present in the active log are counted once');

        $this->filesystem->dumpFile($this->path, '');
        $result = $this->reader->read($this->path, 'example.test');
        self::assertSame([100, 10], [$result['requests'], $result['hits']], 'an empty log after truncation keeps the archived records');
        self::assertFalse($result['sampled']);
        self::assertFalse($result['coverage_gap']);

        $this->filesystem->dumpFile($this->path, str_repeat($this->record('HIT'), 10));
        $result = $this->reader->read($this->path, 'example.test');
        self::assertSame([110, 20], [$result['requests'], $result['hits']]);
        self::assertEqualsWithDelta(18.1818, $result['hit_rate'], 0.0001);
        self::assertSame($before, file_get_contents($this->path . '.1'));
    }

    public function testRenamedLogsCountIdenticalRealRequestsInBothFiles(): void
    {
        $this->filesystem->dumpFile($this->path . '.1', str_repeat($this->record('HIT'), 3) . rtrim($this->record('HIT')));
        $this->filesystem->dumpFile($this->path, $this->record('HIT'));
        self::assertSame([4, 4], $this->counts(), 'equal time, host and status are not a request id');
    }

    public function testRotatedRecordsStillLeaveTheWindowAndRespectTheByteBudget(): void
    {
        $this->filesystem->dumpFile($this->path . '.1', $this->record('MISS', time: $this->now - 3601) . $this->record('MISS', time: $this->now - 30));
        $this->filesystem->dumpFile($this->path, $this->record('HIT'));
        self::assertSame([2, 1], $this->counts());

        $this->filesystem->dumpFile($this->path . '.1', str_repeat('x', 600_000) . "\n" . $this->record('MISS'));
        $this->filesystem->dumpFile($this->path, str_repeat('y', 600_000) . "\n" . $this->record('HIT'));
        $result = $this->reader->read($this->path, 'example.test');
        self::assertSame([2, 1], [$result['requests'], $result['hits']]);
        self::assertTrue($result['sampled'], 'the shared 1 MiB budget truncated the rotated log');
    }

    public function testUnreadableRecentGenerationsAreReportedAsACoverageGap(): void
    {
        $this->filesystem->dumpFile($this->path, $this->record('HIT'));
        $this->filesystem->dumpFile($this->path . '.1.gz', 'compressed');
        $result = $this->reader->read($this->path, 'example.test');
        self::assertTrue($result['coverage_gap']);
        self::assertSame(1, $result['requests']);

        $this->filesystem->touch($this->path . '.1.gz', $this->now - 3700);
        self::assertFalse($this->reader->read($this->path, 'example.test')['coverage_gap'], 'an archive older than the window cannot hold current records');

        $this->filesystem->remove($this->path . '.1.gz');
        $this->filesystem->dumpFile($this->path . '.1', $this->record('MISS'));
        $this->filesystem->dumpFile($this->path . '.2', 'x');
        self::assertTrue($this->reader->read($this->path, 'example.test')['coverage_gap']);
    }

    /** @return array{int, int} */
    private function counts(): array
    {
        $result = $this->reader->read($this->path, 'example.test');

        return [$result['requests'], $result['hits']];
    }

    private function record(string $state, string $host = 'example.test', ?int $time = null): string
    {
        return json_encode(['time' => (string) ($time ?? $this->now), 'host' => $host, 'cache' => $state], JSON_THROW_ON_ERROR) . "\n";
    }
}
