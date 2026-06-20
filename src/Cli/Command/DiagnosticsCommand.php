<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Inspection\Diagnostics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:diagnostics', description: 'Print Nginx cache diagnostics as JSON.')]
final class DiagnosticsCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly Diagnostics $diagnostics,
    ) {

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runCommand([], [], $output);
    }

    protected function runWpCli(array $args, array $assocArgs): int
    {
        return $this->runCommand($args, $assocArgs);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    private function runCommand(array $args, array $assocArgs, ?OutputInterface $output = null): int
    {
        $this->line($this->json($this->diagnostics->report()), $output);

        return Command::SUCCESS;
    }
}
