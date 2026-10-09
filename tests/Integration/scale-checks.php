<?php

declare(strict_types=1);

use SymPress\NginxCache\Purge\SiteScopedCacheScanner;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Settings\TagIndexSettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\TagIndexMaintenance;
use SymPress\NginxCache\Value\PurgeRequest;

function verifyScale(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS ' . $message . PHP_EOL;
}
function percentile(array $values, float $percentile): float
{
    sort($values);
    return $values[(int) ceil(count($values) * $percentile) - 1];
}
function collectQueryMaximum(float &$maximum): void
{
    global $wpdb;
    foreach ($wpdb->queries as $query) {
        $milliseconds = $query[1] * 1000;
        $GLOBALS['scale_max_statement_ms'] = max($GLOBALS['scale_max_statement_ms'] ?? 0.0, $milliseconds);
        if (preg_match('/^\s*(START TRANSACTION|COMMIT|ROLLBACK)\s*$/i', $query[0])) {
            $GLOBALS['scale_max_transaction_ms'] = max($GLOBALS['scale_max_transaction_ms'] ?? 0.0, $milliseconds);
            continue;
        }
        if ($query[1] * 1000 > $maximum) {
            $maximum = $query[1] * 1000;
            $GLOBALS['scale_slowest_query'] = substr($query[0], 0, 250);
        }
    }
    $wpdb->queries = [];
}
function seedCache(string $root, int $count, callable $key): void
{
    for ($i = 0; $i < $count; ++$i) {
        $value = $key($i);
        $hash = md5($value);
        $dir = $root . '/' . substr($hash, -1) . '/' . substr($hash, -3, 2);
        if (!is_dir($dir)) { mkdir($dir, 0700, true); }
        file_put_contents($dir . '/' . $hash, "\0NGINX fixture\nKEY: " . $value . "\n\nCached fixture response");
    }
}

$root = sys_get_temp_dir() . '/sympress-scale-cache-' . bin2hex(random_bytes(8));
require __DIR__ . '/scale-runtime.php';
$report = ['environment' => ['php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'], 'database' => $wpdb->get_var('SELECT VERSION()'), 'os' => PHP_OS_FAMILY, 'external_http' => 0], 'method' => 'Native PHP/WordPress; disposable DB; warm filesystem cache; lightweight synthetic Nginx entries. Timings exclude fixture seeding. Network sites have real WP_Site records and minimal options tables; theme/content tables are not needed by the measured worker.'];
try {
    update_option('permalink_structure', '/%postname%/');
    $GLOBALS['wp_rewrite']->init();
    update_option($settings::OPTION_AUTO_PURGE, 1);
    update_option($settings::OPTION_QUEUE_ENABLED, 1);
    update_option($settings::OPTION_DEBOUNCE_SECONDS, 0);
    update_option($settings::OPTION_TAG_INDEX_ENABLED, 0);
    $postBase = 1000;
    for ($batch = 0; $batch < 50; ++$batch) {
        $rows = [];
        for ($i = 0; $i < 1000; ++$i) {
            $id = $postBase + $batch * 1000 + $i;
            $rows[] = $wpdb->prepare("(%d,1,'2026-10-09 12:00:00','2026-10-09 12:00:00','Fixture','publish',%s,'post')", $id, 'fixture-' . $id);
        }
        verifyScale($wpdb->query("INSERT INTO {$wpdb->posts} (ID,post_author,post_date,post_date_gmt,post_title,post_status,post_name,post_type) VALUES " . implode(',', $rows)) === 1000, 'seed 1000 posts, batch ' . $batch);
    }
    for ($i = 0; $i < 200; ++$i) {
        $term = wp_insert_term('Scale category ' . $i, 'category');
        if (is_wp_error($term)) { throw new RuntimeException($term->get_error_message()); }
        $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->term_relationships} (object_id,term_taxonomy_id) SELECT ID,%d FROM {$wpdb->posts} WHERE ID >= %d AND MOD(ID,200) = %d", $term['term_taxonomy_id'], $postBase, $i));
    }
    seedCache($root, 10000, static fn (int $i): string => 'https|GET|example.test|/fixture-' . ($postBase + $i) . '/');
    $scanner = new SiteScopedCacheScanner(new CacheKeyStrategy());
    $matcher = $scope->matcher();
    $started = microtime(true);
    $scan = $scanner->scan($root, $matcher, fileBudget: 20000, timeBudget: 60);
    $scanSeconds = microtime(true) - $started;
    verifyScale($scan['scanned'] === 10000 && !$scan['partial'], 'scenario A scans all 10000 entries');
    $samples = [];
    $callback = static function (int $id) use ($subscriber): void { $instance = $subscriber(); $instance->purgeOnce('save_post', $id); $instance->flushPending(); };
    add_action('save_post', $callback);
    for ($i = 0; $i < 200; ++$i) {
        $started = microtime(true);
        do_action('save_post', $postBase + $i);
        $samples[] = (microtime(true) - $started) * 1000;
    }
    remove_action('save_post', $callback);
    $report['scenario_a'] = ['posts' => 50000, 'categories' => 200, 'cache_files' => 10000, 'samples' => count($samples), 'save_post_p95_ms' => percentile($samples, 0.95), 'site_scan_files_per_second' => 10000 / $scanSeconds, 'scan_seconds' => $scanSeconds];
    $started = microtime(true);
    $drained = $worker->run(maxRuntime: 120, maxTasks: 1000);
    $duration = microtime(true) - $started;
    verifyScale($queue->count() === 0 && $effects->count() === 0, 'scenario A queue drains without external providers');
    $report['scenario_a']['queue_tasks_per_minute'] = $drained['tasks'] * 60 / $duration;
    $report['scenario_a']['queue_tasks'] = $drained['tasks'];

    $index->install();
    update_option('sympress_nginx_cache_tag_urls_per_tag', 50);
    update_option('sympress_nginx_cache_tag_max_tags', 1000);
    $wpdb->queries = [];
    $maxIndexQuery = 0.0;
    for ($i = 0; $i < 100000; ++$i) {
        $index->remember(home_url('/index-' . $i . '/'), ['common:' . ($i % 10), 'unique:' . $i]);
        if ($i % 100 === 0) { collectQueryMaximum($maxIndexQuery); }
        if ($i % 10000 === 0) { echo 'Indexed URLs: ' . $i . PHP_EOL; }
    }
    collectQueryMaximum($maxIndexQuery);
    $maintenance = new TagIndexMaintenance(new TagIndexSettings(), $clock, testMutex());
    $ticks = 0;
    do {
        $removed = $maintenance->prune();
        verifyScale($removed <= 500, 'prune tick ' . $ticks . ' removes at most 500 rows');
        ++$ticks;
        $stats = $index->stats();
        collectQueryMaximum($maxIndexQuery);
    } while (($stats['tags'] > 1000 || $stats['rows'] > 50 * 1000) && $ticks < 1000);
    $tooLarge = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT tag FROM {$wpdb->prefix}sympress_cache_tags GROUP BY tag HAVING COUNT(*) > 50) oversized");
    verifyScale($stats['tags'] <= 1000 && $tooLarge === 0, '100000 indexed URLs converge to both configured limits');
    $report['tag_index'] = ['inserted_urls' => 100000, 'inserted_rows' => 200000, 'prune_ticks' => $ticks, 'final' => $stats, 'max_index_query_ms' => $maxIndexQuery, 'max_transaction_ms' => $GLOBALS['scale_max_transaction_ms'] ?? 0.0, 'max_statement_ms' => $GLOBALS['scale_max_statement_ms'] ?? 0.0];
    $report['tag_index']['slowest_index_query'] = $GLOBALS['scale_slowest_query'] ?? '';
    echo 'Index maximum query: ' . $maxIndexQuery . ' ms; ' . ($GLOBALS['scale_slowest_query'] ?? '') . PHP_EOL;
    $wpdb->queries = [];

    $fs->remove($root);
    $siteIds = [1];
    // Only tables used by the worker are seeded; full site initialization is
    // covered separately by multisite.php and excluded from benchmark timings.
    remove_action('wp_initialize_site', 'wp_initialize_site');
    for ($i = 1; $i < 500; ++$i) {
        $id = wp_insert_site(['domain' => 'example.test', 'path' => '/site-' . $i . '/', 'network_id' => 1]);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $siteIds[] = $id;
        $table = $wpdb->get_blog_prefix($id) . 'options';
        $wpdb->query($wpdb->prepare('CREATE TABLE %i LIKE %i', $table, $wpdb->options));
        foreach (['home' => 'https://example.test/site-' . $i, 'siteurl' => 'https://example.test/site-' . $i, WordPressCacheSettings::OPTION_TAG_INDEX_ENABLED => 0, WordPressCacheSettings::OPTION_DEBOUNCE_SECONDS => 0] as $name => $value) {
            $wpdb->query($wpdb->prepare('INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $table, $name, (string) $value, 'yes'));
        }
    }
    $scope->reset();
    seedCache($root, 100000, static function (int $i): string { $site = intdiv($i, 200); return 'https|GET|example.test|' . ($site === 0 ? '/' : '/site-' . $site . '/') . 'entry-' . ($i % 200) . '/'; });
    switch_to_blog($siteIds[250]);
    try {
        $started = microtime(true);
        $result = $manager->purgeConfiguredPath(PurgeRequest::full('scale-site'));
        while ($effects->count() > 0 && microtime(true) - $started < 120) { $effects->process(); }
        $siteDuration = microtime(true) - $started;
        verifyScale($result->successful && $effects->count() === 0 && ($history->last()['removed_entries'] ?? 0) === 200, 'scenario B full site purge removes exactly its 200 entries');
    } finally { restore_current_blog(); }
    for ($i = 0; $i < 500; ++$i) {
        switch_to_blog($siteIds[$i]);
        try { $queue->enqueue(PurgeRequest::urls([home_url('/entry-0/')])); }
        finally { restore_current_blog(); }
    }
    $started = microtime(true);
    $networkResult = $worker->run(maxRuntime: 600, maxTasks: 2000, network: true);
    $workerDuration = microtime(true) - $started;
    verifyScale(count($networkResult['sites']) === 500 && (new \SymPress\NginxCache\Purge\NetworkPendingRepository(testMutex()))->all() === [], 'network worker drains exactly 500 pending sites');
    $report['scenario_b'] = ['sites' => 500, 'cache_files_per_site' => 200, 'cache_files' => 100000, 'site_full_purge_seconds' => $siteDuration, 'network_worker_seconds' => $workerDuration, 'network_worker_tasks' => $networkResult['tasks']];
    for ($batch = 0; $batch < 45; ++$batch) {
        $rows = [];
        for ($i = 0; $i < 100; ++$i) {
            $rows[] = $wpdb->prepare('(%s,%s,1)', 'example.test', '/list-only-' . ($batch * 100 + $i) . '/');
        }
        $wpdb->query("INSERT INTO {$wpdb->blogs} (domain,path,site_id) VALUES " . implode(',', $rows));
    }
    update_site_option('blog_count', 5000);
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH . 'wp-admin/includes/screen.php';
    require_once ABSPATH . 'wp-admin/includes/template.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    set_current_screen('sites-network');
    $list = new \SymPress\NginxCache\Admin\NetworkSitesTable($queue, $effects, $history);
    $wpdb->queries = [];
    $started = microtime(true);
    $list->prepare_items();
    $listSeconds = microtime(true) - $started;
    verifyScale(count($list->items) === 20 && $listSeconds < 1, '5000-site network list loads only 20 rows in under one second');
    $report['network_list'] = ['sites' => 5000, 'page_rows' => count($list->items), 'seconds' => $listSeconds, 'queries' => count($wpdb->queries)];
    $report['targets'] = ['save_post_p95_under_30_ms' => $report['scenario_a']['save_post_p95_ms'] < 30, 'scan_at_least_5000_files_per_second' => $report['scenario_a']['site_scan_files_per_second'] >= 5000, 'index_query_under_200_ms' => $maxIndexQuery < 200];
    $output = dirname(__DIR__, 2) . '/build';
    if (!is_dir($output)) { mkdir($output, 0700, true); }
    file_put_contents($output . '/scale-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    verifyScale($maxIndexQuery < 200, 'index data queries stay below 200 ms (measured ' . round($maxIndexQuery, 2) . ' ms); transaction I/O latency is reported separately');
} finally { $fs->remove($root); }
