<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Migration\NginxHelperImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:migrate', description: 'Preview or import supported legacy cache settings.')]
final class MigrateCommand extends AbstractCacheCommand
{
    public function __construct(private readonly NginxHelperImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'nginx-helper or nginx-cache')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only; no options are written.')
            ->addOption('network', null, InputOption::VALUE_NONE, 'Import into network settings.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->migrate((string) $input->getArgument('source'), ['dry-run' => $input->getOption('dry-run'), 'network' => $input->getOption('network')], $output);
    }

    protected function runWpCli(array $args, array $assocArgs): int
    {
        return $this->migrate($args[0] ?? '', $assocArgs);
    }

    /** @param array<string, mixed> $options */
    private function migrate(string $source, array $options, ?OutputInterface $output = null): int
    {
        try {
            $report = $this->importer->import($source, $this->flag($options, 'dry-run'), $this->flag($options, 'network'));
        } catch (\Throwable) {
            return $this->error('Settings could not be imported. Check the source, network and encryption configuration.', $output);
        }
        $this->line($this->json($report), $output);
        $this->line('Verify the cache key and protected HTTP purge endpoint, then deactivate the previous plugin. WooCommerce hooks are built in; Multisite maps are available in Network Admin.', $output);
        return Command::SUCCESS;
    }
}
