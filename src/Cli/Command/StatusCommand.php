<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Inspection\CacheStatusInspector;
use SymPress\NginxCache\Inspection\Diagnostics;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:status', description: 'Show Nginx cache status.')]
final class StatusCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly WordPressCacheSettings $settings,
        private readonly CacheStatusInspector $inspector,
        private readonly Diagnostics $diagnostics,
    ) {

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the status as JSON.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format. Use json for machine output.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runCommand([], [
            'json'   => $input->getOption('json'),
            'format' => $input->getOption('format'),
        ], $output);
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
        $status = $this->inspector->inspect($this->settings->cachePath());
        $report = [
            'path'          => $status->path,
            'available'     => $status->available(),
            'writable'      => $status->writable,
            'files'         => $status->files,
            'directories'   => $status->directories,
            'bytes'         => $status->bytes,
            'formattedSize' => $status->formattedSize(),
            'scanComplete'  => $status->scanComplete,
            'error'         => $status->error,
            'last_purge'    => $this->diagnostics->report()['last_purge'] ?? null,
        ];

        if ($this->flag($assocArgs, 'json') || ($assocArgs['format'] ?? null) === 'json') {
            $this->line($this->json($report), $output);

            return Command::SUCCESS;
        }

        $this->log(sprintf('Path: %s', $status->path), $output);
        $this->log(sprintf('Available: %s', $status->available() ? 'yes' : 'no'), $output);
        $this->log(sprintf('Writable: %s', $status->writable ? 'yes' : 'no'), $output);
        $this->log(sprintf('Files: %d%s', $status->files, $status->scanComplete ? '' : '+'), $output);
        $this->log(sprintf('Directories: %d%s', $status->directories, $status->scanComplete ? '' : '+'), $output);
        $this->log(sprintf('Size: %s', $status->formattedSize()), $output);

        if ($status->error !== null) {
            $this->warning($status->error, $output);
        }

        $last = $report['last_purge'];

        if (is_array($last)) {
            $this->log(sprintf(
                'Last purge: %s, %s, %d entries',
                (string) ($last['mode'] ?? ''),
                (string) ($last['reason'] ?? ''),
                (int) ($last['removed_entries'] ?? 0),
            ), $output);
        }

        return Command::SUCCESS;
    }
}
