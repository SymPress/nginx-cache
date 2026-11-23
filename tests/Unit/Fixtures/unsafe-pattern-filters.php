<?php

declare(strict_types=1);

// Adds unsafe bypass patterns and key templates; loaded only in isolated generator tests.
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return match ($hook) {
        'sympress_nginx_cache_bypass_user_agents' => [...$value, 'Google Page Speed', 'Bot"x', 'Bot#x', 'SafeBot'],
        'sympress_nginx_cache_key_template'       => '$scheme$request_method$host$request_uri"; include /srv/evil.conf; #',
        default                                   => $value,
    };
}
