<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli;

use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Inspection\CacheProbe;
use SymPress\NginxCache\Inspection\CacheStatusInspector;
use SymPress\NginxCache\Inspection\Diagnostics;
use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\Prewarmer;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Value\PurgeRequest;

final readonly class CacheCommand
{
    public function __construct(
        private WordPressCacheSettings $settings,
        private CacheManager $cache,
        private CacheStatusInspector $inspector,
        private PurgeQueueProcessor $queue,
        private Prewarmer $prewarmer,
        private Diagnostics $diagnostics,
        private NginxConfigGenerator $config,
        private CacheProbe $probe,
        private CacheLayerCoordinator $layers,
        private TagIndexRepository $tags,
        private UrlPolicy $urlPolicy,
        private PurgeSideEffectProcessor $sideEffects,
    ) {
    }

    public function register(): void
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        foreach (['edge-cache', 'nginx-cache'] as $namespace) {
            \WP_CLI::add_command(sprintf('%s purge', $namespace), $this->purge(...));
            \WP_CLI::add_command(sprintf('%s status', $namespace), $this->status(...));
            \WP_CLI::add_command(sprintf('%s diagnostics', $namespace), $this->diagnostics(...));
            \WP_CLI::add_command(sprintf('%s queue', $namespace), $this->queue(...));
            \WP_CLI::add_command(sprintf('%s prewarm', $namespace), $this->prewarm(...));
            \WP_CLI::add_command(sprintf('%s config', $namespace), $this->config(...));
            \WP_CLI::add_command(sprintf('%s probe', $namespace), $this->probe(...));
            \WP_CLI::add_command(sprintf('%s layers', $namespace), $this->layers(...));
            \WP_CLI::add_command(sprintf('%s tags', $namespace), $this->tags(...));
            \WP_CLI::add_command(sprintf('%s side-effects', $namespace), $this->sideEffects(...));
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function purge(array $args = [], array $assocArgs = []): void
    {
        if ($this->flag($assocArgs, 'network') && function_exists('is_multisite') && is_multisite()) {
            $this->purgeNetwork($assocArgs);

            return;
        }

        $request = $this->requestFromArgs($args, $assocArgs);

        if ($this->flag($assocArgs, 'queue')) {
            $this->queue->enqueue($request);
            \WP_CLI::success(sprintf('Queued purge request. Pending requests: %d.', $this->queue->count()));

            return;
        }

        $result = $this->cache->purgeConfiguredPath($request);

        if (!$result->successful) {
            \WP_CLI::error($result->message);
        }

        \WP_CLI::success(
            sprintf(
                '%sPurged %d cache entries from %s in %.3fs.',
                $result->dryRun ? 'Dry run: ' : '',
                $result->removedEntries,
                $result->path,
                $result->durationSeconds,
            ),
        );

        if ($result->missedUrls !== []) {
            \WP_CLI::warning(sprintf('%d requested URLs had no matching cache file.', count($result->missedUrls)));
        }

        if ($result->prewarm === null) {
            return;
        }

        \WP_CLI::log(sprintf(
            'Prewarmed %d/%d URLs.',
            $result->prewarm->successful(),
            $result->prewarm->attempted(),
        ));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function status(array $args = [], array $assocArgs = []): void
    {
        $status = $this->inspector->inspect($this->settings->cachePath());

        \WP_CLI::log(sprintf('Path: %s', $status->path));
        \WP_CLI::log(sprintf('Available: %s', $status->available() ? 'yes' : 'no'));
        \WP_CLI::log(sprintf('Writable: %s', $status->writable ? 'yes' : 'no'));
        \WP_CLI::log(sprintf('Files: %d%s', $status->files, $status->scanComplete ? '' : '+'));
        \WP_CLI::log(sprintf('Directories: %d%s', $status->directories, $status->scanComplete ? '' : '+'));
        \WP_CLI::log(sprintf('Size: %s', $status->formattedSize()));

        if ($status->error !== null) {
            \WP_CLI::warning($status->error);
        }

        $last = $this->diagnostics->report()['last_purge'] ?? null;

        if (!is_array($last)) {
            return;
        }

        \WP_CLI::log(sprintf('Last purge: %s, %s, %d entries', (string) ($last['mode'] ?? ''), (string) ($last['reason'] ?? ''), (int) ($last['removed_entries'] ?? 0)));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function diagnostics(array $args = [], array $assocArgs = []): void
    {
        \WP_CLI::line((string) wp_json_encode($this->diagnostics->report(), JSON_PRETTY_PRINT));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function config(array $args = [], array $assocArgs = []): void
    {
        $config = $this->config->generate();
        $missing = $this->config->validate($config);

        if ($this->flag($assocArgs, 'json') || ($assocArgs['format'] ?? null) === 'json') {
            \WP_CLI::line((string) wp_json_encode([
                'profile'            => $this->settings->profile()->value,
                'config'             => $config,
                'missing_directives' => $missing,
            ], JSON_PRETTY_PRINT));

            return;
        }

        \WP_CLI::line($config);

        if ($missing === []) {
            return;
        }

        \WP_CLI::warning(sprintf('Missing directives: %s', implode(', ', $missing)));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function probe(array $args = [], array $assocArgs = []): void
    {
        $url = $args[0] ?? ($assocArgs['target'] ?? null);

        $url = $this->urlPolicy->normalizeSameOriginHttpUrl($url);

        if ($url === '') {
            \WP_CLI::error('A same-origin URL argument is required. Use --target when --url is reserved by WP-CLI.');
        }

        $cookie = is_string($assocArgs['cookie'] ?? null) ? $assocArgs['cookie'] : '';
        \WP_CLI::line((string) wp_json_encode($this->probe->probe($url, $cookie), JSON_PRETTY_PRINT));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function queue(array $args = [], array $assocArgs = []): void
    {
        $action = $args[0] ?? 'status';

        if ($action === 'flush') {
            $this->queue->process();
            \WP_CLI::success('Processed queued purge requests.');

            return;
        }

        \WP_CLI::log(sprintf('Pending purge requests: %d', $this->queue->count()));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function prewarm(array $args = [], array $assocArgs = []): void
    {
        $urls = $this->urlsFromArgs($args, $assocArgs);

        if ($this->hasProvidedUrlArgs($args, $assocArgs) && $urls === []) {
            \WP_CLI::error('At least one same-origin URL is required for prewarm.');
        }

        $result = $this->prewarmer->prewarm($urls);

        \WP_CLI::success(sprintf('Prewarmed %d/%d URLs.', $result->successful(), $result->attempted()));

        foreach ($result->errors as $error) {
            \WP_CLI::warning($error);
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function layers(array $args = [], array $assocArgs = []): void
    {
        $action = $args[0] ?? 'status';

        if ($action === 'flush') {
            $layers = $this->strings($assocArgs['layers'] ?? '');
            \WP_CLI::line((string) wp_json_encode($this->layers->flush($layers), JSON_PRETTY_PRINT));

            return;
        }

        \WP_CLI::line((string) wp_json_encode([
            'sync_enabled' => $this->settings->layerSyncEnabled(),
            'available'    => $this->layers->available(),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function tags(array $args = [], array $assocArgs = []): void
    {
        $action = $args[0] ?? 'status';

        if ($action === 'clear') {
            $this->tags->clear();
            \WP_CLI::success('Cleared Nginx cache tag index.');

            return;
        }

        if ($action === 'urls') {
            $tags = $this->strings($assocArgs['tag'] ?? ($assocArgs['tags'] ?? ''));
            \WP_CLI::line((string) wp_json_encode($this->tags->urlsForTags($tags), JSON_PRETTY_PRINT));

            return;
        }

        \WP_CLI::line((string) wp_json_encode($this->tags->stats(), JSON_PRETTY_PRINT));
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function sideEffects(array $args = [], array $assocArgs = []): void
    {
        $action = $args[0] ?? 'status';

        if ($action === 'flush') {
            $this->sideEffects->process();
            \WP_CLI::success('Processed queued Nginx cache side effects.');

            return;
        }

        \WP_CLI::log(sprintf('Pending side-effect tasks: %d', $this->sideEffects->count()));
    }

    /** @param array<string, mixed> $assocArgs */
    private function purgeNetwork(array $assocArgs): void
    {
        if (!function_exists('get_sites') || !function_exists('switch_to_blog') || !function_exists('restore_current_blog')) {
            \WP_CLI::error('Multisite functions are not available.');
        }

        $sites = get_sites(['fields' => 'ids']);

        foreach ($sites as $siteId) {
            switch_to_blog((int) $siteId);
            $result = $this->cache->purgeConfiguredPath($this->requestFromArgs([], $assocArgs));
            restore_current_blog();

            if (!$result->successful) {
                \WP_CLI::warning(sprintf('Site %d failed: %s', (int) $siteId, $result->message));

                continue;
            }

            \WP_CLI::log(sprintf('Site %d purged %d entries.', (int) $siteId, $result->removedEntries));
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    private function requestFromArgs(array $args, array $assocArgs): PurgeRequest
    {
        $urls = $this->urlsFromArgs($args, $assocArgs);
        $reason = is_string($assocArgs['reason'] ?? null) && trim($assocArgs['reason']) !== ''
            ? trim($assocArgs['reason'])
            : 'cli';
        $dryRun = $this->flag($assocArgs, 'dry-run');
        $prewarm = $this->flag($assocArgs, 'prewarm');
        $full = $this->flag($assocArgs, 'full');

        if (!$full && $this->hasProvidedUrlArgs($args, $assocArgs) && $urls === []) {
            \WP_CLI::error('At least one same-origin URL is required for URL purge.');
        }

        if (!$full && $urls !== []) {
            return PurgeRequest::urls($urls, $reason, 'cli', $dryRun, $prewarm);
        }

        return PurgeRequest::full($reason, 'cli', $dryRun, $prewarm);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @return list<string>
     */
    private function urlsFromArgs(array $args, array $assocArgs): array
    {
        $urls = [];

        foreach ($args as $arg) {
            $url = $this->urlPolicy->normalizeSameOriginHttpUrl($arg);

            if ($url === '') {
                continue;
            }

            $urls[] = $url;
        }

        foreach (['url', 'urls'] as $key) {
            $value = $assocArgs[$key] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $urls = [...$urls, ...(preg_split('/[\s,]+/', $value) ?: [])];
        }

        return array_slice(
            array_values(
                array_unique(
                    array_filter(
                        array_map($this->urlPolicy->normalizeSameOriginHttpUrl(...), $urls),
                        static fn (string $url): bool => $url !== '',
                    ),
                ),
            ),
            0,
            500,
        );
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                    $value,
                ),
                static fn (string $item): bool => $item !== '',
            ),
        );
    }

    /** @param array<string, mixed> $assocArgs */
    private function flag(array $assocArgs, string $name): bool
    {
        $value = $assocArgs[$name] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no'], true);
        }

        return (bool) $value;
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    private function hasProvidedUrlArgs(array $args, array $assocArgs): bool
    {
        if ($args !== []) {
            return true;
        }

        foreach (['url', 'urls'] as $key) {
            if (isset($assocArgs[$key]) && is_string($assocArgs[$key]) && trim($assocArgs[$key]) !== '') {
                return true;
            }
        }

        return false;
    }
}
