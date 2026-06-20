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
