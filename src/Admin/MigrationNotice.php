<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Migration\NginxHelperImporter;
use SymPress\NginxCache\Security\Capabilities;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class MigrationNotice
{
    public function __construct(private NginxHelperImporter $importer)
    {
    }

    public function notice(): void
    {
        if (!current_user_can(Capabilities::MANAGE)) {
            return;
        }
        $plugins = (array) get_option('active_plugins', []);
        $plugins = [...$plugins, ...array_keys((array) get_site_option('active_sitewide_plugins', []))];
        $helper = in_array('nginx-helper/nginx-helper.php', $plugins, true);
        $legacy = class_exists('NginxCache', false);
        if (!$helper && !$legacy) {
            return;
        }
        $source = $helper ? 'nginx-helper' : 'nginx-cache';
        $url = wp_nonce_url(add_query_arg(['action' => 'sympress_nginx_cache_migrate', 'preview' => '1', 'source' => $source], admin_url('admin-post.php')), 'sympress_nginx_cache_migrate');
        echo '<div class="notice notice-info"><p>' . esc_html__('A previous Nginx cache plugin is active. Preview its settings before importing, then deactivate it to avoid duplicate purges.', WordPressCacheSettings::TEXT_DOMAIN) . ' <a href="' . esc_url($url) . '">' . esc_html__('Preview migration', WordPressCacheSettings::TEXT_DOMAIN) . '</a></p></div>';
    }

    public function handle(): void
    {
        if (!current_user_can(Capabilities::MANAGE)) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('sympress_nginx_cache_migrate');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact literal comparison after nonce/capability checks.
        $preview = ($_REQUEST['preview'] ?? '') === '1';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact literal comparison after nonce/capability checks.
        $source = ($_REQUEST['source'] ?? '') === 'nginx-cache' ? 'nginx-cache' : 'nginx-helper';
        try {
            $report = $this->importer->import($source, $preview);
        } catch (\Throwable) {
            wp_die(esc_html__('Migration failed. Check the source and encryption configuration.', WordPressCacheSettings::TEXT_DOMAIN));
        }
        $body = '<h1>' . esc_html($preview ? __('Migration preview', WordPressCacheSettings::TEXT_DOMAIN) : __('Settings imported', WordPressCacheSettings::TEXT_DOMAIN)) . '</h1><table>';
        foreach ($report as $row) {
            $body .= '<tr><th>' . esc_html($row['option']) . '</th><td>' . esc_html((string) wp_json_encode($row['value'])) . '</td><td>' . esc_html($row['status']) . '</td></tr>';
        }
        $body .= '</table><p>' . esc_html__('Verify the cache key and protected HTTP endpoint. Existing Nginx configuration is not modified. Deactivate Nginx Helper after verification. WooCommerce hooks are built in; Multisite map generation is in Network Admin.', WordPressCacheSettings::TEXT_DOMAIN) . '</p>';
        if ($preview) {
            $url = wp_nonce_url(add_query_arg(['action' => 'sympress_nginx_cache_migrate', 'source' => $source], admin_url('admin-post.php')), 'sympress_nginx_cache_migrate');
            $body .= '<form method="post" action="' . esc_url($url) . '"><button type="submit">' . esc_html__('Apply import', WordPressCacheSettings::TEXT_DOMAIN) . '</button></form>';
        }
        $body .= '<p><a href="' . esc_url(admin_url('tools.php?page=sympress-nginx-cache')) . '">' . esc_html__('Back to Nginx Cache', WordPressCacheSettings::TEXT_DOMAIN) . '</a></p>';
        wp_die($body, esc_html__('Nginx Cache migration', WordPressCacheSettings::TEXT_DOMAIN), ['response' => 200]);
    }
}
