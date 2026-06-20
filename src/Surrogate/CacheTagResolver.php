<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Surrogate;

final readonly class CacheTagResolver
{
    /** @return list<string> */
    public function currentTags(): array
    {
        $tags = [$this->siteTag()];

        if ($this->isRestRequest()) {
            $tags[] = 'rest';
        }

        if (function_exists('is_front_page') && is_front_page()) {
            $tags[] = 'home';
        }

        if (function_exists('is_home') && is_home()) {
            $tags[] = 'posts';
            $tags[] = 'archive';
        }

        if (function_exists('is_feed') && is_feed()) {
            $tags[] = 'feed';
        }

        if (function_exists('is_search') && is_search()) {
            $tags[] = 'search';
        }

        if (function_exists('is_404') && is_404()) {
            $tags[] = 'not-found';
            $tags[] = '404';
        }

        if (function_exists('is_singular') && is_singular() && function_exists('get_queried_object_id')) {
            $postId = (int) get_queried_object_id();

            if ($postId > 0) {
                $tags = [...$tags, ...$this->postTags($postId)];
            }
        }

        if (function_exists('is_post_type_archive') && is_post_type_archive() && function_exists('get_query_var')) {
            $postTypes = get_query_var('post_type');

            foreach ((array) $postTypes as $postType) {
                if (!is_string($postType) || $postType === '') {
                    continue;
                }

                $tags[] = sprintf('post_type:%s', $postType);
                $tags[] = 'archive';
            }
        }

        if (function_exists('is_date') && is_date()) {
            $tags[] = 'date';
            $tags[] = 'archive';
        }

        if (function_exists('is_paged') && is_paged()) {
            $tags[] = 'paged';
        }

        if (function_exists('is_author') && is_author() && function_exists('get_queried_object_id')) {
            $authorId = (int) get_queried_object_id();

            if ($authorId > 0) {
                $tags[] = sprintf('author:%d', $authorId);
                $tags[] = 'archive';
            }
        }

        if (function_exists('is_category') && function_exists('is_tag') && function_exists('is_tax')) {
            if ((is_category() || is_tag() || is_tax()) && function_exists('get_queried_object')) {
                $term = get_queried_object();

                if (is_object($term) && property_exists($term, 'term_id') && is_numeric($term->term_id)) {
                    $tags = [...$tags, ...$this->termTags((int) $term->term_id, $term)];
                }
            }
        }

        $tags = [...$tags, ...$this->mainQueryTags()];

        if (function_exists('apply_filters')) {
            $tags = (array) apply_filters('sympress_nginx_cache_current_tags', $tags);
        }

        return $this->normalize($tags);
    }

    /** @return list<string> */
    public function postTags(int $postId): array
    {
        $tags = [
            $this->siteTag(),
            sprintf('post:%d', $postId),
            sprintf('rest:post:%d', $postId),
            'posts',
        ];
        $post = function_exists('get_post') ? get_post($postId) : null;

        if (is_object($post)) {
            if (property_exists($post, 'post_type') && is_string($post->post_type) && $post->post_type !== '') {
                $tags[] = sprintf('post_type:%s', $post->post_type);
                $tags[] = sprintf('rest:%s:collection', $post->post_type);
            }

            if (property_exists($post, 'post_author') && is_numeric($post->post_author)) {
                $tags[] = sprintf('author:%d', (int) $post->post_author);
                $tags[] = sprintf('rest:user:%d', (int) $post->post_author);
            }
        }

        if (function_exists('get_object_taxonomies') && function_exists('get_the_terms')) {
            $postType = is_object($post) && property_exists($post, 'post_type') && is_string($post->post_type)
                ? $post->post_type
                : '';
            $taxonomies = $postType !== '' ? get_object_taxonomies($postType) : [];

            foreach ($taxonomies as $taxonomy) {
                if (!is_string($taxonomy)) {
                    continue;
                }

                $terms = get_the_terms($postId, $taxonomy);

                if (!is_array($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    if (!is_object($term) || !property_exists($term, 'term_id') || !is_numeric($term->term_id)) {
                        continue;
                    }

                    $tags = [...$tags, ...$this->termTags((int) $term->term_id, $term)];
                }
            }
        }

        if (function_exists('apply_filters')) {
            $tags = (array) apply_filters('sympress_nginx_cache_post_tags', $tags, $postId);
        }

        return $this->normalize($tags);
    }

    /** @return list<string> */
    public function termTags(int $termId, ?object $term = null): array
    {
        $term ??= function_exists('get_term') ? get_term($termId) : null;
        $tags = [
            $this->siteTag(),
            sprintf('term:%d', $termId),
            sprintf('rest:term:%d', $termId),
            'archive',
        ];

        if (is_object($term)) {
            if (property_exists($term, 'taxonomy') && is_string($term->taxonomy) && $term->taxonomy !== '') {
                $tags[] = sprintf('term:%s:%d', $term->taxonomy, $termId);
                $tags[] = sprintf('taxonomy:%s', $term->taxonomy);
                $tags[] = sprintf('rest:%s:collection', $term->taxonomy);
            }

            if (property_exists($term, 'slug') && is_string($term->slug) && $term->slug !== '') {
                $tags[] = sprintf('term:%s', $term->slug);
            }
        }

        if (function_exists('apply_filters')) {
            $tags = (array) apply_filters('sympress_nginx_cache_term_tags', $tags, $termId, $term);
        }

        return $this->normalize($tags);
    }

    /** @return list<string> */
    public function commentTags(int $commentId, ?object $comment = null): array
    {
        $comment ??= function_exists('get_comment') ? get_comment($commentId) : null;
        $tags = [$this->siteTag(), sprintf('comment:%d', $commentId), sprintf('rest:comment:%d', $commentId), 'comments'];

        if (is_object($comment) && property_exists($comment, 'comment_post_ID') && is_numeric($comment->comment_post_ID)) {
            $tags[] = sprintf('comment_post:%d', (int) $comment->comment_post_ID);
            $tags = [...$tags, ...$this->postTags((int) $comment->comment_post_ID)];
        }

        if (function_exists('apply_filters')) {
            $tags = (array) apply_filters('sympress_nginx_cache_comment_tags', $tags, $commentId, $comment);
        }

        return $this->normalize($tags);
    }

    /** @return list<string> */
    public function userTags(int $userId): array
    {
        $tags = [$this->siteTag(), sprintf('author:%d', $userId), sprintf('user:%d', $userId), sprintf('rest:user:%d', $userId)];

        if (function_exists('apply_filters')) {
            $tags = (array) apply_filters('sympress_nginx_cache_user_tags', $tags, $userId);
        }

        return $this->normalize($tags);
    }

    /**
     * @param array<mixed> $tags
     * @return list<string>
     */
    public function normalize(array $tags): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function (mixed $tag): string {
                            if (!is_scalar($tag)) {
                                return '';
                            }

                            $tag = preg_replace('/[^A-Za-z0-9:_-]+/', '-', (string) $tag);
                            $tag = trim((string) $tag, '-_:');

                            return $tag === '' ? '' : substr($tag, 0, 80);
                        },
                        $tags,
                    ),
                    static fn (string $tag): bool => $tag !== '',
                ),
            ),
        );
    }

    private function siteTag(): string
    {
        return sprintf('site:%d', function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1);
    }

    private function isRestRequest(): bool
    {
        if (function_exists('wp_is_serving_rest_request')) {
            return (bool) wp_is_serving_rest_request();
        }

        return defined('REST_REQUEST') && REST_REQUEST;
    }

    /** @return list<string> */
    private function mainQueryTags(): array
    {
        $query = $GLOBALS['wp_query'] ?? null;

        if (!is_object($query) || !property_exists($query, 'posts') || !is_array($query->posts)) {
            return [];
        }

        $tags = [];

        foreach ($query->posts as $post) {
            if (!is_object($post) || !property_exists($post, 'ID') || !is_numeric($post->ID)) {
                continue;
            }

            $tags = [...$tags, ...$this->postTags((int) $post->ID)];
        }

        return $tags;
    }
}
