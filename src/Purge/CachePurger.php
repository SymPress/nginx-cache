<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeMode;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use SymPress\NginxCache\Value\PurgeScope;
use SymPress\NginxCache\Value\SiteKeyMatcher;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

final readonly class CachePurger
{
    private const string LOCK_FILE = '.sympress-nginx-cache.lock';

    public function __construct(
        private Filesystem $filesystem,
        private CachePathValidator $validator,
        private CacheFileResolver $files,
        private FullPurgeEndpointDispatcher $fullPurgeEndpoint,
        private CacheClock $clock,
        private ?SiteScopeResolver $scope = null,
        private ?SiteScopedCacheScanner $scanner = null,
    ) {
    }

    public function purge(string $path): PurgeResult
    {
        return $this->purgeRequest($path, PurgeRequest::full());
    }

    public function purgeRequest(string $path, PurgeRequest $request): PurgeResult
    {
        return $this->purgeInScope($path, $request)->withScope($request->scope);
    }

    public function purgeSite(string $path, PurgeRequest $request, SiteKeyMatcher $matcher, string $cursor = ''): PurgeResult
    {
        if ($request->scope !== PurgeScope::Site || !$request->requiresFullPurge()) {
            return PurgeResult::failure($path, 'A site scan requires a full site-scoped request.');
        }
        return $this->purgeInScope($path, $request, $matcher, $cursor)->withScope(PurgeScope::Site);
    }

    private function purgeInScope(string $path, PurgeRequest $request, ?SiteKeyMatcher $matcher = null, string $cursor = ''): PurgeResult
    {
        $startedAt = $this->clock->highResolutionTimestamp();
        $createdAt = $this->clock->timestamp();

        if ($matcher === null && $request->requiresFullPurge() && $this->fullPurgeEndpoint->enabled()) {
            return $this->fullPurgeEndpoint->purge($request, $startedAt, $createdAt);
        }

        $scope = $this->scope ?? new SiteScopeResolver();
        if ($matcher === null && $request->requiresFullPurge() && $request->scope === PurgeScope::Site && !$scope->isIsolated()) {
            $matcher = $scope->matcher();
        }

        $validation = $this->validator->validate($path, !$request->dryRun, true);

        if (!$validation->isValid()) {
            return PurgeResult::failure(
                $validation->path,
                $validation->firstError() ?? 'Cache path is not valid.',
                $this->clock->elapsedSince($startedAt),
                $request->mode,
                $request->reason,
                $request->source,
                $request->dryRun,
                createdAt: $createdAt,
            );
        }

        if ($request->dryRun && !file_exists($validation->path . '/' . self::LOCK_FILE)) {
            return $this->purgeValidatedPath($validation->path, $request, $startedAt, $createdAt, $matcher, $cursor);
        }

        $lock = $this->openLock($validation->path, $request->dryRun);

        if (!is_resource($lock)) {
            return PurgeResult::failure(
                $validation->path,
                'Could not open cache purge lock file.',
                $this->clock->elapsedSince($startedAt),
                $request->mode,
                $request->reason,
                $request->source,
                $request->dryRun,
                createdAt: $createdAt,
            );
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return PurgeResult::failure(
                    $validation->path,
                    'Could not acquire cache purge lock.',
                    $this->clock->elapsedSince($startedAt),
                    $request->mode,
                    $request->reason,
                    $request->source,
                    $request->dryRun,
                    createdAt: $createdAt,
                );
            }

            return $this->purgeValidatedPath($validation->path, $request, $startedAt, $createdAt, $matcher, $cursor);
        } finally {
            flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the connection-local POSIX flock resource after releasing ownership.
            fclose($lock);
        }
    }

    private function purgeValidatedPath(string $path, PurgeRequest $request, float $startedAt, int $createdAt, ?SiteKeyMatcher $matcher = null, string $cursor = ''): PurgeResult
    {
        if (!$request->requiresFullPurge()) {
            return $this->purgeUrls($path, $request, $startedAt, $createdAt);
        }

        if ($matcher !== null) {
            try {
                $scanner = $this->scanner ?? new SiteScopedCacheScanner(new CacheKeyStrategy());
                $scan = $scanner->scan($path, $matcher, $cursor);
                if (!$request->dryRun) {
                    $this->filesystem->remove($scan['entries']);
                }
                return PurgeResult::success($path, count($scan['entries']), $this->clock->elapsedSince($startedAt), reason: $request->reason, source: $request->source, dryRun: $request->dryRun, createdAt: $createdAt)->withScan($scan['partial'], $scan['unmatched'], $scan['cursor']);
            } catch (\Throwable) {
                return PurgeResult::failure($path, 'Site cache scan failed; no broader purge was attempted.', $this->clock->elapsedSince($startedAt), reason: $request->reason, source: $request->source, dryRun: $request->dryRun, createdAt: $createdAt);
            }
        }

        $entries = $this->purgeableEntries($path);

        try {
            if (!$request->dryRun) {
                $this->filesystem->remove($entries);
                $this->filesystem->mkdir($path, 0775);
            }
        } catch (IOExceptionInterface $exception) {
            return PurgeResult::failure(
                $path,
                sprintf('Cache entries could not be removed: %s', $exception->getMessage()),
                $this->clock->elapsedSince($startedAt),
                $request->mode,
                $request->reason,
                $request->source,
                $request->dryRun,
                createdAt: $createdAt,
            );
        }

        return PurgeResult::success(
            $path,
            count($entries),
            $this->clock->elapsedSince($startedAt),
            PurgeMode::Full,
            $request->reason,
            $request->source,
            $request->dryRun,
            createdAt: $createdAt,
        );
    }

    private function purgeUrls(string $path, PurgeRequest $request, float $startedAt, int $createdAt): PurgeResult
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
                $this->clock->elapsedSince($startedAt),
                $request->mode,
                $request->reason,
                $request->source,
                $request->dryRun,
                createdAt: $createdAt,
            );
        }

        return PurgeResult::success(
            $path,
            count($entries),
            $this->clock->elapsedSince($startedAt),
            PurgeMode::Urls,
            $request->reason,
            $request->source,
            $request->dryRun,
            $request->urls,
            $purgedUrls,
            $missedUrls,
            $createdAt,
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
    private function openLock(string $path, bool $dryRun): mixed
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Atomic local flock needs a native descriptor inside the validated managed cache root.
        $lock = @fopen(sprintf('%s/%s', rtrim($path, '/'), self::LOCK_FILE), $dryRun ? 'rb' : 'c');

        return is_resource($lock) ? $lock : null;
    }
}
