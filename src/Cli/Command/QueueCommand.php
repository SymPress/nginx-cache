<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:queue', description: 'Inspect or flush queued purge requests.')]
final class QueueCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly PurgeQueueProcessor $queue,
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
            $this->queue->process();
            if ($this->queue->count() > 0) {
                return $this->error('Purge requests remain pending; inspect details for retry timing or exhaustion.', $output);
            }
            $this->success('Processed queued purge requests.', $output);

            return Command::SUCCESS;
        }

        if ($action === 'retry') {
            $this->queue->retry();
            $this->success('Reset the pending purge retry budget and scheduled processing.', $output);
            return Command::SUCCESS;
        }
        if ($action === 'details') {
            $this->line($this->json($this->queue->inspect()), $output);
            return Command::SUCCESS;
        }
        if ($action !== 'status') {
            return $this->error('Unknown action. Use status, details, flush, or retry.', $output);
        }

        $this->log(sprintf('Pending purge requests: %d', $this->queue->count()), $output);
        $this->log(sprintf('Exhausted purge requests: %d', count(array_filter($this->queue->inspect(), static fn (array $task): bool => $task['exhausted']))), $output);

        return Command::SUCCESS;
    }
}
