<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Surrogate\TagIndexRepository;

final readonly class PurgeUrlCollector
{
    public function __construct(
        private CacheTagResolver $tags,
        private TagIndexRepository $tagIndex,
        private UrlPolicy $urls,
        private WordPressCacheSettings $settings,
    ) {
    }

    /**
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function collect(string $hook, array $arguments): array
    {
        $urls = [];
        $productIds = $this->productIds($hook, $arguments);

        foreach ($productIds as $productId) {
            $urls = [...$urls, ...$this->postUrls($productId)];
        }

        $postId = $this->postId($arguments);

        if ($postId !== null && !in_array($postId, $productIds, true)) {
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
        $productIds = $this->productIds($hook, $arguments);

        foreach ($productIds as $productId) {
            $tags = [...$tags, ...$this->tags->postTags($productId)];
        }

        $postId = $this->postId($arguments);

        if ($postId !== null && !in_array($postId, $productIds, true)) {
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
            'upgrader_process_complete',
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

            if (!is_object($argument) || !method_exists($argument, 'get_id')) {
                continue;
            }

            $id = $argument->get_id();

            if (is_numeric($id)) {
                return (int) $id;
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
                $urls = [...$urls, ...$this->ampUrls($permalink)];
            }
        }

        $postType = function_exists('get_post_type') ? get_post_type($postId) : null;

        if (function_exists('rest_url')) {
            $urls[] = rest_url(sprintf('wp/v2/%s/%d', is_string($postType) ? $this->restBase($postType) : 'posts', $postId));
        }

        if (is_string($postType) && function_exists('get_post_type_archive_link')) {
            $archive = get_post_type_archive_link($postType);

            if (is_string($archive)) {
                $urls = [...$urls, ...$this->archiveUrls($archive)];
            }

            if (function_exists('home_url')) {
                $urls[] = home_url(sprintf('/wp-sitemap-posts-%s-1.xml', $postType));
            }
        }

        if ($postType === 'post') {
            $urls = [...$urls, ...$this->postsPageUrls()];
            $urls = [...$urls, ...$this->dateArchiveUrls($postId)];
        }

        if (function_exists('get_post') && function_exists('get_author_posts_url')) {
            $post = get_post($postId);

            if (is_object($post) && property_exists($post, 'post_author') && is_numeric($post->post_author)) {
                $urls = [...$urls, ...$this->archiveUrls(get_author_posts_url((int) $post->post_author))];
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

                    $urls = [...$urls, ...$this->archiveUrls($termUrl)];
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
        $urls = is_string($url) ? $this->archiveUrls($url) : [];

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

    /**
     * @param array<mixed> $arguments
     * @return list<int>
     */
    private function productIds(string $hook, array $arguments): array
    {
        if (!str_starts_with($hook, 'woocommerce_')) {
            return [];
        }

        $ids = [];

        foreach ($arguments as $argument) {
            if (is_int($argument) && $argument > 0) {
                $ids[] = $argument;
            }

            if (!is_object($argument)) {
                continue;
            }

            $ids = [...$ids, ...$this->productIdsFromObject($argument)];

            if (!method_exists($argument, 'get_items')) {
                continue;
            }

            foreach ((array) $argument->get_items() as $item) {
                if (!is_object($item) || !method_exists($item, 'get_product')) {
                    continue;
                }

                $product = $item->get_product();

                if (!is_object($product)) {
                    continue;
                }

                $ids = [...$ids, ...$this->productIdsFromObject($product)];
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /** @return list<int> */
    private function productIdsFromObject(object $object): array
    {
        $ids = [];

        if (method_exists($object, 'get_id')) {
            $id = $object->get_id();

            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        if (method_exists($object, 'get_parent_id')) {
            $parentId = $object->get_parent_id();

            if (is_numeric($parentId) && (int) $parentId > 0) {
                $ids[] = (int) $parentId;
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private function postsPageUrls(): array
    {
        if (!function_exists('get_option') || !function_exists('get_permalink')) {
            return [];
        }

        $postsPageId = (int) get_option('page_for_posts', 0);

        if ($postsPageId <= 0) {
            return [];
        }

        $url = get_permalink($postsPageId);

        return is_string($url) ? $this->archiveUrls($url) : [];
    }

    /** @return list<string> */
    private function dateArchiveUrls(int $postId): array
    {
        if (!function_exists('get_the_time') || !function_exists('get_year_link')) {
            return [];
        }

        $year = (int) get_the_time('Y', $postId);
        $month = (int) get_the_time('m', $postId);
        $day = (int) get_the_time('d', $postId);
        $urls = [];

        if ($year > 0) {
            $urls = [...$urls, ...$this->archiveUrls(get_year_link($year))];
        }

        if ($year > 0 && $month > 0 && function_exists('get_month_link')) {
            $urls = [...$urls, ...$this->archiveUrls(get_month_link($year, $month))];
        }

        if ($year > 0 && $month > 0 && $day > 0 && function_exists('get_day_link')) {
            $urls = [...$urls, ...$this->archiveUrls(get_day_link($year, $month, $day))];
        }

        return $urls;
    }

    /** @return list<string> */
    private function archiveUrls(string $url): array
    {
        $urls = [$url];
        $limit = $this->settings->archivePageLimit();

        for ($page = 2; $page <= $limit; ++$page) {
            $urls[] = sprintf('%s/page/%d/', rtrim($url, '/'), $page);
        }

        return [...$urls, ...$this->feedUrls($urls)];
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function feedUrls(array $urls): array
    {
        if (!$this->settings->purgeFeedsEnabled()) {
            return [];
        }

        $feeds = [];

        foreach ($urls as $url) {
            foreach ($this->settings->feedVariants() as $variant) {
                $feeds[] = sprintf('%s/%s', rtrim($url, '/'), $variant);

                if (count($feeds) >= $this->settings->maxFeedUrls()) {
                    return $feeds;
                }
            }
        }

        return $feeds;
    }

    /** @return list<string> */
    private function ampUrls(string $url): array
    {
        if (!$this->settings->purgeAmpEnabled()) {
            return [];
        }

        return [sprintf('%s/amp/', rtrim($url, '/'))];
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
