<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Inspection;

use SymPress\NginxCache\Integration\Polylang\PolylangIntegration;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\CacheStatus;

/**
 * Compares public page URLs with the local cache files, without HTTP requests, purges or prewarming.
 */
final readonly class PageCoverageInspector
{
    public const int LIMIT = 500;
    private const int NGINX_CACHE_VERSION = 5;

    public function __construct(
        private WordPressCacheSettings $settings,
        private CacheFileResolver $files,
        private CacheProbe $probe,
        private CacheClock $clock,
        private ?PolylangIntegration $polylang = null,
    ) {
    }

    /** @return array{state: string, total: int, checked: int, cached: int, expired: int, missing: int, excluded: int, unknown: int, measured_at: int} */
    public function inspect(CacheStatus $status): array
    {
        $local = (new CompatibilitySettings($this->settings))->string('purge_backend') === 'local_files';
        // shortcut: only the cache root's readability is verified; unreadable level directories read as "missing" until the status scan reports them.
        if (!$local || !$status->exists || !$status->directory || $status->error !== null || !is_readable($status->path) || !function_exists('get_permalink')) {
            return $this->classify($status->path, [], 0, 'unavailable');
        }
        $query = new \WP_Query([
            'post_type'              => array_values(array_diff(array_filter(get_post_types(), is_post_type_viewable(...)), ['attachment'])),
            'post_status'            => 'publish',
            'posts_per_page'         => self::LIMIT,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            // Polylang: every language version is its own public URL.
            'lang'                   => '',
        ]);
        $urls = $this->polylang?->prewarmUrls([home_url('/')]) ?? [home_url('/')];
        $posts = is_array($query->posts) ? $query->posts : [];
        foreach ($posts as $post) {
            $url = get_permalink($post);
            if (!is_string($url) || $url === '') {
                continue;
            }
            $urls[] = $url;
        }

        return $this->classify($status->path, array_values(array_unique($urls)), (int) max(0, $query->found_posts - count($posts)));
    }

    /**
     * @param list<string> $urls
     * @return array{state: string, total: int, checked: int, cached: int, expired: int, missing: int, excluded: int, unknown: int, measured_at: int}
     */
    public function classify(string $cachePath, array $urls, int $unchecked = 0, string $state = 'ok'): array
    {
        $now = $this->clock->timestamp();
        $states = array_count_values(array_map(fn (string $url): string => $this->pageState($cachePath, $url, $now), $urls));

        return ['state' => $state, 'total' => count($urls) + $unchecked, 'checked' => count($urls), 'cached' => $states['cached'] ?? 0, 'expired' => $states['expired'] ?? 0, 'missing' => $states['missing'] ?? 0, 'excluded' => $states['excluded'] ?? 0, 'unknown' => $states['unknown'] ?? 0, 'measured_at' => $now];
    }

    private function pageState(string $cachePath, string $url, int $now): string
    {
        if ($this->probe->bypassReasons($url, '') !== []) {
            return 'excluded';
        }
        // Key variants (scheme, GET/HEAD) of one URL count as one page; the freshest entry wins.
        $state = 'missing';
        foreach ($this->files->candidates($cachePath, $url) as $file) {
            if (!is_file($file)) {
                continue;
            }
            $validUntil = $this->validUntil($file);
            if ($validUntil === null) {
                $state = $state === 'missing' ? 'unknown' : $state;
                continue;
            }
            if ($validUntil >= $now) {
                return 'cached';
            }
            $state = 'expired';
        }

        return $state;
    }

    private function validUntil(string $file): ?int
    {
        // ngx_http_file_cache_header_t on 64-bit: ngx_uint_t version, then time_t valid_sec.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read 16 header bytes of a local Nginx cache file.
        $header = is_readable($file) ? file_get_contents($file, false, null, 0, 16) : false;
        $fields = is_string($header) && strlen($header) === 16 ? unpack('Pversion/Pvalid', $header) : false;

        return is_array($fields) && $fields['version'] === self::NGINX_CACHE_VERSION ? (int) $fields['valid'] : null;
    }
}
