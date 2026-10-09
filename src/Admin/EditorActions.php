<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\PurgeUrlCollector;
use SymPress\NginxCache\Security\Capabilities;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeRequest;

final readonly class EditorActions
{
    private const string ACTION = 'sympress_nginx_cache_purge_object';

    public function __construct(private CacheManager $cache, private PurgeQueueProcessor $queue, private PurgeUrlCollector $collector, private UrlPolicy $urls, private WordPressCacheSettings $settings)
    {
    }

    public function adminBar(\WP_Admin_Bar $bar): void
    {
        if (is_admin() || !current_user_can(Capabilities::PURGE_URL)) {
            return;
        }
        $url = $this->currentUrl();
        if ($url === '') {
            return;
        }
        if ($bar->get_node(WordPressCacheSettings::TEXT_DOMAIN) === null) {
            $bar->add_node(['id' => WordPressCacheSettings::TEXT_DOMAIN, 'title' => __('Nginx Cache', WordPressCacheSettings::TEXT_DOMAIN)]);
        }
        $bar->add_node(['id' => 'sympress-nginx-cache-current', 'parent' => WordPressCacheSettings::TEXT_DOMAIN, 'title' => __('Purge this page', WordPressCacheSettings::TEXT_DOMAIN), 'href' => $this->actionUrl('url', $url, $url)]);
    }

    public function currentUrl(): string
    {
        if (is_404() || is_search() || is_preview() || is_feed()) {
            return '';
        }
        if (is_singular()) {
            $post = get_queried_object();
            if (!$post instanceof \WP_Post || !is_post_publicly_viewable($post)) {
                return '';
            }
            $url = wp_get_canonical_url($post);
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (!$term instanceof \WP_Term || !is_taxonomy_viewable($term->taxonomy)) {
                return '';
            }
            $url = get_term_link($term);
        } elseif (is_front_page() || is_home() || is_archive()) {
            $url = get_pagenum_link(max(1, (int) get_query_var('paged')), false);
        } else {
            return '';
        }
        return $this->urls->normalizeSameOriginHttpUrl(is_string($url) ? $url : '');
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function postActions(array $actions, \WP_Post $post): array
    {
        if (current_user_can(Capabilities::PURGE_URL) && is_post_publicly_viewable($post)) {
            $actions[self::ACTION] = '<a href="' . esc_url($this->actionUrl('post', (string) $post->ID)) . '">' . esc_html__('Purge cache', WordPressCacheSettings::TEXT_DOMAIN) . '</a>';
        }
        return $actions;
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function termActions(array $actions, \WP_Term $term): array
    {
        if (current_user_can(Capabilities::PURGE_URL) && is_taxonomy_viewable($term->taxonomy)) {
            $actions[self::ACTION] = '<a href="' . esc_url($this->actionUrl('term', (string) $term->term_id)) . '">' . esc_html__('Purge cache', WordPressCacheSettings::TEXT_DOMAIN) . '</a>';
        }
        return $actions;
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function bulkActions(array $actions): array
    {
        if (current_user_can(Capabilities::PURGE_URL)) {
            $actions[self::ACTION] = __('Purge cache', WordPressCacheSettings::TEXT_DOMAIN);
        }
        return $actions;
    }

    /** @param list<int> $ids */
    public function bulk(string $redirect, string $action, array $ids): string
    {
        if ($action !== self::ACTION) {
            return $redirect;
        }
        check_admin_referer('bulk-posts');
        if (!current_user_can(Capabilities::PURGE_URL)) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        $urls = [];
        foreach ($ids as $id) {
            $urls = [...$urls, ...$this->objectUrls('post', (string) $id)];
        }
        $this->purgeUrls($urls, count($ids) > 100);
        return $redirect;
    }

    public function handle(): void
    {
        check_admin_referer(self::ACTION);
        if (!current_user_can(Capabilities::PURGE_URL)) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        $kind = sanitize_key((string) wp_unslash($_GET['kind'] ?? ''));
        $value = sanitize_text_field((string) wp_unslash($_GET['value'] ?? ''));
        $this->purgeUrls($this->objectUrls($kind, $value));
        $redirect = wp_validate_redirect((string) wp_unslash($_GET['redirect_to'] ?? ''), admin_url());
        wp_safe_redirect($redirect);
        exit;
    }

    /** @return list<string> */
    public function objectUrls(string $kind, string $value): array
    {
        if ($kind === 'url') {
            $url = $this->urls->normalizeSameOriginHttpUrl($value);
            return $url === '' ? [] : [$url];
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            return [];
        }
        if ($kind === 'post') {
            $post = get_post((int) $value);
            return $post instanceof \WP_Post && is_post_publicly_viewable($post) ? $this->collector->collect('save_post', [$post->ID, $post]) : [];
        }
        if ($kind === 'term') {
            $term = get_term((int) $value);
            return $term instanceof \WP_Term && is_taxonomy_viewable($term->taxonomy) ? $this->collector->collect('edited_term', [$term->term_id]) : [];
        }
        return [];
    }

    /** @param list<string> $urls */
    private function purgeUrls(array $urls, bool $queued = false): void
    {
        $urls = array_values(array_unique($urls));
        $count = count($urls);
        $successful = false;
        if ($urls !== []) {
            $request = PurgeRequest::urls($urls, 'editor', 'admin-object', prewarm: $this->settings->prewarmEnabled());
            if ($queued) {
                $this->queue->enqueue($request);
                $successful = true;
            } else {
                $successful = $this->cache->purgeConfiguredPath($request)->successful;
            }
        }
        set_transient(self::ACTION . '_' . get_current_user_id(), ['count' => $count, 'queued' => $queued, 'successful' => $successful], MINUTE_IN_SECONDS);
    }

    public function notice(): void
    {
        $key = self::ACTION . '_' . get_current_user_id();
        $value = get_transient($key);
        if (!is_array($value)) {
            return;
        }
        delete_transient($key);
        $message = empty($value['successful']) ? __('No public URLs could be purged.', WordPressCacheSettings::TEXT_DOMAIN) : sprintf($value['queued'] ? __('Queued %d URLs for purge.', WordPressCacheSettings::TEXT_DOMAIN) : __('Purged %d URLs.', WordPressCacheSettings::TEXT_DOMAIN), (int) $value['count']);
        echo '<div class="notice notice-' . (empty($value['successful']) ? 'warning' : 'success') . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function actionUrl(string $kind, string $value, string $redirect = ''): string
    {
        return wp_nonce_url(add_query_arg(['action' => self::ACTION, 'kind' => $kind, 'value' => $value, 'redirect_to' => $redirect !== '' ? $redirect : (wp_get_referer() ?: admin_url())], admin_url('admin-post.php')), self::ACTION);
    }
}
