<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:config', description: 'Generate and validate Nginx cache config snippets.')]
final class ConfigCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly WordPressCacheSettings $settings,
        private readonly NginxConfigGenerator $config,
    ) {

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print config diagnostics as JSON.')
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
        $config = $this->config->generate();
        $missing = $this->config->validate($config);

        if ($this->flag($assocArgs, 'json') || ($assocArgs['format'] ?? null) === 'json') {
            $this->line($this->json([
                'profile'            => $this->settings->profile()->value,
                'config'             => $config,
                'missing_directives' => $missing,
            ]), $output);

            return Command::SUCCESS;
        }

        $this->line($config, $output);

        if ($missing !== []) {
            $this->warning(sprintf('Missing directives: %s', implode(', ', $missing)), $output);
        }

        return Command::SUCCESS;
    }
}
