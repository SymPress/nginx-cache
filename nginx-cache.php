<?php

declare(strict_types=1);

/**
 * Plugin Name:       Nginx Cache
 * Description:       Kernel-integrated purge controls for Nginx FastCGI, proxy and uWSGI cache zones.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.5
 * Author:            SymPress
 * License:           GPL-2.0-or-later
 * Text Domain:       sympress-nginx-cache
 */

namespace SymPress\NginxCache;

use SymPress\Kernel\App;

if (!defined('ABSPATH')) {
    return;
}

if (!class_exists(App::class) || !class_exists(NginxCacheBundle::class)) {
    require_once __DIR__ . '/vendor/autoload.php';
}
