<?php

declare(strict_types=1);

$autoloaders = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (!is_readable($autoloader)) {
        continue;
    }

    require_once $autoloader;
    break;
}

if (!defined('SYMPRESS_NGINX_CACHE_ENCRYPTION_KEY')) {
    define('SYMPRESS_NGINX_CACHE_ENCRYPTION_KEY', 'unit-fixture-key-with-at-least-32-bytes');
}
