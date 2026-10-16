<?php

declare(strict_types=1);

use PHPStan\ExtensionInstaller\GeneratedConfig;

// PHPStan loads registered extensions itself. Add only missing configs for
// plugin-free installs, preserving the same checks without duplicate includes.
$extensions = [
    'phpstan/phpstan-deprecation-rules' => ['rules.neon'],
    'phpstan/phpstan-phpunit'           => ['extension.neon', 'rules.neon'],
    'szepeviktor/phpstan-wordpress'     => ['extension.neon'],
];
$registered = class_exists(GeneratedConfig::class) ? GeneratedConfig::EXTENSIONS : [];
$includes = [];

foreach ($extensions as $package => $files) {
    $registeredIncludes = $registered[$package]['extra']['includes'] ?? [];
    foreach ($files as $file) {
        if (!in_array($file, $registeredIncludes, true)) {
            $includes[] = dirname(__DIR__) . '/vendor/' . $package . '/' . $file;
        }
    }
}

return ['includes' => $includes];
