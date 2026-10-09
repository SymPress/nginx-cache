<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$documentation = file_get_contents($root . '/docs/extending.md');
$hooks = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    preg_match_all('/\b(?:apply_filters|do_action)\(\s*[\'"](sympress_nginx_cache_[a-z0-9_]+)[\'"]/', file_get_contents($file->getPathname()), $matches);
    foreach ($matches[1] as $hook) { $hooks[$hook] = true; }
}
$missing = array_filter(array_keys($hooks), static fn (string $hook): bool => !str_contains($documentation, '`' . $hook . '`'));
if ($missing !== []) {
    fwrite(STDERR, 'Undocumented hooks: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}
echo count($hooks) . ' extension hooks documented.' . PHP_EOL;
