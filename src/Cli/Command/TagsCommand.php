<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Surrogate\TagIndexRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:tags', description: 'Inspect or clear the surrogate tag index.')]
final class TagsCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly TagIndexRepository $tags,
    ) {

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Use "urls" or "clear"; defaults to stats.', 'status')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Single surrogate tag.')
            ->addOption('tags', null, InputOption::VALUE_REQUIRED, 'Comma or whitespace separated surrogate tags.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runCommand($this->strings($input->getArgument('action')), [
            'tag'  => $input->getOption('tag'),
            'tags' => $input->getOption('tags'),
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
        $action = $args[0] ?? 'status';

        if ($action === 'clear') {
            $this->tags->clear();
            $this->success('Cleared Nginx cache tag index.', $output);

            return Command::SUCCESS;
        }

        if ($action === 'urls') {
            $tags = $this->strings($assocArgs['tag'] ?? ($assocArgs['tags'] ?? ''));
            $this->line($this->json($this->tags->urlsForTags($tags)), $output);

            return Command::SUCCESS;
        }

        $this->line($this->json($this->tags->stats()), $output);

        return Command::SUCCESS;
    }
}
