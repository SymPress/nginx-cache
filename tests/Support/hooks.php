<?php

declare(strict_types=1);

// Minimal hook dispatcher for isolated contract tests. Real WordPress binding
// is independently checked by the database integration suite.
function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['extension_hooks'][$hook][$priority][] = [$callback, $accepted_args];
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    add_filter($hook, $callback, $priority, $accepted_args);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $callbacks = $GLOBALS['extension_hooks'][$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as [$callback, $accepted]) {
            $value = $callback(...array_slice([$value, ...$args], 0, $accepted));
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    $callbacks = $GLOBALS['extension_hooks'][$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as [$callback, $accepted]) { $callback(...array_slice($args, 0, $accepted)); }
    }
}
