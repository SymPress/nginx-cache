<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

final readonly class PurgeRequest
{
    /**
     * @param list<string> $urls
     * @param list<string> $tags
     */
    public function __construct(
        public PurgeMode $mode = PurgeMode::Full,
        public array $urls = [],
        public string $reason = 'manual',
        public string $source = 'runtime',
        public bool $dryRun = false,
        public bool $prewarm = false,
        public array $tags = [],
    ) {
    }

    public static function full(
        string $reason = 'manual',
        string $source = 'runtime',
        bool $dryRun = false,
        bool $prewarm = false,
    ): self {

        return new self(PurgeMode::Full, [], $reason, $source, $dryRun, $prewarm);
    }

    /**
     * @param list<string> $urls
     * @param list<string> $tags
     */
    public static function urls(
        array $urls,
        string $reason = 'content-change',
        string $source = 'runtime',
        bool $dryRun = false,
        bool $prewarm = false,
        array $tags = [],
    ): self {

        $urls = array_values(
            array_unique(
                array_filter(
                    array_map(static fn (string $url): string => trim($url), $urls),
                    static fn (string $url): bool => $url !== '',
                ),
            ),
        );

        return new self(
            $urls === [] ? PurgeMode::Full : PurgeMode::Urls,
            $urls,
            $reason,
            $source,
            $dryRun,
            $prewarm,
            $tags,
        );
    }

    public function requiresFullPurge(): bool
    {
        return $this->mode === PurgeMode::Full || $this->urls === [];
    }

    public function withPrewarm(bool $enabled = true): self
    {
        return new self(
            $this->mode,
            $this->urls,
            $this->reason,
            $this->source,
            $this->dryRun,
            $enabled,
            $this->tags,
        );
    }

    public function asDryRun(bool $enabled = true): self
    {
        return new self(
            $this->mode,
            $this->urls,
            $this->reason,
            $this->source,
            $enabled,
            $this->prewarm,
            $this->tags,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode'    => $this->mode->value,
            'urls'    => $this->urls,
            'reason'  => $this->reason,
            'source'  => $this->source,
            'dry_run' => $this->dryRun,
            'prewarm' => $this->prewarm,
            'tags'    => $this->tags,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $mode = ($data['mode'] ?? '') === PurgeMode::Urls->value ? PurgeMode::Urls : PurgeMode::Full;
        $urls = array_values(
            array_filter(
                array_map(
                    static fn (mixed $url): string => is_string($url) ? trim($url) : '',
                    is_array($data['urls'] ?? null) ? $data['urls'] : [],
                ),
                static fn (string $url): bool => $url !== '',
            ),
        );
        $tags = array_values(
            array_filter(
                array_map(
                    static fn (mixed $tag): string => is_string($tag) ? trim($tag) : '',
                    is_array($data['tags'] ?? null) ? $data['tags'] : [],
                ),
                static fn (string $tag): bool => $tag !== '',
            ),
        );

        return new self(
            $mode,
            $urls,
            is_string($data['reason'] ?? null) ? $data['reason'] : 'manual',
            is_string($data['source'] ?? null) ? $data['source'] : 'runtime',
            (bool) ($data['dry_run'] ?? false),
            (bool) ($data['prewarm'] ?? false),
            $tags,
        );
    }
}
