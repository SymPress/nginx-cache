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
            ->addOption('section', null, InputOption::VALUE_REQUIRED, 'Nginx include context: all, http, server, logging or fastcgi.', 'all')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print config diagnostics as JSON.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format. Use json for machine output.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runCommand([], [
            'section' => $input->getOption('section'),
            'json'    => $input->getOption('json'),
            'format'  => $input->getOption('format'),
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
        $context = $assocArgs['section'] ?? 'all';
        if (!is_string($context) || !in_array($context, ['all', 'http', 'server', 'logging', 'fastcgi'], true)) {
            return $this->error('Config context must be all, http, server, logging or fastcgi.', $output);
        }
        $config = $this->config->generate(context: $context);
        $missing = $this->config->validate($config, $context);

        if ($this->flag($assocArgs, 'json') || ($assocArgs['format'] ?? null) === 'json') {
            $this->line($this->json([
                'context'            => $context,
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
