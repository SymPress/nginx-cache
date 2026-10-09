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
        private ?PurgeRules $rules = null,
    ) {
    }

    /**
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function collect(string $hook, array $arguments): array
    {
        $urls = [];
        $affectedPostIds = [];
        $productIds = $this->publicProductIds($hook, $arguments);
        $affectedPostIds = [...$affectedPostIds, ...$productIds];

        foreach ($productIds as $productId) {
            $urls = [...$urls, ...$this->postUrls($productId)];
        }

        $postId = $this->postId($hook, $arguments);
        if ($postId !== null) {
            $affectedPostIds[] = $postId;
        }

        if ($postId !== null && !in_array($postId, $productIds, true)) {
            $urls = [...$urls, ...$this->postUrls($postId)];
        }

        foreach ($this->termIds($hook, $arguments) as $termId) {
            $urls = [...$urls, ...$this->termUrls($termId)];
        }

        foreach ($this->commentPostIds($hook, $arguments) as $commentPostId) {
            $affectedPostIds[] = $commentPostId;
            $urls = [...$urls, ...$this->postUrls($commentPostId)];
        }

        $userId = $this->userId($hook, $arguments);
        if ($userId !== null) {
            if (function_exists('get_author_posts_url')) {
                $urls = [...$urls, ...$this->archiveUrls(get_author_posts_url($userId))];
            }
            if (function_exists('rest_url')) {
                $urls[] = rest_url('wp/v2/users/' . $userId);
            }
        }

        $tags = $this->collectTags($hook, $arguments);

        if ($tags !== [] && !($this->rules?->limited($hook, $arguments) ?? false)) {
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

        $urls = array_values(array_filter($urls, is_string(...)));
        $urls = $this->rules?->filter($hook, $arguments, $urls, array_values(array_unique($affectedPostIds))) ?? $urls;

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
        if ($this->rules?->limited($hook, $arguments)) {
            // URL purges preserve disabled scopes; shared tags could widen them again.
            return [];
        }
        $tags = [$hook];
        $productIds = $this->publicProductIds($hook, $arguments);

        foreach ($productIds as $productId) {
            $tags = [...$tags, ...['post:' . $productId, 'rest:post:' . $productId]];
        }

        $postId = $this->postId($hook, $arguments);

        if ($postId !== null && !in_array($postId, $productIds, true)) {
            $tags = [...$tags, ...['post:' . $postId, 'rest:post:' . $postId]];
        }

        foreach ($this->termIds($hook, $arguments) as $termId) {
            $tags = [...$tags, ...$this->tags->termTags($termId)];
        }

        foreach ($this->commentPostIds($hook, $arguments) as $commentPostId) {
            $tags = [...$tags, ...['post:' . $commentPostId, 'rest:post:' . $commentPostId]];
        }

        if (function_exists('apply_filters')) {
            $tags = (array) apply_filters('sympress_nginx_cache_purge_tags', $tags, $hook, $arguments);
        }

        $userId = $this->userId($hook, $arguments);
        if ($userId !== null) {
            $tags = [...$tags, ...$this->tags->userTags($userId)];
        }
        // Site/collection tags appear on unrelated pages and must never widen a targeted purge.
        return array_values(array_filter($this->tags->normalize($tags), static fn (string $tag): bool => !str_starts_with($tag, 'site:')
            && !str_starts_with($tag, 'post_type:') && !str_starts_with($tag, 'taxonomy:')
            && !str_ends_with($tag, ':collection') && !in_array($tag, ['posts', 'archive', 'comments'], true)));
    }

    /**
     * @param array<mixed> $arguments
     * @return list<string>
     */
    public function affectedUrls(string $hook, array $arguments): array
    {
        $urls = [];
        $post = $this->postId($hook, $arguments);
        $ids = array_values(array_unique([...($post !== null ? [$post] : []), ...$this->publicProductIds($hook, $arguments), ...$this->commentPostIds($hook, $arguments)]));
        foreach ($ids as $id) {
            $url = function_exists('get_permalink') ? get_permalink($id) : false;
            if (!is_string($url)) {
                continue;
            }

            $urls[] = $url;
        }
        foreach ($this->termIds($hook, $arguments) as $id) {
            $url = function_exists('get_term_link') ? get_term_link($id) : false;
            if (!is_string($url)) {
                continue;
            }

            $urls[] = $url;
        }
        if (function_exists('apply_filters')) {
            $urls = (array) apply_filters('sympress_nginx_cache_affected_urls', $urls, $hook, $arguments);
        }
        return array_values(array_unique(array_filter(array_map($this->urls->normalizeSameOriginHttpUrl(...), array_filter($urls, is_string(...))))));
    }

    /** @param array<mixed> $arguments */
    public function requiresFullPurge(string $hook, array $arguments = []): bool
    {
        $fullHooks = [
            'switch_theme',
            'customize_save_after',
            'wp_update_nav_menu',
            'wp_create_nav_menu',
            'wp_delete_nav_menu',
            'update_option_permalink_structure',
            'upgrader_process_complete',
        ];

        if (function_exists('apply_filters')) {
            $fullHooks = (array) apply_filters('sympress_nginx_cache_full_purge_hooks', $fullHooks, $hook, $arguments);
        }

        return in_array($hook, $fullHooks, true);
    }

    /** @param array<mixed> $arguments */
    public function postId(string $hook, array $arguments): ?int
    {
        $postHooks = [
        'publish_post', 'save_post', 'pll_save_post', 'edit_post', 'before_delete_post', 'deleted_post',
            'delete_post', 'trashed_post', 'untrashed_post', 'transition_post_status', 'delete_attachment', 'clean_post_cache',
        ];
        if (!in_array($hook, $postHooks, true)) {
            return null;
        }
        $value = $hook === 'transition_post_status' ? ($arguments[2] ?? null) : ($arguments[0] ?? null);
        if (is_object($value) && isset($value->ID) && is_numeric($value->ID)) {
            return (int) $value->ID;
        }
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param array<mixed> $arguments */
    public function publicMutation(string $hook, array $arguments): bool
    {
        $id = $this->postId($hook, $arguments);
        if ($id === null) {
            $comments = $this->commentPostIds($hook, $arguments);
            if ($comments !== []) {
                foreach ($comments as $commentPostId) {
                    $post = function_exists('get_post') ? get_post($commentPostId) : null;
                    if (is_object($post) && $post->post_status === 'publish') {
                        return true;
                    }
                }
                return false;
            }
            $products = $this->productIds($hook, $arguments);
            if ($products === []) {
                return true;
            }
            foreach ($products as $productId) {
                $product = function_exists('get_post') ? get_post($productId) : null;
                if (is_object($product) && $product->post_status === 'publish') {
                    return true;
                }
            }
            return false;
        }
        if ($hook === 'transition_post_status') {
            return ($arguments[0] ?? null) === 'publish' || ($arguments[1] ?? null) === 'publish';
        }
        $post = $arguments[1] ?? null;
        if (!is_object($post) || !isset($post->post_status)) {
            $post = function_exists('get_post') ? get_post($id) : null;
        }
        if (is_object($post) && isset($post->post_status)) {
            return $post->post_status === 'publish' || ($hook === 'trashed_post' && ($arguments[1] ?? null) === 'publish');
        }
        return false;
    }

    /** @param array<mixed> $arguments */
    private function userId(string $hook, array $arguments): ?int
    {
        if (!in_array($hook, ['clean_user_cache', 'edit_user_profile_update', 'profile_update', 'deleted_user'], true)) {
            return null;
        }
        $id = $arguments[0] ?? null;
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * Collect an object's related public URLs without invoking purge filters.
     *
     * @return list<string>
     */
    public function urlsForPost(int $postId): array
    {
        return $this->normalizeUrls($this->postUrls($postId));
    }

    /** @return list<string> */
    public function urlsForTerm(int $termId): array
    {
        return $this->normalizeUrls($this->termUrls($termId));
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function normalizeUrls(array $urls): array
    {
        return array_values(array_unique(array_filter(array_map($this->urls->normalizeSameOriginHttpUrl(...), $urls), static fn (string $url): bool => $url !== '')));
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
            $urls[] = rest_url('wp/v2/' . (is_string($postType) ? $this->restBase($postType) : 'posts'));
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

    /**
     * @param array<mixed> $arguments
     * @return list<int>
     */
    private function termIds(string $hook, array $arguments): array
    {
        if (!in_array($hook, ['created_term', 'edited_term', 'delete_term', 'clean_term_cache'], true)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', (array) ($arguments[0] ?? [])), static fn (int $id): bool => $id > 0));
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

    /**
     * @param array<mixed> $arguments
     * @return list<int>
     */
    private function commentPostIds(string $hook, array $arguments): array
    {
        if ($hook === 'transition_comment_status') {
            $comment = $arguments[2] ?? null;
            return is_object($comment) && isset($comment->comment_post_ID) ? [(int) $comment->comment_post_ID] : [];
        }
        if (!in_array($hook, ['comment_post', 'edit_comment', 'delete_comment', 'wp_set_comment_status', 'clean_comment_cache'], true)) {
            return [];
        }
        $ids = [];
        foreach ((array) ($arguments[0] ?? []) as $commentId) {
            $comment = function_exists('get_comment') ? get_comment((int) $commentId) : null;
            if (!is_object($comment)) {
                $comment = $arguments[1] ?? null;
            }
            if (!is_object($comment) || !isset($comment->comment_post_ID)) {
                continue;
            }

            $ids[] = (int) $comment->comment_post_ID;
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array<mixed> $arguments
     * @return list<int>
     */
    private function publicProductIds(string $hook, array $arguments): array
    {
        return array_values(array_filter($this->productIds($hook, $arguments), static function (int $id): bool {
            $post = function_exists('get_post') ? get_post($id) : null;
            return is_object($post) && $post->post_status === 'publish';
        }));
    }

    /**
     * @param array<mixed> $arguments
     * @return list<int>
     */
    private function productIds(string $hook, array $arguments): array
    {
        if (!in_array($hook, ['woocommerce_after_product_object_save', 'woocommerce_reduce_order_stock', 'woocommerce_update_product', 'woocommerce_delete_product_transients'], true)) {
            return [];
        }

        $ids = [];

        foreach ($arguments as $argument) {
            if ($hook === 'woocommerce_reduce_order_stock' && is_numeric($argument) && function_exists('wc_get_order')) {
                $argument = wc_get_order((int) $argument);
            }
            if ($hook !== 'woocommerce_reduce_order_stock' && is_int($argument) && $argument > 0) {
                $ids[] = $argument;
            }

            if (!is_object($argument)) {
                continue;
            }

            if ($hook !== 'woocommerce_reduce_order_stock') {
                $ids = [...$ids, ...$this->productIdsFromObject($argument)];
            }

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
