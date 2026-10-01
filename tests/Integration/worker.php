<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$number = (int) ($argv[2] ?? 0);
if (($argv[1] ?? '') === 'index') {
    testIndex()->remember('https://example.test/concurrent/' . $number . '/', ['concurrent', 'item:' . $number]);
} elseif (($argv[1] ?? '') === 'queue') {
    testQueue()->push(\SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/queued/' . $number . '/']));
} elseif (($argv[1] ?? '') === 'lock') {
    testMutex()->synchronized('blocking-fixture', static function (): void {
        update_option('sympress_nginx_cache_worker_entered', true, false);
    });
} elseif (($argv[1] ?? '') === 'option-lock') {
    $store = new \SymPress\NginxCache\Support\WordPressOptionLockStore(new \SymPress\NginxCache\Time\CacheClock(new \Symfony\Component\Clock\NativeClock()));
    $key = new \Symfony\Component\Lock\Key('simultaneous-option-lock');
    update_option('sympress_nginx_cache_ready_' . $number, 'ready', false);
    $deadline = microtime(true) + 20;
    while ($wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'sympress_nginx_cache_start_lock')) !== '1') {
        if (microtime(true) > $deadline) { throw new RuntimeException('Start barrier timed out.'); }
        usleep(10000);
    }
    $won = false;
    try {
        $store->save($key);
        $won = true;
    } catch (\Symfony\Component\Lock\Exception\LockConflictedException) {
    }
    update_option('sympress_nginx_cache_result_' . $number, $won ? 'won' : 'lost', false);
    while ($wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'sympress_nginx_cache_finish_lock')) !== '1') {
        if (microtime(true) > $deadline) { throw new RuntimeException('Finish barrier timed out.'); }
        usleep(10000);
    }
    if ($won) { $store->delete($key); }
} else {
    throw new RuntimeException('Unknown worker operation.');
}
