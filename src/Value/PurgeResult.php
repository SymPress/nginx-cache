<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

final readonly class PurgeResult
{
    /**
     * @param list<string> $requestedUrls
     * @param list<string> $purgedUrls
     * @param list<string> $missedUrls
     * @param list<string> $errors
     * @param array<string, mixed> $sideEffects
     */
    private function __construct(
        public bool $successful,
        public string $path,
        public int $removedEntries,
        public float $durationSeconds,
        public string $message,
        public PurgeMode $mode = PurgeMode::Full,
        public string $reason = 'manual',
        public string $source = 'runtime',
        public bool $dryRun = false,
        public array $requestedUrls = [],
        public array $purgedUrls = [],
        public array $missedUrls = [],
        public ?PrewarmResult $prewarm = null,
        public array $errors = [],
        public array $sideEffects = [],
        public int $createdAt = 0,
    ) {
    }

    /**
     * @param list<string> $requestedUrls
     * @param list<string> $purgedUrls
     * @param list<string> $missedUrls
     */
    public static function success(
        string $path,
        int $removedEntries,
        float $durationSeconds,
        PurgeMode $mode = PurgeMode::Full,
        string $reason = 'manual',
        string $source = 'runtime',
        bool $dryRun = false,
        array $requestedUrls = [],
        array $purgedUrls = [],
        array $missedUrls = [],
        int $createdAt = 0,
    ): self {

        $prefix = $dryRun ? 'Dry run: ' : '';

        return new self(
            true,
            $path,
            $removedEntries,
            $durationSeconds,
            sprintf(
                '%sPurged %d cache entr%s.',
                $prefix,
                $removedEntries,
                $removedEntries === 1 ? 'y' : 'ies',
            ),
            $mode,
            $reason,
            $source,
            $dryRun,
            $requestedUrls,
            $purgedUrls,
            $missedUrls,
            null,
            [],
            [],
            $createdAt,
        );
    }

    /** @param list<string> $errors */
    public static function failure(
        string $path,
        string $message,
        float $durationSeconds = 0.0,
        PurgeMode $mode = PurgeMode::Full,
        string $reason = 'manual',
        string $source = 'runtime',
        bool $dryRun = false,
        array $errors = [],
        int $createdAt = 0,
    ): self {

        return new self(
            false,
            $path,
            0,
            $durationSeconds,
            $message,
            $mode,
            $reason,
            $source,
            $dryRun,
            [],
            [],
            [],
            null,
            $errors !== [] ? $errors : [$message],
            [],
            $createdAt,
        );
    }

    public function withPrewarm(PrewarmResult $prewarm): self
    {
        return new self(
            $this->successful,
            $this->path,
            $this->removedEntries,
            $this->durationSeconds,
            $this->message,
            $this->mode,
            $this->reason,
            $this->source,
            $this->dryRun,
            $this->requestedUrls,
            $this->purgedUrls,
            $this->missedUrls,
            $prewarm,
            $this->errors,
            $this->sideEffects,
            $this->createdAt,
        );
    }

    /** @param array<string, mixed> $sideEffects */
    public function withSideEffects(array $sideEffects): self
    {
        return new self(
            $this->successful,
            $this->path,
            $this->removedEntries,
            $this->durationSeconds,
            $this->message,
            $this->mode,
            $this->reason,
            $this->source,
            $this->dryRun,
            $this->requestedUrls,
            $this->purgedUrls,
            $this->missedUrls,
            $this->prewarm,
            $this->errors,
            $sideEffects,
            $this->createdAt,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $mode = ($data['mode'] ?? '') === PurgeMode::Urls->value ? PurgeMode::Urls : PurgeMode::Full;
        $prewarm = null;

        if (is_array($data['prewarm'] ?? null)) {
            $prewarm = new PrewarmResult(
                self::stringList($data['prewarm']['urls'] ?? []),
                is_array($data['prewarm']['responses'] ?? null) ? $data['prewarm']['responses'] : [],
                self::stringList($data['prewarm']['errors'] ?? []),
            );
        }

        return new self(
            (bool) ($data['successful'] ?? false),
            is_string($data['path'] ?? null) ? $data['path'] : '',
            (int) ($data['removed_entries'] ?? 0),
            (float) ($data['duration_seconds'] ?? 0.0),
            is_string($data['message'] ?? null) ? $data['message'] : '',
            $mode,
            is_string($data['reason'] ?? null) ? $data['reason'] : 'manual',
            is_string($data['source'] ?? null) ? $data['source'] : 'runtime',
            (bool) ($data['dry_run'] ?? false),
            self::stringList($data['requested_urls'] ?? []),
            self::stringList($data['purged_urls'] ?? []),
            self::stringList($data['missed_urls'] ?? []),
            $prewarm,
            self::stringList($data['errors'] ?? []),
            is_array($data['side_effects'] ?? null) ? $data['side_effects'] : [],
            (int) ($data['created_at'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'successful'       => $this->successful,
            'path'             => $this->path,
            'removed_entries'  => $this->removedEntries,
            'duration_seconds' => $this->durationSeconds,
            'message'          => $this->message,
            'mode'             => $this->mode->value,
            'reason'           => $this->reason,
            'source'           => $this->source,
            'dry_run'          => $this->dryRun,
            'requested_urls'   => $this->requestedUrls,
            'purged_urls'      => $this->purgedUrls,
            'missed_urls'      => $this->missedUrls,
            'prewarm'          => $this->prewarm instanceof PrewarmResult ? [
                'urls'       => $this->prewarm->urls,
                'attempted'  => $this->prewarm->attempted(),
                'successful' => $this->prewarm->successful(),
                'failed'     => $this->prewarm->failed(),
                'responses'  => $this->prewarm->responses,
                'errors'     => $this->prewarm->errors,
            ] : null,
            'errors'           => $this->errors,
            'side_effects'     => $this->sideEffects,
            'created_at'       => $this->createdAt,
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
                static fn (string $item): bool => $item !== '',
            ),
        );
    }
}
