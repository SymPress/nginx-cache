<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Support;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\TagIndexRepository;

final readonly class UninstallPolicy
{
    public static function removeCurrentSiteData(): void
    {
        if (!function_exists('get_option') || !get_option(WordPressCacheSettings::OPTION_DELETE_ON_UNINSTALL, false)) {
            return;
        }
        $database = $GLOBALS['wpdb'] ?? null;
        if (!$database instanceof \wpdb) {
            throw new \RuntimeException('Uninstall requires the WordPress database.');
        }
        $names = $database->get_col($database->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $database->options, $database->esc_like('sympress_nginx_cache_') . '%'));
        foreach ($names as $name) {
            delete_option((string) $name);
        }
        delete_option(WordPressCacheSettings::OPTION_PATH);
        delete_option(WordPressCacheSettings::OPTION_AUTO_PURGE);
        $query = $database->prepare('DROP TABLE IF EXISTS %i', $database->prefix . TagIndexRepository::TABLE_SUFFIX);
        if ($query === null || $database->query($query) === false) {
            throw new \RuntimeException('Unable to remove the tag index.');
        }
        foreach (['sympress_nginx_cache_process_queue', 'sympress_nginx_cache_process_side_effects'] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
