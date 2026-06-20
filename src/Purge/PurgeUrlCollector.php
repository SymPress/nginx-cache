<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Surrogate\TagIndexRepository;

final readonly class PurgeUrlCollector
{
    public function __construct(
        private CacheTagResolver $tags,
        private TagIndexRepository $tagIndex,
        private UrlPolicy $urls,
    ) {
    }

    /**
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function collect(string $hook, array $arguments): array
    {
        $urls = [];
        $postId = $this->postId($arguments);

        if ($postId !== null) {
            $urls = [...$urls, ...$this->postUrls($postId)];
        }

        $termId = $this->termId($hook, $arguments);

        if ($termId !== null) {
            $urls = [...$urls, ...$this->termUrls($termId)];
        }

        $commentPostId = $this->commentPostId($hook, $arguments);

        if ($commentPostId !== null) {
            $urls = [...$urls, ...$this->postUrls($commentPostId)];
        }

        $tags = $this->collectTags($hook, $arguments);

        if ($tags !== []) {
            $urls = [...$urls, ...$this->tagIndex->urlsForTags($tags)];
        }

        if ($urls !== [] && function_exists('home_url')) {
            $urls[] = home_url('/');
            $urls[] = home_url('/feed/');
            $urls[] = home_url('/wp-sitemap.xml');
        }

        if (function_exists('apply_filters')) {
            $urls = (array) apply_filters('sympress_nginx_cache_purge_urls', $urls, $hook, $arguments);
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map($this->urls->normalizeSameOriginHttpUrl(...), $urls),
                    static fn (string $url): bool => $url !== '',
                ),
            ),
        );
    }

    /**
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function collectTags(string $hook, array $arguments): array
    {
        $tags = [$hook];
        $postId = $this->postId($arguments);

        if ($postId !== null) {
            $tags = [...$tags, ...$this->tags->postTags($postId)];
        }

        $termId = $this->termId($hook, $arguments);

        if ($termId !== null) {
            $tags = [...$tags, ...$this->tags->termTags($termId)];
        }

        $commentPostId = $this->commentPostId($hook, $arguments);

        if ($commentPostId !== null) {
            $tags = [...$tags, ...$this->tags->postTags($commentPostId)];
        }

        if (function_exists('apply_filters')) {
            $tags = (array) apply_filters('sympress_nginx_cache_purge_tags', $tags, $hook, $arguments);
        }

        return $this->tags->normalize($tags);
    }

    public function requiresFullPurge(string $hook): bool
    {
        $fullHooks = [
            'switch_theme',
            'customize_save_after',
            'wp_update_nav_menu',
            'wp_create_nav_menu',
            'wp_delete_nav_menu',
            'edit_user_profile_update',
            'update_option_permalink_structure',
            'woocommerce_delete_product_transients',
        ];

        if (function_exists('apply_filters')) {
            $fullHooks = (array) apply_filters('sympress_nginx_cache_full_purge_hooks', $fullHooks);
        }

        return in_array($hook, $fullHooks, true);
    }

    /** @param array<mixed> $arguments */
    private function postId(array $arguments): ?int
    {
        foreach ($arguments as $argument) {
            if (is_int($argument) && function_exists('get_post') && get_post($argument) !== null) {
                return $argument;
            }

            if (is_object($argument) && property_exists($argument, 'ID') && is_numeric($argument->ID)) {
                return (int) $argument->ID;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function postUrls(int $postId): array
    {
        $urls = [];

        if (function_exists('get_permalink')) {
            $permalink = get_permalink($postId);

            if (is_string($permalink)) {
                $urls[] = $permalink;
            }
        }

        $postType = function_exists('get_post_type') ? get_post_type($postId) : null;

        if (function_exists('rest_url')) {
            $urls[] = rest_url(sprintf('wp/v2/%s/%d', is_string($postType) ? $this->restBase($postType) : 'posts', $postId));
        }

        if (is_string($postType) && function_exists('get_post_type_archive_link')) {
            $archive = get_post_type_archive_link($postType);

            if (is_string($archive)) {
                $urls[] = $archive;
            }

            if (function_exists('home_url')) {
                $urls[] = home_url(sprintf('/wp-sitemap-posts-%s-1.xml', $postType));
            }
        }

        if (function_exists('get_post') && function_exists('get_author_posts_url')) {
            $post = get_post($postId);

            if (is_object($post) && property_exists($post, 'post_author') && is_numeric($post->post_author)) {
                $urls[] = get_author_posts_url((int) $post->post_author);
            }
        }

        if (function_exists('get_object_taxonomies') && function_exists('get_the_terms') && function_exists('get_term_link') && is_string($postType)) {
            foreach (get_object_taxonomies($postType) as $taxonomy) {
                if (!is_string($taxonomy)) {
                    continue;
                }

                $terms = get_the_terms($postId, $taxonomy);

                if (!is_array($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    $termUrl = get_term_link($term);

                    if (!is_string($termUrl)) {
                        continue;
                    }

                    $urls[] = $termUrl;
                }
            }
        }

        if (function_exists('get_post_comments_feed_link')) {
            $feed = get_post_comments_feed_link($postId);

            if (is_string($feed)) {
                $urls[] = $feed;
            }
        }

        return $urls;
    }

    /** @param array<mixed> $arguments */
    private function termId(string $hook, array $arguments): ?int
    {
        if (!in_array($hook, ['created_term', 'edited_term', 'delete_term'], true)) {
            return null;
        }

        $termId = $arguments[0] ?? null;

        return is_numeric($termId) ? (int) $termId : null;
    }

    /** @return list<string> */
    private function termUrls(int $termId): array
    {
        if (!function_exists('get_term_link')) {
            return [];
        }

        $url = get_term_link($termId);
        $urls = is_string($url) ? [$url] : [];

        if (function_exists('get_term_feed_link')) {
            $feed = get_term_feed_link($termId);

            if (is_string($feed)) {
                $urls[] = $feed;
            }
        }

        $term = function_exists('get_term') ? get_term($termId) : null;

        if (is_object($term) && property_exists($term, 'taxonomy') && is_string($term->taxonomy) && function_exists('home_url')) {
            $urls[] = home_url(sprintf('/wp-sitemap-taxonomies-%s-1.xml', $term->taxonomy));
        }

        return $urls;
    }

    /** @param array<mixed> $arguments */
    private function commentPostId(string $hook, array $arguments): ?int
    {
        if (!in_array($hook, ['comment_post', 'edit_comment', 'delete_comment', 'wp_set_comment_status'], true)) {
            return null;
        }

        $commentId = $arguments[0] ?? null;

        if (!is_numeric($commentId) || !function_exists('get_comment')) {
            return null;
        }

        $comment = get_comment((int) $commentId);

        if (!is_object($comment) || !property_exists($comment, 'comment_post_ID')) {
            return null;
        }

        return (int) $comment->comment_post_ID;
    }

    private function restBase(string $postType): string
    {
        if (!function_exists('get_post_type_object')) {
            return $postType === 'page' ? 'pages' : 'posts';
        }

        $object = get_post_type_object($postType);

        if (is_object($object) && property_exists($object, 'rest_base') && is_string($object->rest_base) && $object->rest_base !== '') {
            return $object->rest_base;
        }

        return $postType === 'page' ? 'pages' : 'posts';
    }
}
