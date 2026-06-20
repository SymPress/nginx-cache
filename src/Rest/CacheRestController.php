<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Rest;

use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Inspection\CacheProbe;
use SymPress\NginxCache\Inspection\Diagnostics;
use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Value\PurgeRequest;
use WP_REST_Request;
use WP_REST_Response;

final readonly class CacheRestController
{
    private const string NAMESPACE = 'sympress-nginx-cache/v1';

    public function __construct(
        private WordPressCacheSettings $settings,
        private CacheManager $cache,
        private PurgeQueueProcessor $queue,
        private Diagnostics $diagnostics,
        private NginxConfigGenerator $config,
        private CacheProbe $probe,
        private CacheLayerCoordinator $layers,
        private TagIndexRepository $tags,
        private UrlPolicy $urlPolicy,
    ) {
    }

    public function register(): void
    {
        if (!$this->settings->restEnabled() || !function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => $this->status(...),
            'permission_callback' => $this->permission(...),
        ]);

        register_rest_route(self::NAMESPACE, '/purge', [
            'methods'             => 'POST',
            'callback'            => $this->purge(...),
            'permission_callback' => $this->permission(...),
        ]);

        register_rest_route(self::NAMESPACE, '/queue/flush', [
            'methods'             => 'POST',
            'callback'            => $this->flushQueue(...),
            'permission_callback' => $this->permission(...),
        ]);

        register_rest_route(self::NAMESPACE, '/config', [
            'methods'             => 'GET',
            'callback'            => $this->config(...),
            'permission_callback' => $this->permission(...),
        ]);

        register_rest_route(self::NAMESPACE, '/probe', [
            'methods'             => 'POST',
            'callback'            => $this->probe(...),
            'permission_callback' => $this->permission(...),
        ]);

        register_rest_route(self::NAMESPACE, '/layers/flush', [
            'methods'             => 'POST',
            'callback'            => $this->flushLayers(...),
            'permission_callback' => $this->permission(...),
        ]);

        register_rest_route(self::NAMESPACE, '/tags/clear', [
            'methods'             => 'POST',
            'callback'            => $this->clearTags(...),
            'permission_callback' => $this->permission(...),
        ]);
    }

    public function permission(): bool
    {
        return current_user_can('manage_options');
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->diagnostics->report());
    }

    public function purge(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $purgeRequest = $this->requestFromRest($request);
        } catch (\InvalidArgumentException $exception) {
            return new WP_REST_Response([
                'error' => $exception->getMessage(),
            ], 400);
        }

        $queued = (bool) $request->get_param('queue');

        if ($queued) {
            $this->queue->enqueue($purgeRequest);

            return new WP_REST_Response([
                'queued'  => true,
                'pending' => $this->queue->count(),
            ], 202);
        }

        return new WP_REST_Response($this->cache->purgeConfiguredPath($purgeRequest)->toArray());
    }

    public function flushQueue(WP_REST_Request $request): WP_REST_Response
    {
        $this->queue->process();

        return new WP_REST_Response([
            'flushed' => true,
            'pending' => $this->queue->count(),
        ]);
    }

    public function config(WP_REST_Request $request): WP_REST_Response
    {
        $config = $this->config->generate();

        return new WP_REST_Response([
            'profile'            => $this->settings->profile()->value,
            'config'             => $config,
            'missing_directives' => $this->config->validate($config),
        ]);
    }

    public function probe(WP_REST_Request $request): WP_REST_Response
    {
        $url = $this->urlPolicy->normalizeSameOriginHttpUrl($this->rawStringParam($request->get_param('url'), ''));

        if ($url === '') {
            return new WP_REST_Response([
                'error' => 'A same-origin HTTP(S) URL is required.',
            ], 400);
        }

        $cookie = $this->rawStringParam($request->get_param('cookie'), '');

        if ($cookie !== '' && (strlen($cookie) > 4096 || preg_match('/[\r\n]/', $cookie) === 1)) {
            return new WP_REST_Response([
                'error' => 'Cookie header is not valid.',
            ], 400);
        }

        return new WP_REST_Response($this->probe->probe($url, $cookie));
    }

    public function flushLayers(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'layers' => $this->layers->flush($this->strings($request->get_param('layers'))),
        ]);
    }

    public function clearTags(WP_REST_Request $request): WP_REST_Response
    {
        $this->tags->clear();

        return new WP_REST_Response([
            'cleared' => true,
            'stats'   => $this->tags->stats(),
        ]);
    }

    private function requestFromRest(WP_REST_Request $request): PurgeRequest
    {
        $rawUrls = $request->get_param('urls');
        $urls = $this->urls($rawUrls);
        $reason = $this->stringParam($request->get_param('reason'), 'rest');
        $dryRun = (bool) $request->get_param('dry_run');
        $prewarm = (bool) $request->get_param('prewarm');
        $mode = $this->stringParam($request->get_param('mode'), $urls === [] ? 'full' : 'urls');

        if (($mode === 'urls' || $this->hasProvidedUrls($rawUrls)) && $urls === []) {
            throw new \InvalidArgumentException('At least one same-origin URL is required for URL purge.');
        }

        if ($mode === 'urls' && $urls !== []) {
            return PurgeRequest::urls($urls, $reason, 'rest', $dryRun, $prewarm);
        }

        return PurgeRequest::full($reason, 'rest', $dryRun, $prewarm);
    }

    /** @return list<string> */
    private function urls(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_slice(
            array_values(
                array_filter(
                    array_map(
                        fn (mixed $url): string => $this->urlPolicy->normalizeSameOriginHttpUrl($url),
                        $value,
                    ),
                    static fn (string $url): bool => $url !== '',
                ),
            ),
            0,
            500,
        );
    }

    private function stringParam(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? sanitize_key($value) : $fallback;
    }

    private function rawStringParam(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    private function hasProvidedUrls(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_array($value) && $value !== [];
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
}
