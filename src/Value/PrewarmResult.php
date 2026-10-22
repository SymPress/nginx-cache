<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

final readonly class PrewarmResult
{
    /**
     * @param list<string> $urls
     * @param array<string, int|string> $responses
     * @param list<string> $errors
     * @param array<string, int> $priorities
     * @param array<string, array{reason: string, stale: bool}> $issues Safe per-URL reasons; stale targets are skipped, not retried.
     */
    public function __construct(
        public array $urls,
        public array $responses,
        public array $errors = [],
        public array $priorities = [],
        public array $issues = [],
    ) {
    }

    public function attempted(): int
    {
        return count($this->urls);
    }

    public function successful(): int
    {
        return count($this->responses);
    }

    /** @return list<string> */
    public function stale(): array
    {
        return array_keys(array_filter($this->issues, static fn (array $issue): bool => $issue['stale']));
    }

    public function failed(): int
    {
        return count($this->errors);
    }
}
