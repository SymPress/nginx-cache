<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Inspection;

use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Value\CacheStatus;
use Symfony\Component\Finder\Finder;

final readonly class CacheStatusInspector
{
    public function __construct(
        private int $scanLimit = 5000,
    ) {
    }

    public function inspect(string $path): CacheStatus
    {
        $path = $this->normalizePath($path);

        if (!file_exists($path)) {
            return new CacheStatus($path, false, false, false, 0, 0, 0, true);
        }

        if (!is_dir($path)) {
            return new CacheStatus($path, true, false, false, 0, 0, 0, true);
        }

        try {
            [$files, $directories, $bytes, $complete] = $this->scan($path);

            return new CacheStatus(
                $path,
                true,
                true,
                is_writable($path),
                $files,
                $directories,
                $bytes,
                $complete,
            );
        } catch (\Throwable $exception) {
            return new CacheStatus(
                $path,
                true,
                true,
                is_writable($path),
                0,
                0,
                0,
                false,
                $exception->getMessage(),
            );
        }
    }

    /** @return array{0: int, 1: int, 2: int, 3: bool} */
    private function scan(string $path): array
    {
        $files = 0;
        $directories = 0;
        $bytes = 0;
        $seen = 0;
        $finder = (new Finder())
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->in($path);

        foreach ($finder as $entry) {
            if (in_array($entry->getFilename(), ['.sympress-nginx-cache.lock', CachePathValidator::SENTINEL_FILE], true)) {
                continue;
            }

            ++$seen;

            if ($seen > $this->scanLimit) {
                return [$files, $directories, $bytes, false];
            }

            if ($entry->isDir()) {
                ++$directories;

                continue;
            }

            ++$files;
            $bytes += $entry->getSize();
        }

        return [$files, $directories, $bytes, true];
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }
}
