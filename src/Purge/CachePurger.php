<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

final readonly class CachePurger
{
    private const string LOCK_FILE = '.sympress-nginx-cache.lock';

    public function __construct(
        private Filesystem $filesystem,
        private CachePathValidator $validator,
        private CacheFileResolver $files,
    ) {
    }

    public function purge(string $path): PurgeResult
    {
        return $this->purgeRequest($path, PurgeRequest::full());
    }

    public function purgeRequest(string $path, PurgeRequest $request): PurgeResult
    {
        $startedAt = microtime(true);
        $validation = $this->validator->validate($path, true, true);

        if (!$validation->isValid()) {
            return PurgeResult::failure(
                $validation->path,
                $validation->firstError() ?? 'Cache path is not valid.',
                microtime(true) - $startedAt,
                $request->mode,
                $request->reason,
                $request->source,
                $request->dryRun,
            );
        }

        $lock = $this->openLock($validation->path);

        if (!is_resource($lock)) {
            return PurgeResult::failure(
                $validation->path,
                'Could not open cache purge lock file.',
                microtime(true) - $startedAt,
                $request->mode,
                $request->reason,
                $request->source,
                $request->dryRun,
            );
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return PurgeResult::failure(
                    $validation->path,
                    'Could not acquire cache purge lock.',
                    microtime(true) - $startedAt,
                    $request->mode,
                    $request->reason,
                    $request->source,
                    $request->dryRun,
                );
            }

            if (!$request->requiresFullPurge()) {
                return $this->purgeUrls($validation->path, $request, $startedAt);
            }

            $entries = $this->purgeableEntries($validation->path);
            $removed = count($entries);

            try {
                if (!$request->dryRun) {
                    $this->filesystem->remove($entries);
                    $this->filesystem->mkdir($validation->path, 0775);
                }
            } catch (IOExceptionInterface $exception) {
                return PurgeResult::failure(
                    $validation->path,
                    sprintf('Cache entries could not be removed: %s', $exception->getMessage()),
                    microtime(true) - $startedAt,
                    $request->mode,
                    $request->reason,
                    $request->source,
                    $request->dryRun,
                );
            }

            return PurgeResult::success(
                $validation->path,
                $removed,
                microtime(true) - $startedAt,
                PurgeMode::Full,
                $request->reason,
                $request->source,
                $request->dryRun,
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function purgeUrls(string $path, PurgeRequest $request, float $startedAt): PurgeResult
    {
        $entries = [];
        $purgedUrls = [];
        $missedUrls = [];

        foreach ($request->urls as $url) {
            $matches = [];

            foreach ($this->files->candidates($path, $url) as $candidate) {
                if (!is_file($candidate)) {
                    continue;
                }

                $matches[] = $candidate;
            }

            if ($matches === []) {
                $missedUrls[] = $url;

                continue;
            }

            $purgedUrls[] = $url;
            $entries = [...$entries, ...$matches];
        }

        $entries = array_values(array_unique($entries));

        try {
            if (!$request->dryRun) {
                $this->filesystem->remove($entries);
            }
        } catch (IOExceptionInterface $exception) {
            return PurgeResult::failure(
                $path,
                sprintf('Cache URL entries could not be removed: %s', $exception->getMessage()),
                microtime(true) - $startedAt,
                $request->mode,
                $request->reason,
                $request->source,
                $request->dryRun,
            );
        }

        return PurgeResult::success(
            $path,
            count($entries),
            microtime(true) - $startedAt,
            PurgeMode::Urls,
            $request->reason,
            $request->source,
            $request->dryRun,
            $request->urls,
            $purgedUrls,
            $missedUrls,
        );
    }

    /** @return list<string> */
    private function purgeableEntries(string $path): array
    {
        $entries = [];
        $iterator = new \FilesystemIterator(
            $path,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME,
        );

        foreach ($iterator as $entry) {
            if (
                !is_string($entry)
                || in_array(basename($entry), [self::LOCK_FILE, CachePathValidator::SENTINEL_FILE], true)
            ) {
                continue;
            }

            $entries[] = $entry;
        }

        sort($entries);

        return $entries;
    }

    /** @return resource|null */
    private function openLock(string $path): mixed
    {
        $lock = @fopen(sprintf('%s/%s', rtrim($path, '/'), self::LOCK_FILE), 'c');

        return is_resource($lock) ? $lock : null;
    }
}
