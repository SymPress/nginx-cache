<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Hook;

use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\PurgeRequestMerger;
use SymPress\NginxCache\Purge\PurgeRules;
use SymPress\NginxCache\Purge\PurgeUrlCollector;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeRequest;

final class AutomaticPurgeSubscriber
{
    /** @var array<int, PurgeRequest> Pending purges by the blog that was current when their hook fired. */
    private array $pending = [];
    /** @var array<int, true> Sites created in this request; they have no cache to purge yet. */
    private array $initializing = [];
    private bool $flushed = false;

    public function __construct(
        private readonly WordPressCacheSettings $settings,
        private readonly CacheManager $cache,
        private readonly PurgeQueueProcessor $queue,
        private readonly PurgeUrlCollector $urls,
        private readonly PurgeRequestMerger $merger,
        private readonly ?PurgeRules $rules = null,
    ) {
    }

    public function register(): void
    {
        if (!$this->settings->autoPurgeEnabled() || !function_exists('add_action')) {
            return;
        }

        foreach ($this->purgeActions() as $action) {
            add_action(
                $action,
                function (mixed ...$arguments) use ($action): void {
                    $this->purgeOnce($action, ...$arguments);
                },
                10,
                99,
            );
        }
        add_action('wp_initialize_site', function (mixed $site): void {
            if (!is_object($site) || !isset($site->id) || !is_numeric($site->id)) {
                return;
            }
            $this->initializing[(int) $site->id] = true;
        }, 0);
    }

    public function purgeOnce(string $hook = 'unknown', mixed ...$arguments): void
    {
        if ($this->flushed || !$this->shouldPurge($hook, $arguments)) {
            return;
        }

        $request = $this->request($hook, $arguments);
        if ($request === null) {
            return;
        }
        // Sites resolve from the current blog when the purge runs, so keep the blog the hook fired in.
        $blog = $this->blogId();
        $merged = $this->merger->merge(array_values(array_filter([$this->pending[$blog] ?? null, $request])));
        if (!isset($merged[0])) {
            return;
        }
        $this->pending[$blog] = $merged[0];
    }

    public function flushPending(): void
    {
        if ($this->flushed || $this->pending === []) {
            return;
        }

        $current = $this->blogId();
        foreach (array_diff_key($this->pending, $this->initializing) as $blog => $request) {
            $switched = $blog !== $current && function_exists('switch_to_blog') && switch_to_blog($blog);
            try {
                $this->dispatch($request);
            } finally {
                if ($switched && function_exists('restore_current_blog')) {
                    restore_current_blog();
                }
            }
        }

        $this->flushed = true;
        $this->pending = [];
    }

    private function dispatch(PurgeRequest $request): void
    {
        if ($this->settings->queueEnabled()) {
            $this->queue->enqueue($request);
            return;
        }
        try {
            $result = $this->cache->purgeConfiguredPath($request);
            if (!$result->successful) {
                $this->queue->enqueue($request);
            }
        } catch (\Throwable) {
            $this->queue->enqueue($request);
        }
    }

    private function blogId(): int
    {
        return function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
    }

    /** @return list<string> */
    private function purgeActions(): array
    {
        $actions = [
            'publish_post',
            'save_post',
            'edit_post',
            'before_delete_post',
            'deleted_post',
            'delete_post',
            'trashed_post',
            'untrashed_post',
            'transition_post_status',
            'delete_attachment',
            'clean_post_cache',
            'clean_term_cache',
            'clean_comment_cache',
            'clean_user_cache',
            'woocommerce_after_product_object_save',
            'woocommerce_reduce_order_stock',
            'woocommerce_update_product',
            'woocommerce_delete_product_transients',
            'comment_post',
            'edit_comment',
            'delete_comment',
            'wp_set_comment_status',
            'transition_comment_status',
            'created_term',
            'edited_term',
            'delete_term',
            'switch_theme',
            'customize_save_after',
            'wp_update_nav_menu',
            'wp_create_nav_menu',
            'wp_delete_nav_menu',
            'edit_user_profile_update',
            'profile_update',
            'deleted_user',
            'update_option_permalink_structure',
            'upgrader_process_complete',
            ...$this->urls->fullPurgeHooks(),
        ];

        if ($this->rules?->customized()) {
            // Generic cache-clean events have no edit/delete/comment context.
            $actions = array_values(array_diff($actions, ['clean_post_cache', 'clean_comment_cache']));
        }
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
    private function shouldPurge(string $hook, array $arguments): bool
    {
        if (!$this->urls->publicMutation($hook, $arguments) || $this->isAutosaveOrRevision($this->urls->postId($hook, $arguments))) {
            return false;
        }

        $id = $this->urls->postId($hook, $arguments);
        $postType = $id !== null && function_exists('get_post_type') ? get_post_type($id) : null;

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
    private function request(string $hook, array $arguments): ?PurgeRequest
    {
        if ($this->urls->requiresFullPurge($hook, $arguments)) {
            return PurgeRequest::full($hook, 'wordpress-hook', false, $this->settings->prewarmEnabled());
        }

        $urls = $this->urls->collect($hook, $arguments);
        $tags = $this->urls->collectTags($hook, $arguments);
        $prewarm = $this->shouldPrewarm($hook, $arguments);

        if ($urls === []) {
            return null;
        }
        if ($this->isImportRequest() || !$this->settings->selectivePurgeEnabled()) {
            return PurgeRequest::full($hook, 'wordpress-hook', false, $this->settings->prewarmEnabled(), affectedUrls: $this->urls->affectedUrls($hook, $arguments));
        }
        return PurgeRequest::urls($urls, $hook, 'wordpress-hook', false, $prewarm, $tags, affectedUrls: $this->urls->affectedUrls($hook, $arguments));
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

    /** @param array<mixed> $arguments */
    private function shouldPrewarm(string $hook, array $arguments): bool
    {
        if (!$this->settings->prewarmEnabled()) {
            return false;
        }

        if ($hook !== 'transition_post_status') {
            return false;
        }

        $newStatus = is_string($arguments[0] ?? null) ? $arguments[0] : '';
        $oldStatus = is_string($arguments[1] ?? null) ? $arguments[1] : '';

        return $newStatus === 'publish' && $oldStatus !== 'publish';
    }

    private function isImportRequest(): bool
    {
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return true;
        }

        return function_exists('did_action') && did_action('import_start') > 0;
    }
}
