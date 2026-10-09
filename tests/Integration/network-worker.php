<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
wp_installing(false);
$root = getenv('NGINX_TEST_WORKER_ROOT');
if (!is_string($root) || !str_starts_with($root, sys_get_temp_dir() . '/sympress-multisite-cache-')) { throw new RuntimeException('Expected disposable cache fixture.'); }
require __DIR__ . '/scale-runtime.php';
add_action('sympress_nginx_cache_purged', static function (\SymPress\NginxCache\Value\PurgeResult $result): void {
    if ($result->dryRun || $result->partial) { return; }
    if (!add_option('sympress_nginx_cache_fixture_worker_completed', 1, '', false)) { throw new RuntimeException('Duplicate site purge execution.'); }
});
$result = $worker->run(maxRuntime: 10, network: true);
echo json_encode(['tasks' => $result['tasks'], 'exhausted' => $result['exhausted']], JSON_THROW_ON_ERROR) . PHP_EOL;
