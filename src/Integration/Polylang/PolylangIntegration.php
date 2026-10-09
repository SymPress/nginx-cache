<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Integration\Polylang;

use SymPress\NginxCache\Purge\PurgeUrlCollector;

final class PolylangIntegration
{
    private bool $collecting = false;

    public function __construct(private readonly PurgeUrlCollector $collector)
    {
    }

    public function active(): bool
    {
        return function_exists('pll_get_post_translations');
    }

    /**
     * @param list<string> $urls
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function affectedUrls(array $urls, string $hook, array $arguments): array
    {
        $id = $this->collector->postId($hook, $arguments);
        if ($id === null || !function_exists('pll_get_post_translations') || $this->collecting) {
            return $urls;
        }
        $this->collecting = true;
        try {
            foreach (array_slice(pll_get_post_translations($id), 0, 20) as $translation) {
                $post = is_numeric($translation) ? get_post((int) $translation) : null;
                if (!$post instanceof \WP_Post || !is_post_publicly_viewable($post)) {
                    continue;
                }
                $url = get_permalink($post);
                if (!is_string($url)) {
                    continue;
                }

                $urls[] = $url;
            }
        } finally {
            $this->collecting = false;
        }
        return $urls;
    }

    /**
     * @param list<string> $urls
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function purgeUrls(array $urls, string $hook, array $arguments): array
    {
        if (!function_exists('pll_get_post_translations') || $this->collecting) {
            return $urls;
        }
        $this->collecting = true;
        try {
            $post = $this->collector->postId($hook, $arguments);
            if ($post !== null) {
                foreach (array_slice(pll_get_post_translations($post), 0, 20, true) as $language => $id) {
                    if (!is_numeric($id) || (int) $id < 1) {
                        continue;
                    }
                    $object = get_post((int) $id);
                    if (!$object instanceof \WP_Post || !is_post_publicly_viewable($object)) {
                        continue;
                    }
                    if ((int) $id !== $post) {
                        $urls = [...$urls, ...$this->collector->urlsForPost((int) $id)];
                    }
                    if (is_string($language) && function_exists('pll_home_url')) {
                        $home = pll_home_url($language);
                        if (is_string($home)) {
                            $urls[] = $home;
                        }
                    }
                    $page = (int) get_option('page_for_posts', 0);
                    if ($page <= 0 || !is_string($language) || !function_exists('pll_get_post')) {
                        continue;
                    }

                    $translated = pll_get_post($page, $language);
                    $link = $translated ? get_permalink($translated) : false;
                    if (!is_string($link)) {
                        continue;
                    }

                    $urls[] = $link;
                }
            }
            if (in_array($hook, ['created_term', 'edited_term', 'delete_term', 'clean_term_cache'], true) && function_exists('pll_get_term_translations')) {
                foreach (array_slice((array) ($arguments[0] ?? []), 0, 20) as $id) {
                    foreach (array_slice(pll_get_term_translations((int) $id), 0, 20) as $translation) {
                        if (!is_numeric($translation) || (int) $translation <= 0) {
                            continue;
                        }

                        $urls = [...$urls, ...$this->collector->urlsForTerm((int) $translation)];
                    }
                }
            }
            return $urls;
        } finally {
            $this->collecting = false;
        }
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    public function postTags(array $tags, int $id): array
    {
        if (!function_exists('pll_get_post_translations')) {
            return $tags;
        }
        return $this->group($tags, pll_get_post_translations($id), 'pll-group:');
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    public function termTags(array $tags, int $id): array
    {
        if (!$this->active() || !function_exists('pll_get_term_translations')) {
            return $tags;
        }
        return $this->group($tags, pll_get_term_translations($id), 'pll-term-group:');
    }

    /**
     * @param list<string> $tags
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function purgeTags(array $tags, string $hook, array $arguments): array
    {
        if (!$this->active()) {
            return $tags;
        }
        $id = $this->collector->postId($hook, $arguments);
        if ($id !== null) {
            $tags = $this->postTags($tags, $id);
        }
        if (in_array($hook, ['created_term', 'edited_term', 'delete_term', 'clean_term_cache'], true)) {
            foreach (array_slice((array) ($arguments[0] ?? []), 0, 20) as $term) {
                $tags = $this->termTags($tags, (int) $term);
            }
        }
        return $tags;
    }

    /**
     * @param list<string> $hooks
     * @return list<string>
     */
    public function actions(array $hooks): array
    {
        return $this->active() ? array_values(array_unique([...$hooks, 'pll_save_post', 'pll_add_language', 'pll_update_language', 'pll_update_default_lang', 'update_option_polylang'])) : $hooks;
    }

    /**
     * @param list<string> $hooks
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function fullHooks(array $hooks, string $hook = '', array $arguments = []): array
    {
        if (!$this->active()) {
            return $hooks;
        }
        $hooks = [...$hooks, 'pll_add_language', 'pll_update_language', 'pll_update_default_lang', 'update_option_polylang'];
        // Polylang 3.8.10 deletes its language taxonomy through core wp_delete_term.
        if ($hook === 'delete_term' && in_array($arguments[2] ?? '', ['language', 'term_language'], true)) {
            $hooks[] = 'delete_term';
        }
        return array_values(array_unique($hooks));
    }

    /**
     * @param list<string> $cookies
     * @return list<string>
     */
    public function cookies(array $cookies): array
    {
        if (!$this->active()) {
            return $cookies;
        }
        return $this->directoryMode() ? array_values(array_diff($cookies, ['pll_language'])) : array_values(array_unique([...$cookies, 'pll_language']));
    }

    /**
     * @param list<string> $uris
     * @return list<string>
     */
    public function uris(array $uris): array
    {
        $options = $this->options();
        return $this->active() && $this->directoryMode() && !empty($options['browser']) ? array_values(array_unique([...$uris, '^/$'])) : $uris;
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    public function queryAllowlist(array $patterns): array
    {
        return $this->active() && !$this->directoryMode() ? array_values(array_unique([...$patterns, '^lang=[a-zA-Z0-9_-]+$'])) : $patterns;
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    public function prewarmUrls(array $urls): array
    {
        if (!$this->active() || !function_exists('pll_languages_list') || !function_exists('pll_home_url')) {
            return $urls;
        }
        foreach (array_slice(pll_languages_list(['fields' => 'slug']), 0, 20) as $language) {
            if (!is_string($language)) {
                continue;
            }

            $home = pll_home_url($language);
            if (!is_string($home)) {
                continue;
            }

            $urls[] = $home;
        }
        return array_values(array_unique($urls));
    }

    /** @return array{active: bool, mode: string, warning: string} */
    public function diagnostic(): array
    {
        $active = $this->active();
        return ['active' => $active, 'mode' => !$active ? 'inactive' : ($this->directoryMode() ? 'url-language' : 'query-language'), 'warning' => $active && !$this->directoryMode() ? 'Polylang query-language mode retains lang in cache keys and bypasses language-cookie requests; path-based languages improve anonymous caching.' : ''];
    }

    private function directoryMode(): bool
    {
        return (int) ($this->options()['force_lang'] ?? 0) >= 1;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        $options = get_option('polylang', []);
        return is_array($options) ? $options : [];
    }

    /**
     * @param list<string> $tags
     * @param array<mixed> $translations
     * @return list<string>
     */
    private function group(array $tags, array $translations, string $prefix): array
    {
        $ids = array_values(array_filter(array_map('intval', array_values($translations)), static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            $tags[] = $prefix . min($ids);
        }
        return $tags;
    }
}
