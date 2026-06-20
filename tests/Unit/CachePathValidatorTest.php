<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use Symfony\Component\Filesystem\Filesystem;

final class CachePathValidatorTest extends TestCase
{
    public function testItRejectsRelativePaths(): void
    {
        $validator = new CachePathValidator(new Filesystem());
        $result = $validator->validate('var/cache/nginx');

        self::assertFalse($result->isValid());
        self::assertSame('Cache path must be absolute.', $result->firstError());
    }

    public function testItCanCreateMissingAbsolutePath(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-validator-' . bin2hex(random_bytes(8));

        try {
            $validator = new CachePathValidator($filesystem);
            $result = $validator->validate($path, true);

            self::assertTrue($result->isValid());
            self::assertDirectoryExists($path);
        } finally {
            $filesystem->remove($path);
        }
    }

    public function testItRejectsProjectChildren(): void
    {
        $cwd = getcwd();

        self::assertIsString($cwd);

        $validator = new CachePathValidator(new Filesystem());
        $result = $validator->validate($cwd . '/public/wp-content/uploads/cache');

        self::assertFalse($result->isValid());
        self::assertSame('Cache path points to a protected project or system directory.', $result->firstError());
    }

    public function testDestructiveValidationRejectsNonCacheDirectoryWithoutSentinel(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-unsafe-' . bin2hex(random_bytes(8));
        $filesystem->mkdir($path);
        file_put_contents($path . '/image.jpg', 'not a cache entry');

        try {
            $validator = new CachePathValidator($filesystem);
            $result = $validator->validate($path, false, true);

            self::assertFalse($result->isValid());
            self::assertStringContainsString('not marked as a managed Nginx cache root', (string) $result->firstError());
        } finally {
            $filesystem->remove($path);
        }
    }

    public function testDestructiveValidationAcceptsSentinelDirectory(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-safe-' . bin2hex(random_bytes(8));
        $filesystem->mkdir($path);
        file_put_contents($path . '/' . CachePathValidator::SENTINEL_FILE, '');

        try {
            $validator = new CachePathValidator($filesystem);
            $result = $validator->validate($path, false, true);

            self::assertTrue($result->isValid());
        } finally {
            $filesystem->remove($path);
        }
    }
}
