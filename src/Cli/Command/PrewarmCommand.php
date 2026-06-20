<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Cli\UrlInputNormalizer;
use SymPress\NginxCache\Purge\Prewarmer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:prewarm', description: 'Prewarm configured or provided cache URLs.')]
final class PrewarmCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly Prewarmer $prewarmer,
        private readonly UrlInputNormalizer $urls,
    ) {

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('urls', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Same-origin URLs to prewarm.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Single same-origin URL to prewarm.')
            ->addOption('urls', null, InputOption::VALUE_REQUIRED, 'Comma or whitespace separated URLs to prewarm.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runCommand($this->strings($input->getArgument('urls')), [
            'url'  => $input->getOption('url'),
            'urls' => $input->getOption('urls'),
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
        $urls = $this->urls->urls($args, $assocArgs);

        if ($this->urls->hasProvidedUrls($args, $assocArgs) && $urls === []) {
            return $this->error('At least one same-origin URL is required for prewarm.', $output);
        }

        $result = $this->prewarmer->prewarm($urls);
        $this->success(sprintf('Prewarmed %d/%d URLs.', $result->successful(), $result->attempted()), $output);

        foreach ($result->errors as $error) {
            $this->warning($error, $output);
        }

        return Command::SUCCESS;
    }
}
