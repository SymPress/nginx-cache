<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Inspection\CacheStatusInspector;
use Symfony\Component\Filesystem\Filesystem;

final class CacheStatusInspectorTest extends TestCase
{
    public function testFileAndByteKpisExcludeInternalLockAndSentinel(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-cache-status-' . bin2hex(random_bytes(8));
        $filesystem->dumpFile($path . '/a/b/' . str_repeat('a', 32), '12345');
        $filesystem->dumpFile($path . '/a/b/' . str_repeat('b', 32), '1234567');
        $filesystem->dumpFile($path . '/.sympress-nginx-cache.lock', 'internal lock');
        $filesystem->dumpFile($path . '/' . CachePathValidator::SENTINEL_FILE, 'internal sentinel');
        try {
            $status = (new CacheStatusInspector())->inspect($path);
            self::assertTrue($status->available());
            self::assertTrue($status->scanComplete);
            self::assertSame(2, $status->files);
            self::assertSame(12, $status->bytes);
            self::assertSame('12 B', $status->formattedSize());
            self::assertFalse((new CacheStatusInspector(1))->inspect($path)->scanComplete);
        } finally {
            $filesystem->remove($path);
        }
    }
}
