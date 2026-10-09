<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Config\MultisiteMapGenerator;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\PurgeHistoryRepository;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use SymPress\NginxCache\Security\Capabilities;
use SymPress\NginxCache\Settings\ConfigurationCatalog;
use SymPress\NginxCache\Settings\NetworkConfiguration;
use SymPress\NginxCache\Settings\NetworkSettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeScope;

final readonly class NetworkAdminPage
{
    public const string ACTION = 'sympress_nginx_cache_network';

    public function __construct(private NetworkSettings $network, private NetworkConfiguration $configuration, private CacheManager $cache, private PurgeQueueProcessor $queue, private PurgeSideEffectProcessor $effects, private PurgeHistoryRepository $history, private MultisiteMapGenerator $maps)
    {
    }

    public function menu(): void
    {
        $hook = add_submenu_page('settings.php', __('Nginx Cache', WordPressCacheSettings::TEXT_DOMAIN), __('Nginx Cache', WordPressCacheSettings::TEXT_DOMAIN), Capabilities::PURGE_NETWORK, 'sympress-nginx-cache', $this->render(...));
        if (!is_string($hook)) {
            return;
        }

        add_action('load-' . $hook, (new CacheMetricsPanel())->enqueueStyles(...));
    }

    public function handle(): void
    {
        check_admin_referer(self::ACTION);
        if (!current_user_can(Capabilities::PURGE_NETWORK)) {
            wp_die(esc_html__('Network cache permission is required.', WordPressCacheSettings::TEXT_DOMAIN));
        }
        $operation = isset($_REQUEST['operation']) && is_string($_REQUEST['operation']) ? sanitize_key(wp_unslash($_REQUEST['operation'])) : '';
        $tab = 'sites';
        if ($operation === 'settings') {
            $this->configuration->register();
            $values = isset($_POST['values']) && is_array($_POST['values']) ? wp_unslash($_POST['values']) : [];
            $policies = isset($_POST['policies']) && is_array($_POST['policies']) ? wp_unslash($_POST['policies']) : [];
            $sanitized = [];
            foreach (ConfigurationCatalog::defaults() as $option => $default) {
                if (!array_key_exists($option, $values) || (ConfigurationCatalog::secret($option) && $values[$option] === '')) {
                    continue;
                }
                $sanitized[$option] = $this->configuration->sanitize($option, $values[$option]);
            }
            foreach ($sanitized as $option => $value) {
                $this->network->save($option, $value, ($policies[$option] ?? '') === 'network' ? 'network' : 'default');
            }
            $tab = 'settings';
        } elseif ($operation === 'network') {
            $domain = get_network()->domain ?? '';
            $confirmation = isset($_POST['confirmation']) && is_string($_POST['confirmation']) ? trim(wp_unslash($_POST['confirmation'])) : '';
            if (!hash_equals($domain, $confirmation)) {
                wp_die(esc_html__('Enter the exact network domain to confirm this purge.', WordPressCacheSettings::TEXT_DOMAIN));
            }
            $result = $this->cache->purgeConfiguredPath(PurgeRequest::full('network-admin', 'admin-network', scope: PurgeScope::Network));
            if (!$result->successful) {
                wp_die(esc_html($result->message));
            }
            $tab = 'network';
        } elseif (in_array($operation, ['purge_site', 'retry'], true)) {
            $id = isset($_REQUEST['site_id']) ? absint($_REQUEST['site_id']) : 0;
            $site = get_site($id);
            if ($site === null || (int) $site->site_id !== get_current_network_id()) {
                wp_die(esc_html__('Select a site in this network.', WordPressCacheSettings::TEXT_DOMAIN));
            }
            switch_to_blog($id);
            try {
                if ($operation === 'retry') {
                    $this->queue->retry();
                    $this->effects->retry();
                } else {
                    $this->queue->enqueue(PurgeRequest::full('network-admin-site', 'admin-network'));
                }
            } finally {
                restore_current_blog();
            }
        } else {
            wp_die(esc_html__('Unknown network cache action.', WordPressCacheSettings::TEXT_DOMAIN));
        }
        wp_safe_redirect(add_query_arg(['page' => 'sympress-nginx-cache', 'tab' => $tab, 'updated' => 'true'], network_admin_url('settings.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(Capabilities::PURGE_NETWORK)) {
            wp_die(esc_html__('Network cache permission is required.', WordPressCacheSettings::TEXT_DOMAIN));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection, allowlisted before use.
        $tab = isset($_GET['tab']) && in_array($_GET['tab'], ['settings', 'sites', 'network'], true) ? $_GET['tab'] : 'settings';
        ?>
        <div class="wrap sympress-network">
            <h1><?php echo esc_html__('Nginx Cache · Network', WordPressCacheSettings::TEXT_DOMAIN); ?></h1>
            <p><?php echo esc_html__('Manage shared configuration and site queues from one place.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
            <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('Network cache views', WordPressCacheSettings::TEXT_DOMAIN); ?>">
                <?php foreach (['settings' => __('Settings', WordPressCacheSettings::TEXT_DOMAIN), 'sites' => __('Sites', WordPressCacheSettings::TEXT_DOMAIN), 'network' => __('Network', WordPressCacheSettings::TEXT_DOMAIN)] as $key => $label) : ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'sympress-nginx-cache', 'tab' => $key], network_admin_url('settings.php'))); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($tab === 'settings') {
                $this->settings();
            } elseif ($tab === 'sites') {
                $this->sites();
            } else {
                $this->networkActions();
            } ?>
        </div>
        <?php
    }

    private function form(string $operation): void
    {
        echo '<form method="post" action="' . esc_url(add_query_arg('action', self::ACTION, network_admin_url('edit.php'))) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="operation" value="' . esc_attr($operation) . '" />';
    }

    private function settings(): void
    {
        $this->form('settings');
        echo '<p>' . esc_html__('Network-managed values are locked for sites. Defaults allow site overrides. Existing site options are retained.', WordPressCacheSettings::TEXT_DOMAIN) . '</p><table class="form-table"><tbody>';
        foreach (ConfigurationCatalog::defaults() as $option => $default) {
            $value = $this->network->value($option, $default);
            $id = 'network-' . $option;
            $label = ucwords(str_replace('_', ' ', str_replace(['sympress_nginx_cache_', 'nginx_'], '', $option)));
            echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
            if (is_array($default)) {
                $value = is_array($value) ? $value : [];
                echo '<input type="hidden" name="values[' . esc_attr($option) . '][]" value="" />';
                foreach (wp_roles()->get_names() as $role => $name) {
                    if ($role === 'administrator') {
                        continue;
                    }
                    echo '<label><input type="checkbox" name="values[' . esc_attr($option) . '][]" value="' . esc_attr($role) . '" ' . checked(in_array($role, $value, true), true, false) . ' /> ' . esc_html(translate_user_role($name)) . '</label><br />';
                }
            } else {
                echo '<input class="regular-text" id="' . esc_attr($id) . '" type="' . (ConfigurationCatalog::secret($option) ? 'password' : (is_int($default) ? 'number' : 'text')) . '" name="values[' . esc_attr($option) . ']" value="' . esc_attr(ConfigurationCatalog::secret($option) ? '' : (string) $value) . '" autocomplete="off" />';
                if (ConfigurationCatalog::secret($option)) {
                    echo '<p class="description">' . esc_html__('Leave empty to retain the stored credential.', WordPressCacheSettings::TEXT_DOMAIN) . '</p>';
                }
            }
            if ($this->network->mandatory($option)) {
                echo '<p class="description">' . esc_html__('Managed by the network.', WordPressCacheSettings::TEXT_DOMAIN) . '</p>';
            } else {
                echo '<select aria-label="' . esc_attr__('Setting policy', WordPressCacheSettings::TEXT_DOMAIN) . '" name="policies[' . esc_attr($option) . ']"><option value="default" ' . selected($this->network->policy($option), 'default', false) . '>' . esc_html__('Site may override', WordPressCacheSettings::TEXT_DOMAIN) . '</option><option value="network" ' . selected($this->network->policy($option), 'network', false) . '>' . esc_html__('Managed by network', WordPressCacheSettings::TEXT_DOMAIN) . '</option></select>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        submit_button(__('Save network settings', WordPressCacheSettings::TEXT_DOMAIN));
        echo '</form>';
    }

    private function sites(): void
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        $table = new NetworkSitesTable($this->queue, $this->effects, $this->history);
        $table->prepare_items();
        echo '<form method="get"><input type="hidden" name="page" value="sympress-nginx-cache" /><input type="hidden" name="tab" value="sites" />';
        $table->search_box(__('Search sites', WordPressCacheSettings::TEXT_DOMAIN), 'cache-sites');
        $table->display();
        echo '</form>';
    }

    private function networkActions(): void
    {
        $domain = get_network()->domain ?? '';
        echo '<h2>' . esc_html__('Purge network cache', WordPressCacheSettings::TEXT_DOMAIN) . '</h2><p>' . esc_html__('This removes cache entries across the shared network. Confirm the domain to proceed.', WordPressCacheSettings::TEXT_DOMAIN) . '</p>';
        $this->form('network');
        echo '<p><label for="network-confirmation">' . esc_html(sprintf(__('Type %s to confirm', WordPressCacheSettings::TEXT_DOMAIN), $domain)) . '</label><br /><input id="network-confirmation" name="confirmation" type="text" required autocomplete="off" /></p>';
        submit_button(__('Purge entire network', WordPressCacheSettings::TEXT_DOMAIN));
        echo '</form><h2>' . esc_html__('Multisite Nginx map', WordPressCacheSettings::TEXT_DOMAIN) . '</h2>';
        try {
            echo '<textarea class="large-text code" readonly rows="12" aria-label="' . esc_attr__('Generated Multisite map', WordPressCacheSettings::TEXT_DOMAIN) . '">' . esc_textarea($this->maps->generate()) . '</textarea>';
        } catch (\RuntimeException) {
            echo '<p role="alert">' . esc_html__('Map generation failed. Check network size and site data.', WordPressCacheSettings::TEXT_DOMAIN) . '</p>';
        }
    }
}
