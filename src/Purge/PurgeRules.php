<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Settings\CompatibilitySettings;

final readonly class PurgeRules
{
    public function __construct(private CompatibilitySettings $settings)
    {
    }

    public function customized(): bool
    {
        foreach ($this->settings->defaults() as $name => $default) {
            if (str_starts_with($name, 'purge_') && is_int($default) && $this->settings->integer($name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array<mixed> $arguments */
    public function limited(string $hook, array $arguments): bool
    {
        $event = $this->event($hook, $arguments);
        foreach (['home', 'page', 'archive'] as $scope) {
            if ($this->settings->integer('purge_' . $scope . '_' . $event) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $arguments
     * @param list<string> $urls
     * @param list<int> $postIds
     * @return list<string>
     */
    public function filter(string $hook, array $arguments, array $urls, array $postIds): array
    {
        if ($postIds === []) {
            return $urls;
        }
        if ($hook === 'comment_post' && !in_array($arguments[1] ?? 1, [1, '1', 'approved'], true)) {
            return [];
        }
        $event = $this->event($hook, $arguments);
        $home = function_exists('home_url') ? home_url('/') : '';
        return array_values(array_filter($urls, function (string $url) use ($postIds, $event, $home): bool {
            $scope = 'archive';
            if ($url === $home || str_starts_with($url, rtrim($home, '/') . '/feed/')) {
                $scope = 'home';
            } else {
                foreach ($postIds as $postId) {
                    if ($this->singularUrl($url, $postId)) {
                        $scope = 'page';
                        break;
                    }
                }
            }
            return $this->settings->integer('purge_' . $scope . '_' . $event) !== 0;
        }));
    }

    /** @param array<mixed> $arguments */
    private function event(string $hook, array $arguments): string
    {
        if (
            in_array($hook, ['delete_comment', 'trashed_comment', 'spammed_comment'], true)
            || ($hook === 'wp_set_comment_status' && ($arguments[1] ?? '') !== 'approve')
            || ($hook === 'transition_comment_status' && ($arguments[0] ?? '') !== 'approved')
        ) {
            return 'comment_delete';
        }
        if (str_contains($hook, 'comment')) {
            return 'comment_new';
        }
        if (
            in_array($hook, ['before_delete_post', 'deleted_post', 'delete_post', 'trashed_post', 'delete_attachment'], true)
            || ($hook === 'transition_post_status' && ($arguments[0] ?? '') === 'trash')
        ) {
            return 'delete';
        }
        return 'edit';
    }

    private function singularUrl(string $url, int $postId): bool
    {
        $base = function_exists('get_permalink') ? get_permalink($postId) : false;
        if (
            is_string($base) && ($url === $base || str_starts_with($url, rtrim($base, '/') . '/amp/')
            || str_starts_with($url, rtrim($base, '/') . '/feed/') || str_starts_with($url, $base . '?amp='))
        ) {
            return true;
        }
        if (!function_exists('get_post_type') || !function_exists('get_post_type_object') || !function_exists('rest_url')) {
            return false;
        }
        $type = get_post_type($postId);
        $object = is_string($type) ? get_post_type_object($type) : null;
        $restBase = is_object($object) && is_string($object->rest_base) && $object->rest_base !== '' ? $object->rest_base : $type;
        return is_string($restBase) && $url === rest_url('wp/v2/' . $restBase . '/' . $postId);
    }
}
