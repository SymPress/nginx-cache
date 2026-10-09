<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Settings\NetworkConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:network', description: 'Adopt a site configuration as network values without overwriting site options.')]
final class NetworkCommand extends AbstractCacheCommand
{
    public function __construct(private readonly NetworkConfiguration $configuration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'Supported action: adopt-settings.', 'adopt-settings')
            ->addOption('from-site', null, InputOption::VALUE_REQUIRED, 'Source site ID.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview values without writing network settings.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->adopt((string) $input->getArgument('action'), ['from-site' => $input->getOption('from-site'), 'dry-run' => $input->getOption('dry-run')], $output);
    }

    protected function runWpCli(array $args, array $assocArgs): int
    {
        return $this->adopt($args[0] ?? 'adopt-settings', $assocArgs);
    }

    /** @param array<string, mixed> $options */
    private function adopt(string $action, array $options, ?OutputInterface $output = null): int
    {
        if ($action !== 'adopt-settings' || (int) ($options['from-site'] ?? 0) < 1) {
            return $this->error('Use network adopt-settings --from-site=<id> [--dry-run].', $output);
        }
        try {
            $report = $this->configuration->adoption((int) $options['from-site'], $this->flag($options, 'dry-run'));
        } catch (\Throwable) {
            return $this->error('Network settings could not be adopted; check the source site and encryption configuration.', $output);
        }
        $this->line($this->json($report), $output);
        return Command::SUCCESS;
    }
}
