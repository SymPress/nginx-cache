<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Purge\PurgeHistoryRepository;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

/** Loaded only after WordPress' admin list-table base class is available. */
final class NetworkSitesTable extends \WP_List_Table
{
    public function __construct(private readonly PurgeQueueProcessor $queue, private readonly PurgeSideEffectProcessor $effects, private readonly PurgeHistoryRepository $history)
    {
        parent::__construct(['singular' => 'site', 'plural' => 'sites', 'ajax' => false]);
    }

    public function get_columns(): array
    {
        return ['site' => __('Site', WordPressCacheSettings::TEXT_DOMAIN), 'address' => __('Domain / path', WordPressCacheSettings::TEXT_DOMAIN), 'pending' => __('Pending', WordPressCacheSettings::TEXT_DOMAIN), 'exhausted' => __('Exhausted', WordPressCacheSettings::TEXT_DOMAIN), 'last' => __('Last purge', WordPressCacheSettings::TEXT_DOMAIN)];
    }

    public function prepare_items(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized network-site search.
        $search = isset($_GET['s']) && is_string($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page = max(1, $this->get_pagenum());
        $large = wp_is_large_network();
        $args = ['network_id' => get_current_network_id(), 'number' => $large ? 21 : 20, 'offset' => ($page - 1) * 20, 'orderby' => 'id', 'order' => 'ASC'];
        if ($search !== '') {
            $args['search'] = '*' . $search . '*';
        }
        $sites = get_sites($args);
        $total = $large ? ($page - 1) * 20 + count($sites) : (int) get_sites([...$args, 'count' => true, 'number' => 0, 'offset' => 0]);
        $this->items = [];
        foreach (array_slice($sites, 0, 20) as $site) {
            switch_to_blog((int) $site->blog_id);
            try {
                $last = $this->history->last();
                $this->items[] = ['id' => (int) $site->blog_id, 'site' => get_bloginfo('name'), 'address' => $site->domain . $site->path, 'pending' => $this->queue->count() + $this->effects->count(), 'exhausted' => count(array_filter([...$this->queue->inspect(), ...$this->effects->inspect()], static fn (array $task): bool => ($task['exhausted'] ?? false) === true)), 'last' => is_array($last) && (int) ($last['created_at'] ?? 0) > 0 ? wp_date('Y-m-d H:i', (int) $last['created_at']) : '—'];
            } finally {
                restore_current_blog();
            }
        }
        $this->_column_headers = [$this->get_columns(), [], [], 'site'];
        $this->set_pagination_args(['total_items' => $total, 'per_page' => 20]);
    }

    public function column_default($item, $column_name): string
    {
        return esc_html((string) ($item[$column_name] ?? ''));
    }

    public function column_site($item): string
    {
        $actions = [];
        foreach (['purge_site' => __('Purge site', WordPressCacheSettings::TEXT_DOMAIN), 'retry' => __('Retry queue', WordPressCacheSettings::TEXT_DOMAIN)] as $operation => $label) {
            $url = wp_nonce_url(add_query_arg(['action' => NetworkAdminPage::ACTION, 'operation' => $operation, 'site_id' => $item['id']], network_admin_url('edit.php')), NetworkAdminPage::ACTION);
            $actions[$operation] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        return '<strong>' . esc_html((string) $item['site']) . '</strong>' . $this->row_actions($actions);
    }
}
