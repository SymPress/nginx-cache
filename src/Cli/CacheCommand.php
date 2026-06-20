<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli;

use SymPress\NginxCache\Cli\Command\WpCliCommand;

final readonly class CacheCommand
{
    /** @param iterable<WpCliCommand> $commands */
    public function __construct(
        private iterable $commands,
    ) {
    }

    public function register(): void
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        foreach ($this->commands as $command) {
            foreach (['edge-cache', 'nginx-cache'] as $namespace) {
                \WP_CLI::add_command(sprintf('%s %s', $namespace, $command->getWpCliName()), $command);
            }
        }
    }
}
