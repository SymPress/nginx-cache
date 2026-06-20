<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Filesystem;

use SymPress\NginxCache\Value\PathValidation;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final readonly class CachePathValidator
{
    public const string SENTINEL_FILE = '.sympress-nginx-cache-root';

    public function __construct(
        private Filesystem $filesystem,
    ) {
    }

    public function validate(string $path, bool $createMissing = false, bool $destructiveOperation = false): PathValidation
    {
        $path = $this->normalizePath($path);
        $errors = [];
        $warnings = [];

        if ($path === '') {
            return new PathValidation($path, ['Cache path is not configured.']);
        }

        if (!$this->filesystem->isAbsolutePath($path)) {
            $errors[] = 'Cache path must be absolute.';
        }

        if ($this->isProtectedPath($path)) {
            $errors[] = 'Cache path points to a protected project or system directory.';
        }

        if ($errors !== []) {
            return new PathValidation($path, $errors);
        }

        if (!is_dir($path) && $createMissing) {
            try {
                $this->filesystem->mkdir($path, 0775);

                if ($destructiveOperation) {
                    $this->filesystem->touch(sprintf('%s/%s', $path, self::SENTINEL_FILE));
                }
            } catch (IOExceptionInterface $exception) {
                $errors[] = sprintf('Cache directory could not be created: %s', $exception->getMessage());
            }
        }

        if (!file_exists($path)) {
            $errors[] = 'Cache directory does not exist.';
        } elseif (is_link($path)) {
            $errors[] = 'Cache path must not be a symbolic link.';
        } elseif (!is_dir($path)) {
            $errors[] = 'Cache path is not a directory.';
        } elseif (!is_readable($path)) {
            $errors[] = 'Cache directory is not readable.';
        } elseif (!is_writable($path)) {
            $errors[] = 'Cache directory is not writable.';
        }

        if ($errors !== [] || !is_dir($path)) {
            return new PathValidation($path, $errors, $warnings);
        }

        if ($destructiveOperation && !$this->isTrustedPurgeRoot($path)) {
            $errors[] = sprintf(
                'Cache directory is not marked as a managed Nginx cache root. Add %s or use a common Nginx cache file layout before purging.',
                self::SENTINEL_FILE,
            );

            return new PathValidation($path, $errors, $warnings);
        }

        $shapeWarning = $this->shapeWarning($path);

        if ($shapeWarning !== null) {
            $warnings[] = $shapeWarning;
        }

        return new PathValidation($path, [], $warnings);
    }

    private function shapeWarning(string $path): ?string
    {
        if ($this->hasSentinel($path)) {
            return null;
        }

        try {
            $finder = (new Finder())
                ->files()
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->depth('<= 3')
                ->in($path);

            $checked = 0;

            foreach ($finder as $file) {
                ++$checked;
                $name = $file->getFilename();

                if (str_contains($name, '.')) {
                    continue;
                }

                if (strlen($name) === 32 && ctype_xdigit($name)) {
                    continue;
                }

                return 'Some files do not look like common Nginx cache entries.';
            }

            return $checked === 0 ? null : null;
        } catch (\Throwable $exception) {
            return sprintf('Cache directory could not be inspected: %s', $exception->getMessage());
        }
    }

    private function isProtectedPath(string $path): bool
    {
        $path = $this->canonicalPath($path);

        if ($path === '/' || preg_match('#^[A-Z]:/?$#i', $path) === 1) {
            return true;
        }

        foreach ($this->protectedDirectories() as $protectedDirectory) {
            $protectedDirectory = $this->canonicalPath($protectedDirectory);

            if (
                $protectedDirectory !== ''
                && ($path === $protectedDirectory || str_starts_with($path . '/', $protectedDirectory . '/'))
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function protectedDirectories(): array
    {
        $directories = ['/', '/etc', '/bin', '/sbin', '/usr', '/var/www', '/var/www/html', '/home', '/Users'];

        foreach (['ABSPATH', 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR'] as $constant) {
            if (!defined($constant)) {
                continue;
            }

            $directories[] = (string) constant($constant);
        }

        if (function_exists('get_theme_root')) {
            $directories[] = (string) get_theme_root();
        }

        if (isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])) {
            $directories[] = $_SERVER['DOCUMENT_ROOT'];
        }

        $cwd = getcwd();

        if (is_string($cwd)) {
            $directories[] = $cwd;
        }

        return array_values(array_unique(array_filter($directories, static fn (string $directory): bool => $directory !== '')));
    }

    private function isTrustedPurgeRoot(string $path): bool
    {
        return $this->hasSentinel($path)
            || $this->containsOnlyInternalFiles($path)
            || $this->hasCommonNginxCacheShape($path);
    }

    private function hasSentinel(string $path): bool
    {
        return is_file(sprintf('%s/%s', rtrim($path, '/'), self::SENTINEL_FILE));
    }

    private function containsOnlyInternalFiles(string $path): bool
    {
        try {
            $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME);

            foreach ($iterator as $entry) {
                if (!is_string($entry)) {
                    return false;
                }

                if (!in_array(basename($entry), [self::SENTINEL_FILE, '.sympress-nginx-cache.lock'], true)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasCommonNginxCacheShape(string $path): bool
    {
        try {
            $finder = (new Finder())
                ->files()
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->depth('<= 4')
                ->in($path);

            $checked = 0;
            $validCacheFiles = 0;

            foreach ($finder as $file) {
                if (++$checked > 200) {
                    break;
                }

                $name = $file->getFilename();

                if ($name === '.sympress-nginx-cache.lock' || $name === self::SENTINEL_FILE) {
                    continue;
                }

                if (strlen($name) === 32 && ctype_xdigit($name)) {
                    ++$validCacheFiles;

                    continue;
                }

                return false;
            }

            return $validCacheFiles > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }

    private function canonicalPath(string $path): string
    {
        $realPath = realpath($path);

        if (is_string($realPath)) {
            $path = $realPath;
        }

        return $this->normalizePath($path);
    }
}
