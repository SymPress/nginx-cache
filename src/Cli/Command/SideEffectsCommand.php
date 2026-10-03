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
        $this->addArgument('action', InputArgument::OPTIONAL, 'Use status, details, flush (due work), or retry (reset the retry budget).', 'status');
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
            if ($this->sideEffects->count() > 0 || $this->sideEffects->attentionReason() === 'storage-error') {
                return $this->error('Side effects remain pending; inspect details for retry timing or exhaustion.', $output);
            }
            $this->success('Processed queued Nginx cache side effects.', $output);

            return Command::SUCCESS;
        }

        if ($action === 'retry') {
            $this->sideEffects->retry();
            $this->success('Reset the pending side-effect retry budget and scheduled processing.', $output);
            return Command::SUCCESS;
        }
        if ($action === 'details') {
            $this->line($this->json($this->sideEffects->inspect()), $output);
            return Command::SUCCESS;
        }
        if ($action !== 'status') {
            return $this->error('Unknown action. Use status, details, flush, or retry.', $output);
        }

        $this->log(sprintf('Pending side-effect tasks: %d', $this->sideEffects->count()), $output);
        $this->log(sprintf('Exhausted side-effect tasks: %d', count(array_filter($this->sideEffects->inspect(), static fn (array $task): bool => $task['exhausted']))), $output);
        if ($this->sideEffects->attentionReason() !== '') {
            $this->log('External queue attention: ' . $this->sideEffects->attentionReason(), $output);
        }

        return Command::SUCCESS;
    }
}
