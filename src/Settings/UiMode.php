<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

use SymPress\NginxCache\Security\Capabilities;

final readonly class UiMode
{
    public const string OPTION = 'sympress_nginx_cache_ui_mode';
    public const string ACTION = 'sympress_nginx_cache_ui_mode';

    public function initialize(): void
    {
        if (get_option(self::OPTION, false) !== false) {
            return;
        }
        $db = $GLOBALS['wpdb'] ?? null;
        if (!$db instanceof \wpdb) {
            return;
        }
        $existing = $db->get_var($db->prepare('SELECT option_id FROM %i WHERE option_name LIKE %s OR option_name IN (%s,%s) LIMIT 1', $db->options, $db->esc_like('sympress_nginx_cache_') . '%', WordPressCacheSettings::OPTION_PATH, WordPressCacheSettings::OPTION_AUTO_PURGE));
        add_option(self::OPTION, $existing === null ? 'simple' : 'advanced', '', false);
    }

    public function simple(): bool
    {
        return get_option(self::OPTION, 'advanced') === 'simple';
    }

    public function toggleUrl(): string
    {
        return wp_nonce_url(add_query_arg(['action' => self::ACTION, 'mode' => $this->simple() ? 'advanced' : 'simple'], admin_url('admin-post.php')), self::ACTION);
    }

    public function handle(): void
    {
        check_admin_referer(self::ACTION);
        if (!current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('Cache management permission is required.', WordPressCacheSettings::TEXT_DOMAIN));
        }
        $mode = isset($_GET['mode']) && $_GET['mode'] === 'simple' ? 'simple' : 'advanced';
        update_option(self::OPTION, $mode, false);
        wp_safe_redirect(admin_url('tools.php?page=sympress-nginx-cache'));
        exit;
    }

    public function optionsCapability(): string
    {
        return Capabilities::MANAGE;
    }
}
