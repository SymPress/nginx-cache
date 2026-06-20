<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:side-effects', description: 'Inspect or flush queued cache side effects.')]
final class SideEffectsCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly PurgeSideEffectProcessor $sideEffects,
    ) {

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'Use "flush" to process queued side effects.', 'status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runCommand($this->strings($input->getArgument('action')), [], $output);
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
        $action = $args[0] ?? 'status';

        if ($action === 'flush') {
            $this->sideEffects->process();
            $this->success('Processed queued Nginx cache side effects.', $output);

            return Command::SUCCESS;
        }

        $this->log(sprintf('Pending side-effect tasks: %d', $this->sideEffects->count()), $output);

        return Command::SUCCESS;
    }
}
