<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Layer;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeResult;

final readonly class CacheLayerCoordinator
{
    public function __construct(
        private WordPressCacheSettings $settings,
    ) {
    }

    /** @return list<array{layer: string, flushed: bool, message: string}> */
    public function sync(PurgeResult $result): array
    {
        if (!$this->settings->layerSyncEnabled() || $result->dryRun || !$result->successful) {
            return [];
        }

        $layers = ['wordpress-hooks'];

        if (function_exists('apply_filters')) {
            $layers = (array) apply_filters('sympress_nginx_cache_sync_layers', $layers, $result);
        }

        return $this->flush($this->normalizeLayers($layers), $result);
    }

    /**
     * @param list<string> $layers
     * @return list<array{layer: string, flushed: bool, message: string}>
     */
    public function flush(array $layers = [], ?PurgeResult $result = null): array
    {
        $layers = $layers === [] ? ['object-cache', 'opcache', 'wordpress-hooks'] : $this->normalizeLayers($layers);
        $responses = [];

        foreach ($layers as $layer) {
            $responses[] = match ($layer) {
                'object-cache' => $this->flushObjectCache(),
                'opcache' => $this->flushOpcache(),
                'wordpress-hooks' => $this->flushHooks($result),
                default => [
                    'layer'   => $layer,
                    'flushed' => false,
                    'message' => 'Unknown cache layer.',
                ],
            };
        }

        return $responses;
    }

    /** @return array<string, bool> */
    public function available(): array
    {
        return [
            'object-cache'    => function_exists('wp_cache_flush'),
            'opcache'         => function_exists('opcache_reset'),
            'wordpress-hooks' => function_exists('do_action'),
        ];
    }

    /**
     * @param array<mixed> $layers
     * @return list<string>
     */
    private function normalizeLayers(array $layers): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $layer): string => is_string($layer) ? strtolower(trim($layer)) : '',
                        $layers,
                    ),
                    static fn (string $layer): bool => $layer !== '',
                ),
            ),
        );
    }

    /** @return array{layer: string, flushed: bool, message: string} */
    private function flushObjectCache(): array
    {
        if (!function_exists('wp_cache_flush')) {
            return [
                'layer'   => 'object-cache',
                'flushed' => false,
                'message' => 'WordPress object cache API is not available.',
            ];
        }

        try {
            $flushed = (bool) wp_cache_flush();
        } catch (\Throwable $exception) {
            return [
                'layer'   => 'object-cache',
                'flushed' => false,
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'layer'   => 'object-cache',
            'flushed' => $flushed,
            'message' => $flushed ? 'Object cache flushed.' : 'Object cache did not report success.',
        ];
    }

    /** @return array{layer: string, flushed: bool, message: string} */
    private function flushOpcache(): array
    {
        if (!function_exists('opcache_reset')) {
            return [
                'layer'   => 'opcache',
                'flushed' => false,
                'message' => 'OPcache reset is not available.',
            ];
        }

        $flushed = (bool) opcache_reset();

        return [
            'layer'   => 'opcache',
            'flushed' => $flushed,
            'message' => $flushed ? 'OPcache reset requested.' : 'OPcache did not report success.',
        ];
    }

    /** @return array{layer: string, flushed: bool, message: string} */
    private function flushHooks(?PurgeResult $result): array
    {
        if (!function_exists('do_action')) {
            return [
                'layer'   => 'wordpress-hooks',
                'flushed' => false,
                'message' => 'WordPress hooks are not available.',
            ];
        }

        do_action('sympress_nginx_cache_flush_layers', $result);

        return [
            'layer'   => 'wordpress-hooks',
            'flushed' => true,
            'message' => 'Layer sync hook dispatched.',
        ];
    }
}
