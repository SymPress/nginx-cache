<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

final readonly class PathValidation
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public string $path,
        public array $errors = [],
        public array $warnings = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
