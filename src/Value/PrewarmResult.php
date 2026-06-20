<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

final readonly class PrewarmResult
{
    /**
     * @param list<string> $urls
     * @param array<string, int|string> $responses
     * @param list<string> $errors
     */
    public function __construct(
        public array $urls,
        public array $responses,
        public array $errors = [],
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

    public function failed(): int
    {
        return count($this->errors);
    }
}
