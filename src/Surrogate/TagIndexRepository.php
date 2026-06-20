<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Surrogate;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Time\CacheClock;

final readonly class TagIndexRepository
{
    private const string OPTION_INDEX = 'sympress_nginx_cache_tag_index';
    private const string CACHE_GROUP = 'sympress_nginx_cache';
    private const int MAX_TAGS = 1000;
    private const int MAX_URLS_PER_TAG = 50;

    public function __construct(
        private CacheTagResolver $tags,
        private UrlPolicy $urls,
        private CacheClock $clock,
    ) {
    }

    /** @param list<string> $tags */
    public function remember(string $url, array $tags): void
    {
        $url = $this->normalizeUrl($url);

        if ($url === [] || !function_exists('update_option')) {
            return;
        }

        $index = $this->index();
        $normalizedTags = $this->tags->normalize($tags);
        $timestamp = $this->clock->timestamp();

        foreach ($index as $tag => $urls) {
            unset($index[$tag][$url[0]]);

            if ($index[$tag] !== []) {
                continue;
            }

            unset($index[$tag]);
        }

        foreach ($normalizedTags as $tag) {
            $index[$tag] ??= [];
            $index[$tag][$url[0]] = $timestamp;
            arsort($index[$tag]);
            $index[$tag] = array_slice($index[$tag], 0, self::MAX_URLS_PER_TAG, true);
        }

        $this->persist($this->prune($index));
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    public function urlsForTags(array $tags): array
    {
        $urls = [];
        $index = $this->index();

        foreach ($this->tags->normalize($tags) as $tag) {
            if (!isset($index[$tag])) {
                continue;
            }

            $urls = [...$urls, ...array_keys($index[$tag])];
        }

        return array_values(array_unique($urls));
    }

    /** @param list<string> $urls */
    public function forgetUrls(array $urls): void
    {
        $normalizedUrls = [];

        foreach ($urls as $url) {
            $normalizedUrls = [...$normalizedUrls, ...$this->normalizeUrl($url)];
        }

        if ($normalizedUrls === [] || !function_exists('update_option')) {
            return;
        }

        $index = $this->index();

        foreach ($index as $tag => $tagUrls) {
            foreach ($normalizedUrls as $url) {
                unset($tagUrls[$url]);
            }

            if ($tagUrls === []) {
                unset($index[$tag]);
            } else {
                $index[$tag] = $tagUrls;
            }
        }

        $this->persist($index);
    }

    public function clear(): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_INDEX);
        }

        if (!function_exists('wp_cache_delete')) {
            return;
        }

        wp_cache_delete(self::OPTION_INDEX, self::CACHE_GROUP);
    }

    /** @return array{tags: int, urls: int} */
    public function stats(): array
    {
        $index = $this->index();
        $urls = [];

        foreach ($index as $tagUrls) {
            $urls = [...$urls, ...array_keys($tagUrls)];
        }

        return [
            'tags' => count($index),
            'urls' => count(array_unique($urls)),
        ];
    }

    /** @return array<string, array<string, int>> */
    private function index(): array
    {
        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get(self::OPTION_INDEX, self::CACHE_GROUP);

            if (is_array($cached)) {
                return $this->normalizeIndex($cached);
            }
        }

        if (!function_exists('get_option')) {
            return [];
        }

        $index = get_option(self::OPTION_INDEX, []);
        $index = is_array($index) ? $this->normalizeIndex($index) : [];

        if (function_exists('wp_cache_set')) {
            wp_cache_set(self::OPTION_INDEX, $index, self::CACHE_GROUP);
        }

        return $index;
    }

    /** @param array<string, array<string, int>> $index */
    private function persist(array $index): void
    {
        if ($index === []) {
            $this->clear();

            return;
        }

        update_option(self::OPTION_INDEX, $index, false);

        if (!function_exists('wp_cache_set')) {
            return;
        }

        wp_cache_set(self::OPTION_INDEX, $index, self::CACHE_GROUP);
    }

    /**
     * @param array<string, array<string, int>> $index
     * @return array<string, array<string, int>>
     */
    private function prune(array $index): array
    {
        if (count($index) <= self::MAX_TAGS) {
            return $index;
        }

        uasort(
            $index,
            static fn (array $left, array $right): int => max($right ?: [0]) <=> max($left ?: [0]),
        );

        return array_slice($index, 0, self::MAX_TAGS, true);
    }

    /**
     * @param array<mixed> $index
     * @return array<string, array<string, int>>
     */
    private function normalizeIndex(array $index): array
    {
        $normalized = [];

        foreach ($index as $tag => $urls) {
            if (!is_string($tag) || !is_array($urls)) {
                continue;
            }

            foreach ($urls as $url => $timestamp) {
                if (!is_string($url) || !is_numeric($timestamp)) {
                    continue;
                }

                $normalized[$tag][$url] = (int) $timestamp;
            }
        }

        return $normalized;
    }

    /** @return list<string> */
    private function normalizeUrl(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            return [];
        }

        $url = $this->urls->normalizeSameOriginHttpUrl($url);

        return $url !== '' ? [$url] : [];
    }
}
