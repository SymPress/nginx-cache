<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Inspection;

final readonly class EnvironmentDetector
{
    /** @return array<string, mixed> */
    public function detect(): array
    {
        $signals = [];

        if (getenv('DDEV_PROJECT') || is_file('/mnt/ddev_config/nginx_full/nginx-site.conf')) {
            $signals[] = 'ddev';
        }

        if (is_file('/.dockerenv') || getenv('container') !== false) {
            $signals[] = 'docker';
        }

        if (getenv('KUBERNETES_SERVICE_HOST')) {
            $signals[] = 'kubernetes';
        }

        foreach (['/usr/local/psa' => 'plesk', '/etc/runcloud' => 'runcloud', '/opt/gridpane' => 'gridpane'] as $path => $name) {
            if (!is_dir($path)) {
                continue;
            }

            $signals[] = $name;
        }

        $software = is_string($_SERVER['SERVER_SOFTWARE'] ?? null) ? $_SERVER['SERVER_SOFTWARE'] : '';
        $nginx = stripos($software, 'nginx') !== false;

        return [
            'server_software' => $software,
            'nginx_detected'  => $nginx,
            'nginx_flavour'   => $nginx && stripos($software, 'plus') !== false ? 'nginx-plus' : ($nginx ? 'nginx-open-source' : 'unknown'),
            'signals'         => array_values(array_unique($signals)),
            'php_sapi'        => PHP_SAPI,
        ];
    }
}
