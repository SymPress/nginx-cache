<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Inspection;

use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Purge\PurgeHistoryRepository;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\TagIndexRepository;

final readonly class Diagnostics
{
    public function __construct(
        private WordPressCacheSettings $settings,
        private CacheStatusInspector $inspector,
        private PurgeHistoryRepository $history,
        private PurgeQueueProcessor $queue,
        private EnvironmentDetector $environment,
        private NginxConfigGenerator $config,
        private TagIndexRepository $tags,
        private CacheLayerCoordinator $layers,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $status = $this->inspector->inspect($this->settings->cachePath());

        return [
            'settings'     => $this->settings->config(),
            'status'       => [
                'path'          => $status->path,
                'available'     => $status->available(),
                'exists'        => $status->exists,
                'directory'     => $status->directory,
                'writable'      => $status->writable,
                'files'         => $status->files,
                'directories'   => $status->directories,
                'bytes'         => $status->bytes,
                'size'          => $status->formattedSize(),
                'scan_complete' => $status->scanComplete,
                'error'         => $status->error,
            ],
            'queue'        => [
                'pending' => $this->queue->count(),
            ],
            'tag_index'    => $this->tags->stats(),
            'layers'       => [
                'sync_enabled' => $this->settings->layerSyncEnabled(),
                'available'    => $this->layers->available(),
            ],
            'remote'       => [
                'endpoints'  => count($this->settings->remoteEndpoints()),
                'signed'     => $this->settings->remoteSecret() !== null,
                'cloudflare' => [
                    'enabled'    => $this->settings->cloudflareEnabled(),
                    'configured' => $this->settings->cloudflareConfigured(),
                    'zone_id'    => $this->settings->cloudflareZoneId() !== null,
                    'api_token'  => $this->settings->cloudflareApiToken() !== null,
                ],
                'full_purge' => [
                    'mode'        => $this->settings->fullPurgeMode(),
                    'endpoint'    => $this->settings->fullPurgeEndpoint() !== null,
                    'http_method' => $this->settings->fullPurgeHttpMethod(),
                ],
            ],
            'nginx_config' => [
                'profile'            => $this->settings->profile()->value,
                'missing_directives' => $this->config->validate($this->config->generate()),
            ],
            'last_purge'   => $this->history->last(),
            'environment'  => $this->environment->detect(),
            'wordpress'    => [
                'multisite' => function_exists('is_multisite') && is_multisite(),
                'blog_id'   => function_exists('get_current_blog_id') ? get_current_blog_id() : null,
            ],
        ];
    }
}
