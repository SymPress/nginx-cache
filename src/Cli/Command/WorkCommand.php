<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Purge\CacheWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:work', description: 'Process due cache work within a runtime and task budget.')]
final class WorkCommand extends AbstractCacheCommand
{
    public function __construct(private readonly CacheWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Maximum runtime in seconds.', '50')
            ->addOption('max-tasks', null, InputOption::VALUE_REQUIRED, 'Maximum tasks attempted.', '500')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Milliseconds between passes.', '0')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run one pass over pending sites.')
            ->addOption('network', null, InputOption::VALUE_NONE, 'Only process network sites with pending work.')
            ->addOption('fail-on-exhausted', null, InputOption::VALUE_NONE, 'Fail when exhausted tasks remain.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output: table or json.', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [];
        foreach (['max-runtime', 'max-tasks', 'sleep', 'once', 'network', 'fail-on-exhausted', 'format'] as $name) {
            $options[$name] = $input->getOption($name);
        }
        return $this->runWorker($options, $output);
    }

    protected function runWpCli(array $args, array $assocArgs): int
    {
        return $this->runWorker($assocArgs);
    }

    /** @param array<string, mixed> $options */
    private function runWorker(array $options, ?OutputInterface $output = null): int
    {
        if (!in_array($options['format'] ?? 'table', ['table', 'json'], true)) {
            return $this->error('Format must be table or json.', $output);
        }
        $report = $this->worker->run((float) ($options['max-runtime'] ?? 50), (int) ($options['max-tasks'] ?? 500), (int) ($options['sleep'] ?? 0), $this->flag($options, 'once'), $this->flag($options, 'network'));
        if (($options['format'] ?? 'table') === 'json') {
            $this->line($this->json($report), $output);
        } else {
            $this->line('Site | Tasks | Pending | Exhausted', $output);
            foreach ($report['sites'] as $id => $site) {
                $this->line(sprintf('%d | %d | %d | %d', $id, $site['tasks'], $site['pending'], $site['exhausted']), $output);
            }
            $this->line(sprintf('Processed %d tasks in %.3fs.', $report['tasks'], $report['runtime_seconds']), $output);
        }
        return $this->flag($options, 'fail-on-exhausted') && $report['exhausted'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
