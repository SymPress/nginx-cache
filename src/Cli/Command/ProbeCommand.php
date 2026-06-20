<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Inspection\CacheProbe;
use SymPress\NginxCache\Security\UrlPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:probe', description: 'Probe a URL and inspect cache headers and files.')]
final class ProbeCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly CacheProbe $probe,
        private readonly UrlPolicy $urls,
    ) {

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'Same-origin URL to probe.')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Same-origin URL to probe.')
            ->addOption('cookie', null, InputOption::VALUE_REQUIRED, 'Cookie header used for bypass analysis.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runCommand($this->strings($input->getArgument('url')), [
            'target' => $input->getOption('target'),
            'cookie' => $input->getOption('cookie'),
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
        $url = $args[0] ?? ($assocArgs['target'] ?? null);
        $url = $this->urls->normalizeSameOriginHttpUrl($url);

        if ($url === '') {
            return $this->error(
                'A same-origin URL argument is required. Use --target when --url is reserved by WP-CLI.',
                $output,
            );
        }

        $cookie = is_string($assocArgs['cookie'] ?? null) ? $assocArgs['cookie'] : '';
        $this->line($this->json($this->probe->probe($url, $cookie)), $output);

        return Command::SUCCESS;
    }
}
