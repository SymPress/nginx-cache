<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

interface WpCliCommand
{
    public function getWpCliName(): string;

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args = [], array $assocArgs = []): void;
}
