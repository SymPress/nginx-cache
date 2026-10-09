<?php

declare(strict_types=1);

$workerSites = [];
for ($i = 0; $i < 8; ++$i) {
    $id = wp_insert_site(['domain' => 'example.test', 'path' => '/parallel-' . $i . '/', 'network_id' => 1]);
    check(is_int($id), 'initialize parallel worker site ' . $i);
    $workerSites[] = $id;
    switch_to_blog($id);
    try {
        update_option($settings::OPTION_TAG_INDEX_ENABLED, 0);
        update_option($settings::OPTION_PREWARM_ENABLED, 0);
        $url = home_url('/entry/');
        $key = 'https|GET|example.test|/parallel-' . $i . '/entry/';
        $hash = md5($key);
        $fs->dumpFile($root . '/' . substr($hash, -1) . '/' . substr($hash, -3, 2) . '/' . $hash, "\0fixture\nKEY: " . $key . "\n");
        $queue->enqueue(\SymPress\NginxCache\Value\PurgeRequest::urls([$url]));
    } finally { restore_current_blog(); }
}
putenv('NGINX_TEST_WORKER_ROOT=' . $root);
$parallel = [];
for ($i = 0; $i < 2; ++$i) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/network-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start parallel network worker.'); }
    fclose($pipes[0]);
    $parallel[] = [$process, $pipes];
}
$totalTasks = 0;
foreach ($parallel as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'parallel worker exits successfully: ' . $errors);
    $result = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
    $totalTasks += $result['tasks'];
}
foreach ($workerSites as $id) {
    switch_to_blog($id);
    try {
        check((int) get_option('sympress_nginx_cache_fixture_worker_completed') === 1 && $queue->count() === 0, 'site task completes once with two concurrent workers: ' . $id);
    } finally { restore_current_blog(); }
}
check($totalTasks === 8 && $pending->all() === [], 'two real network workers drain eight tasks without duplicate execution or stale markers');
putenv('NGINX_TEST_WORKER_ROOT');
$scope->reset();
