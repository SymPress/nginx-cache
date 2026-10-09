<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli\Command;

use SymPress\NginxCache\Cli\UrlInputNormalizer;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'nginx-cache:purge', description: 'Purge the configured Nginx cache.')]
final class PurgeCommand extends AbstractCacheCommand
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly PurgeQueueProcessor $queue,
        private readonly UrlInputNormalizer $urls,
    ) {

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('urls', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Same-origin URLs to purge.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Single same-origin URL to purge.')
            ->addOption('urls', null, InputOption::VALUE_REQUIRED, 'Comma or whitespace separated URLs to purge.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason recorded in purge history.', 'cli')
            ->addOption('network', null, InputOption::VALUE_NONE, 'Purge all sites in a multisite network.')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Purge ownership: site or network.', 'site')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Queue the purge instead of running immediately.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve matching cache entries without deleting them.')
            ->addOption('prewarm', null, InputOption::VALUE_NONE, 'Prewarm URLs after a successful purge.')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Force a full cache purge.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runCommand($this->strings($input->getArgument('urls')), [
            'url'     => $input->getOption('url'),
            'urls'    => $input->getOption('urls'),
            'reason'  => $input->getOption('reason'),
            'network' => $input->getOption('network'),
            'scope'   => $input->getOption('scope'),
            'queue'   => $input->getOption('queue'),
            'dry-run' => $input->getOption('dry-run'),
            'prewarm' => $input->getOption('prewarm'),
            'full'    => $input->getOption('full'),
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
        $scope = $assocArgs['scope'] ?? 'site';
        if (!in_array($scope, ['site', 'network'], true)) {
            return $this->error('Scope must be site or network.', $output);
        }
        if ($this->flag($assocArgs, 'network') && $this->flag($assocArgs, 'full')) {
            $assocArgs['scope'] = 'network';
        } elseif ($this->flag($assocArgs, 'network') && function_exists('is_multisite') && is_multisite()) {
            return $this->purgeNetwork($assocArgs, $output);
        }

        $request = $this->requestFromArgs($args, $assocArgs, $output);

        if (!$request instanceof PurgeRequest) {
            return Command::FAILURE;
        }

        if ($this->flag($assocArgs, 'queue') && !$request->dryRun) {
            $this->queue->enqueue($request);
            $this->success(sprintf('Queued purge request. Pending requests: %d.', $this->queue->count()), $output);

            return Command::SUCCESS;
        }

        $result = $this->cache->purgeConfiguredPath($request);

        if (!$result->successful) {
            return $this->error($result->message, $output);
        }

        $this->success(
            sprintf(
                '%sPurged %d cache entries from %s in %.3fs.',
                $result->dryRun ? 'Dry run: ' : '',
                $result->removedEntries,
                $result->path,
                $result->durationSeconds,
            ),
            $output,
        );

        if ($result->missedUrls !== []) {
            $this->warning(sprintf('%d requested URLs had no matching cache file.', count($result->missedUrls)), $output);
        }

        if ($result->prewarm !== null) {
            $this->log(sprintf(
                'Prewarmed %d/%d URLs.',
                $result->prewarm->successful(),
                $result->prewarm->attempted(),
            ), $output);
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $assocArgs */
    private function purgeNetwork(array $assocArgs, ?OutputInterface $output): int
    {
        if (!function_exists('get_sites') || !function_exists('switch_to_blog') || !function_exists('restore_current_blog')) {
            return $this->error('Multisite functions are not available.', $output);
        }

        $sites = get_sites(['fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id()]);
        $failed = false;
        $assocArgs['scope'] = 'site';

        foreach ($sites as $siteId) {
            switch_to_blog((int) $siteId);

            try {
                $request = $this->requestFromArgs([], $assocArgs, $output);

                if (!$request instanceof PurgeRequest) {
                    return Command::FAILURE;
                }

                if ($this->flag($assocArgs, 'queue') && !$request->dryRun) {
                    $this->queue->enqueue($request);
                    $this->log(sprintf('Site %d queued.', (int) $siteId), $output);
                    continue;
                }
                $result = $this->cache->purgeConfiguredPath($request);
            } finally {
                restore_current_blog();
            }

            if (!$result->successful) {
                $this->warning(sprintf('Site %d failed: %s', (int) $siteId, $result->message), $output);
                $failed = true;

                continue;
            }

            $this->log(sprintf('Site %d purged %d entries.', (int) $siteId, $result->removedEntries), $output);
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    private function requestFromArgs(array $args, array $assocArgs, ?OutputInterface $output): ?PurgeRequest
    {
        $urls = $this->urls->urls($args, $assocArgs);
        $reason = is_string($assocArgs['reason'] ?? null) && trim($assocArgs['reason']) !== ''
            ? trim($assocArgs['reason'])
            : 'cli';
        $dryRun = $this->flag($assocArgs, 'dry-run');
        $prewarm = $this->flag($assocArgs, 'prewarm');
        $full = $this->flag($assocArgs, 'full');
        $scope = ($assocArgs['scope'] ?? 'site') === 'network' ? PurgeScope::Network : PurgeScope::Site;

        if (!$full && $this->urls->hasProvidedUrls($args, $assocArgs) && $urls === []) {
            $this->error('At least one same-origin URL is required for URL purge.', $output);

            return null;
        }

        if (!$full && $urls !== []) {
            return PurgeRequest::urls($urls, $reason, 'cli', $dryRun, $prewarm, scope: $scope);
        }

        return PurgeRequest::full($reason, 'cli', $dryRun, $prewarm, $scope);
    }
}
