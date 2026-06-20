<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Hook;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\CacheTagHeaderFormatter;
use SymPress\NginxCache\Surrogate\CacheTagResolver;

final class RestGraphQlCacheTagSubscriber
{
    /** @var list<string> */
    private array $restTags = [];

    /** @var array<string, string> */
    private array $restCollections = [];

    /** @var list<string> */
    private array $graphQlTags = [];

    public function __construct(
        private readonly CacheTagResolver $resolver,
        private readonly CacheTagHeaderFormatter $headers,
        private readonly WordPressCacheSettings $settings,
    ) {
    }

    public function registerRestFilters(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }

        if (function_exists('get_post_types')) {
            foreach (get_post_types(['show_in_rest' => true], 'objects') as $postType) {
                if (!is_object($postType) || !property_exists($postType, 'name') || !is_string($postType->name)) {
                    continue;
                }

                add_filter(sprintf('rest_prepare_%s', $postType->name), $this->collectRestPostTags(...), 10, 3);
                $base = property_exists($postType, 'rest_base') && is_string($postType->rest_base) && $postType->rest_base !== ''
                    ? $postType->rest_base
                    : $postType->name;
                $this->restCollections[sprintf('/wp/v2/%s', trim($base, '/'))] = sprintf('rest:%s:collection', $postType->name);
            }
        }

        if (function_exists('get_taxonomies')) {
            foreach (get_taxonomies(['show_in_rest' => true], 'objects') as $taxonomy) {
                if (!is_object($taxonomy) || !property_exists($taxonomy, 'name') || !is_string($taxonomy->name)) {
                    continue;
                }

                add_filter(sprintf('rest_prepare_%s', $taxonomy->name), $this->collectRestTermTags(...), 10, 3);
                $base = property_exists($taxonomy, 'rest_base') && is_string($taxonomy->rest_base) && $taxonomy->rest_base !== ''
                    ? $taxonomy->rest_base
                    : $taxonomy->name;
                $this->restCollections[sprintf('/wp/v2/%s', trim($base, '/'))] = sprintf('rest:%s:collection', $taxonomy->name);
            }
        }

        add_filter('rest_prepare_comment', $this->collectRestCommentTags(...), 10, 3);
        add_filter('rest_prepare_user', $this->collectRestUserTags(...), 10, 3);
        add_filter('rest_pre_get_setting', $this->collectRestSettingTag(...), 10, 2);
        $this->restCollections['/wp/v2/comments'] = 'rest:comment:collection';
        $this->restCollections['/wp/v2/users'] = 'rest:user:collection';
    }

    public function resetRestTags(mixed $result, mixed $server, mixed $request): mixed
    {
        $this->restTags = ['rest'];
        $route = is_object($request) && method_exists($request, 'get_route') ? (string) $request->get_route() : '';

        if (isset($this->restCollections[$route])) {
            $this->restTags[] = $this->restCollections[$route];
        }

        return $result;
    }

    public function addRestHeaders(mixed $result, mixed $server, mixed $request = null): mixed
    {
        if (!is_object($result) || !method_exists($result, 'header')) {
            return $result;
        }

        if (!$this->settings->debugHeadersEnabled() && !$this->settings->cloudflareEnabled()) {
            return $result;
        }

        $tags = $this->restTags;

        if ($tags === []) {
            return $result;
        }

        if ($this->settings->debugHeadersEnabled()) {
            $headerValue = $this->headers->value($tags);

            if ($headerValue !== '') {
                $result->header('Surrogate-Key', $headerValue);
                $result->header('X-SymPress-Cache-Tags', $headerValue);
            }
        }

        if ($this->settings->cloudflareEnabled()) {
            $cloudflareValue = $this->headers->cloudflareValue($tags);

            if ($cloudflareValue !== '') {
                $result->header('Cache-Tag', $cloudflareValue);
            }
        }

        return $result;
    }

    public function collectRestPostTags(mixed $response, mixed $post, mixed $request): mixed
    {
        if (is_object($post) && property_exists($post, 'ID') && is_numeric($post->ID)) {
            $this->restTags = [...$this->restTags, ...$this->resolver->postTags((int) $post->ID)];
        }

        return $response;
    }

    public function collectRestTermTags(mixed $response, mixed $term, mixed $request): mixed
    {
        if (is_object($term) && property_exists($term, 'term_id') && is_numeric($term->term_id)) {
            $this->restTags = [...$this->restTags, ...$this->resolver->termTags((int) $term->term_id, $term)];
        }

        return $response;
    }

    public function collectRestCommentTags(mixed $response, mixed $comment, mixed $request): mixed
    {
        if (is_object($comment) && property_exists($comment, 'comment_ID') && is_numeric($comment->comment_ID)) {
            $this->restTags = [...$this->restTags, ...$this->resolver->commentTags((int) $comment->comment_ID, $comment)];
        }

        return $response;
    }

    public function collectRestUserTags(mixed $response, mixed $user, mixed $request): mixed
    {
        if (is_object($user) && property_exists($user, 'ID') && is_numeric($user->ID)) {
            $this->restTags = [...$this->restTags, ...$this->resolver->userTags((int) $user->ID)];
        }

        return $response;
    }

    public function collectRestSettingTag(mixed $result, string $name): mixed
    {
        $this->restTags[] = sprintf('rest:setting:%s', $name);

        return $result;
    }

    public function collectGraphQlModel(mixed $model): mixed
    {
        if (!is_object($model)) {
            return $model;
        }

        $class = (new \ReflectionClass($model))->getShortName();
        $prefix = strtolower($class);
        $id = null;

        if (property_exists($model, 'databaseId') && is_numeric($model->databaseId)) {
            $id = (int) $model->databaseId;
        } elseif (property_exists($model, 'ID') && is_numeric($model->ID)) {
            $id = (int) $model->ID;
        } elseif (property_exists($model, 'id') && is_numeric($model->id)) {
            $id = (int) $model->id;
        }

        if ($id !== null) {
            $this->graphQlTags[] = sprintf('graphql:%s:%d', $prefix, $id);
        }

        return $model;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function addGraphQlHeaders(array $headers): array
    {
        if (!$this->settings->debugHeadersEnabled() && !$this->settings->cloudflareEnabled()) {
            return $headers;
        }

        $tags = ['graphql', 'graphql:collection', ...$this->graphQlTags];

        if ($this->settings->debugHeadersEnabled()) {
            $value = $this->headers->value($tags);

            if ($value !== '') {
                $headers['Surrogate-Key'] = $value;
                $headers['X-SymPress-Cache-Tags'] = $value;
            }
        }

        if ($this->settings->cloudflareEnabled()) {
            $cloudflareValue = $this->headers->cloudflareValue($tags);

            if ($cloudflareValue !== '') {
                $headers['Cache-Tag'] = $cloudflareValue;
            }
        }

        return $headers;
    }
}
