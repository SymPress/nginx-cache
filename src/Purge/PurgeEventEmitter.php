<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Value\PurgeResult;

final readonly class PurgeEventEmitter
{
    public function emit(PurgeResult $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        if ($result->successful) {
            do_action('sympress_nginx_cache_purged', $result);
            do_action('nginx_cache_zone_purged', $result->path);

            return;
        }

        do_action('sympress_nginx_cache_purge_failed', $result);
    }
}
