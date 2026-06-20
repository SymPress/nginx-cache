<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Hook;

use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\PurgeRequestMerger;
use SymPress\NginxCache\Purge\PurgeUrlCollector;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeRequest;

final class AutomaticPurgeSubscriber
{
    private ?PurgeRequest $pending = null;
    private bool $flushed = false;

    public function __construct(
        private readonly WordPressCacheSettings $settings,
        private readonly CacheManager $cache,
        private readonly PurgeQueueProcessor $queue,
        private readonly PurgeUrlCollector $urls,
        private readonly PurgeRequestMerger $merger,
    ) {
    }

    public function register(): void
    {
        if (!$this->settings->autoPurgeEnabled() || !function_exists('add_action')) {
            return;
        }

        foreach ($this->purgeActions() as $action) {
            if (did_action($action) > 0) {
                $this->purgeOnce($action);

                continue;
            }

            add_action(
                $action,
                function (mixed ...$arguments) use ($action): void {
                    $this->purgeOnce($action, ...$arguments);
                },
                10,
                99,
            );
        }
    }

    public function purgeOnce(string $hook = 'unknown', mixed ...$arguments): void
    {
        if ($this->flushed || !$this->shouldPurge($arguments)) {
            return;
        }

        $request = $this->request($hook, $arguments);
        $merged = $this->merger->merge(array_values(array_filter([$this->pending, $request])));
        $this->pending = $merged[0] ?? null;
    }

    public function flushPending(): void
    {
        if ($this->flushed || !$this->pending instanceof PurgeRequest) {
            return;
        }

        if ($this->settings->queueEnabled()) {
            $this->queue->enqueue($this->pending);
        } else {
            $this->cache->purgeConfiguredPath($this->pending);
        }

        $this->flushed = true;
        $this->pending = null;
    }

    /** @return list<string> */
    private function purgeActions(): array
    {
        $actions = [
            'publish_post',
            'save_post',
            'edit_post',
            'deleted_post',
            'delete_post',
            'trashed_post',
            'untrashed_post',
            'clean_post_cache',
            'woocommerce_after_product_object_save',
            'woocommerce_update_product',
            'woocommerce_delete_product_transients',
            'comment_post',
            'edit_comment',
            'delete_comment',
            'wp_set_comment_status',
            'created_term',
            'edited_term',
            'delete_term',
            'switch_theme',
            'customize_save_after',
            'wp_update_nav_menu',
            'wp_create_nav_menu',
            'wp_delete_nav_menu',
            'edit_user_profile_update',
            'update_option_permalink_structure',
        ];

        if (function_exists('apply_filters')) {
            $actions = (array) apply_filters('nginx_cache_purge_actions', $actions);
            $actions = (array) apply_filters('sympress_nginx_cache_purge_actions', $actions);
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $action): string => is_string($action) ? trim($action) : '',
                        $actions,
                    ),
                    static fn (string $action): bool => $action !== '',
                ),
            ),
        );
    }

    /** @param array<mixed> $arguments */
    private function shouldPurge(array $arguments): bool
    {
        if ($this->isAutosaveOrRevision($arguments[0] ?? null)) {
            return false;
        }

        $postType = $this->postTypeFromArguments($arguments);

        if ($postType !== null && in_array($postType, $this->settings->excludedPostTypes(), true)) {
            return false;
        }

        $shouldPurge = true;

        if (function_exists('apply_filters')) {
            $shouldPurge = (bool) apply_filters('sympress_nginx_cache_should_purge', $shouldPurge, $arguments);
            $shouldPurge = (bool) apply_filters('nginx_cache_should_purge', $shouldPurge, $arguments);
        }

        return $shouldPurge;
    }

    /** @param array<mixed> $arguments */
    private function request(string $hook, array $arguments): PurgeRequest
    {
        if (!$this->settings->selectivePurgeEnabled() || $this->urls->requiresFullPurge($hook)) {
            return PurgeRequest::full($hook, 'wordpress-hook', false, $this->settings->prewarmEnabled());
        }

        $urls = $this->urls->collect($hook, $arguments);
        $tags = $this->urls->collectTags($hook, $arguments);

        return $urls === []
            ? PurgeRequest::full($hook, 'wordpress-hook', false, $this->settings->prewarmEnabled())
            : PurgeRequest::urls($urls, $hook, 'wordpress-hook', false, $this->settings->prewarmEnabled(), $tags);
    }

    /** @param array<mixed> $arguments */
    private function postTypeFromArguments(array $arguments): ?string
    {
        foreach ($arguments as $argument) {
            if (is_object($argument) && property_exists($argument, 'post_type') && is_string($argument->post_type)) {
                return $argument->post_type;
            }

            if (!is_int($argument) || !function_exists('get_post_type')) {
                continue;
            }

            $postType = get_post_type($argument);

            if (is_string($postType) && $postType !== '') {
                return $postType;
            }
        }

        return null;
    }

    private function isAutosaveOrRevision(mixed $postId): bool
    {
        if (!is_int($postId)) {
            return false;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return true;
        }

        return function_exists('wp_is_post_autosave') && (bool) wp_is_post_autosave($postId);
    }
}
