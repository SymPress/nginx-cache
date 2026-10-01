<?php

declare(strict_types=1);

use SymPress\NginxCache\Support\UninstallPolicy;

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!class_exists(UninstallPolicy::class)) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog((int) $siteId);
        try {
            UninstallPolicy::removeCurrentSiteData();
        } finally {
            restore_current_blog();
        }
    }
} else {
    UninstallPolicy::removeCurrentSiteData();
}
