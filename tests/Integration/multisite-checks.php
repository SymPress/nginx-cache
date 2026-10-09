<?php

declare(strict_types=1);

use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\CachePurger;
use SymPress\NginxCache\Purge\CacheWorker;
use SymPress\NginxCache\Purge\NetworkPendingRepository;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Purge\FullPurgeEndpointDispatcher;
use SymPress\NginxCache\Purge\Prewarmer;
use SymPress\NginxCache\Purge\PurgeEventEmitter;
use SymPress\NginxCache\Purge\PurgeHistoryRepository;
use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use SymPress\NginxCache\Purge\PurgeSideEffectQueueRepository;
use SymPress\NginxCache\Purge\SiteScopeResolver;
use SymPress\NginxCache\Remote\CloudflarePurgeDispatcher;
use SymPress\NginxCache\Remote\RemotePurgeDispatcher;
use SymPress\NginxCache\Security\Capabilities;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\NetworkSettings;
use SymPress\NginxCache\Settings\OptionSource;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeScope;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo 'PASS ' . $message . PHP_EOL;
}

$root = sys_get_temp_dir() . '/sympress-multisite-cache-' . bin2hex(random_bytes(8));
$fs = new Filesystem();
$shop = wp_insert_site(['domain' => 'example.test', 'path' => '/shop/', 'network_id' => 1]);
$child = wp_insert_site(['domain' => 'example.test', 'path' => '/shop/child/', 'network_id' => 1]);
$other = wp_insert_site(['domain' => 'other.test', 'path' => '/', 'network_id' => 1]);
check(is_int($shop) && is_int($child) && is_int($other), 'three additional sites initialized by real WordPress');
$keys = [
    'a' => 'https|GET|example.test|/',
    'b' => 'https|GET|example.test|/shop/product/',
    'c' => 'https|GET|example.test|/shop/child/page/',
    'd' => 'https|GET|other.test|/',
    'e' => 'unknown-key-format',
    'f' => 'https|GET|mapped.test|/product/',
];
foreach ($keys as $name => $key) { $fs->dumpFile($root . '/a/' . str_repeat($name, 32), "\0binary\nKEY: " . $key . "\n"); }
$clock = new CacheClock(new MockClock('2026-10-09 12:00:00'));
$settings = new WordPressCacheSettings($root);
$policy = new UrlPolicy();
$scope = new SiteScopeResolver();
$http = new MockHttpClient(static fn () => throw new RuntimeException('Unexpected external HTTP.'));
$history = new PurgeHistoryRepository($clock);
$events = new PurgeEventEmitter();
$purger = new CachePurger($fs, new CachePathValidator($fs), new CacheFileResolver($settings, new CacheKeyStrategy()), new FullPurgeEndpointDispatcher($http, $settings, $policy, $clock, $scope), $clock, $scope);
try {
    check(is_multisite(), 'Multisite bootstrap is active');
    $main = $scope->matcher();
    check($main->matches('example.test', '/') && !$main->matches('example.test', '/shop/product/'), 'main site excludes the longest matching child prefix');
    switch_to_blog($shop);
    try {
        update_option('home', 'https://mapped.test');
        $matcher = $scope->matcher();
        check($matcher->matches('example.test', '/shop/product/') && $matcher->matches('mapped.test', '/product/'), 'original host and mapped home belong to the same site');
        check(!$matcher->matches('example.test', '/shop/child/page/'), 'nested sites remain outside shop scope');
        $network = new NetworkSettings();
        $options = new OptionSource($network);
        add_filter('pre_update_option', $options->protectUpdate(...), 10, 3);
        $network->save(WordPressCacheSettings::OPTION_PATH, $root);
        update_option(WordPressCacheSettings::OPTION_PATH, '/wrong-site-root');
        check($settings->cachePath() === $root, 'locked network path wins and tampered site write is ignored');
        $caps = new Capabilities($options);
        add_filter('map_meta_cap', $caps->map(...), 10, 4);
        $editor = wp_insert_user(['user_login' => 'fixture-editor', 'user_pass' => bin2hex(random_bytes(16)), 'role' => 'editor']);
        $admin = wp_insert_user(['user_login' => 'fixture-site-admin', 'user_pass' => bin2hex(random_bytes(16)), 'role' => 'administrator']);
        $network->save(Capabilities::ROLES_OPTION, ['editor']);
        wp_set_current_user($editor);
        check(current_user_can(Capabilities::PURGE_URL) && !current_user_can(Capabilities::PURGE_SITE) && !current_user_can(Capabilities::MANAGE), 'editor only receives delegated URL purging');
        wp_set_current_user($admin);
        check(current_user_can(Capabilities::PURGE_SITE) && !current_user_can(Capabilities::PURGE_NETWORK), 'site administrator cannot purge the network');
        $before = [];
        foreach ($keys as $name => $_) { $file = $root . '/a/' . str_repeat($name, 32); $before[$name] = [hash_file('sha256', $file), filemtime($file)]; }
        $dry = $purger->purgeRequest($root, PurgeRequest::full(dryRun: true));
        check($dry->successful && $dry->removedEntries === 2 && $dry->unmatched === 1, 'site dry run reports only owned entries and unreadable keys');
        foreach ($before as $name => $snapshot) { $file = $root . '/a/' . str_repeat($name, 32); check($snapshot === [hash_file('sha256', $file), filemtime($file)], 'dry run preserves file ' . $name); }
        check(!file_exists($root . '/.sympress-nginx-cache.lock'), 'dry run creates no lock');
        $endpointCalls = 0;
        $endpointClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$endpointCalls): \Symfony\Component\HttpClient\Response\MockResponse {
            ++$endpointCalls;
            $headers = [];
            foreach ($options['normalized_headers'] as $name => $values) { $headers[$name] = explode(': ', $values[0], 2)[1]; }
            $signed = $headers['x-sympress-timestamp'] . '.full-purge.' . $headers['x-sympress-purge-scope'];
            if ($headers['x-sympress-purge-scope'] === 'site') {
                $signed .= '.' . $headers['x-sympress-purge-host'] . '.' . $headers['x-sympress-purge-path'] . '.' . $headers['x-sympress-site-boundaries'];
                $metadata = json_decode(base64_decode($headers['x-sympress-site-boundaries'], true), true, flags: JSON_THROW_ON_ERROR);
                check(in_array('/shop/child/', $metadata['paths']['example.test'], true), 'signed endpoint metadata includes nested ownership boundaries');
            }
            check(hash_equals('sha256=' . hash_hmac('sha256', $signed, 'fixture-endpoint-secret'), $headers['x-sympress-signature']), 'endpoint signature authenticates the complete purge scope');
            return new \Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 204]);
        });
        $network->save($settings::OPTION_FULL_PURGE_ENDPOINT, 'https://1.1.1.1/fixture-full');
        $network->save($settings::OPTION_REMOTE_SECRET, $settings->sanitizeStoredSecret('fixture-endpoint-secret', $settings::OPTION_REMOTE_SECRET));
        $endpoint = new FullPurgeEndpointDispatcher($endpointClient, $settings, $policy, $clock, $scope);
        check(!$endpoint->purge(PurgeRequest::full(), $clock->highResolutionTimestamp(), $clock->timestamp())->successful && $endpointCalls === 0, 'shared full endpoint requires explicit site-scope support before any HTTP');
        define('SYMPRESS_NGINX_CACHE_ENDPOINT_SUPPORTS_SITE_SCOPE', true);
        check($endpoint->purge(PurgeRequest::full(dryRun: true), $clock->highResolutionTimestamp(), $clock->timestamp())->successful && $endpointCalls === 0, 'scoped endpoint dry run performs no HTTP');
        check($endpoint->purge(PurgeRequest::full(), $clock->highResolutionTimestamp(), $clock->timestamp())->successful, 'site endpoint transmits the signed boundary contract');
        check($endpoint->purge(PurgeRequest::full(scope: PurgeScope::Network), $clock->highResolutionTimestamp(), $clock->timestamp())->successful && $endpointCalls === 2, 'network endpoint uses a separately authenticated explicit scope');
        $network->save($settings::OPTION_FULL_PURGE_ENDPOINT, '');
        $network->save($settings::OPTION_REMOTE_SECRET, '');
        define('SYMPRESS_NGINX_CACHE_SCAN_FILE_BUDGET', 1);
        $index = testIndex();
        $index->install();
        $sideQueue = new PurgeSideEffectQueueRepository(testMutex(), $clock);
        $effects = new PurgeSideEffectProcessor($settings, $sideQueue, new Prewarmer($http, $settings, $policy, $clock), new CacheLayerCoordinator($settings), new RemotePurgeDispatcher($http, $settings, $policy, $clock), new CloudflarePurgeDispatcher($http, $settings, new CacheTagResolver(), $policy), $clock, $purger, $scope, $index, $history, $events);
        $manager = new CacheManager($settings, $purger, $history, $events, $index, $effects);
        $foreign = $manager->purgeConfiguredPath(PurgeRequest::urls(['https://example.test/shop/child/page/']));
        check(!$foreign->successful && $sideQueue->count() === 0 && is_file($root . '/a/' . str_repeat('c', 32)), 'selective site purge refuses nested-site URLs before local or remote effects');
        $result = $manager->purgeConfiguredPath(PurgeRequest::full());
        check($result->partial && $sideQueue->count() === 1, 'budget limit persists continuation without any optional provider');
        for ($i = 0; $i < 10 && $sideQueue->count() > 0; ++$i) { $clock->sleepMicroseconds(2000000); $effects->process(); }
        check($sideQueue->count() === 0, 'bounded worker scans converge without exhausting retries');
        check(!file_exists($root . '/a/' . str_repeat('b', 32)) && !file_exists($root . '/a/' . str_repeat('f', 32)), 'both owned hosts have been purged');
        foreach (['a', 'c', 'd', 'e'] as $name) { $file = $root . '/a/' . str_repeat($name, 32); check($before[$name] === [hash_file('sha256', $file), filemtime($file)], 'other sites and unknown key remain byte-identical: ' . $name); }
        check(($history->last()['partial'] ?? true) === false && ($history->last()['removed_entries'] ?? 0) === 2, 'history reports the completed cumulative site result');
        foreach (['b', 'f'] as $name) { $fs->dumpFile($root . '/a/' . str_repeat($name, 32), "\0binary\nKEY: " . $keys[$name] . "\n"); }
        $failQueueWrite = static function (string $sql): string {
            if (str_contains($sql, 'sympress_nginx_cache_side_effect_queue') && preg_match('/^\s*(INSERT|UPDATE)/i', $sql)) { throw new RuntimeException('Fixture queue write failure'); }
            return $sql;
        };
        add_filter('query', $failQueueWrite);
        try { $lost = $manager->purgeConfiguredPath(PurgeRequest::full()); }
        finally { remove_filter('query', $failQueueWrite); }
        check($lost->partial && $effects->attentionReason() === 'storage-error' && is_array(get_option($effects::SCAN_RECOVERY_PREFIX . 'site')), 'failed continuation storage records a scoped rescan marker');
        $effects->retry();
        for ($i = 0; $i < 10 && $sideQueue->count() > 0; ++$i) { $clock->sleepMicroseconds(2000000); $effects->process(); }
        check($sideQueue->count() === 0 && !is_file($root . '/a/' . str_repeat('b', 32)) && !is_file($root . '/a/' . str_repeat('f', 32)) && get_option($effects::SCAN_RECOVERY_PREFIX . 'site', false) === false, 'operator retry rescans from the beginning after continuation storage recovers');
        $pending = new NetworkPendingRepository(testMutex());
        $queue = new PurgeQueueProcessor($settings, testQueue($clock), $manager, $clock);
        $queue->enqueue(PurgeRequest::urls(['https://mapped.test/product/']));
        $snapshot = $pending->all();
        $pending->mark();
        $pending->clear($shop, $snapshot[$shop], static fn (): bool => true);
        check(isset($pending->all()[$shop]), 'new producer generation survives stale worker acknowledgement');
        switch_to_blog($other);
        try {
            testIndex()->install();
            $queue->enqueue(PurgeRequest::urls([home_url('/')]));
        } finally { restore_current_blog(); }
        $worker = new CacheWorker($queue, $effects, $pending, $clock);
        $budgeted = $worker->run(maxTasks: 1, network: true);
        check($budgeted['tasks'] === 1 && count($pending->all()) >= 1, 'network worker respects the task budget');
        $drained = $worker->run(network: true);
        check($drained['tasks'] === 1 && $pending->all() === [], 'network worker drains only pending site queues');
        check(!isset($drained['sites'][1]) && !isset($drained['sites'][$child]), 'idle main and child sites are never visited by the network worker');
        check(get_current_blog_id() === $shop, 'network worker restores the calling blog');
        require __DIR__ . '/v1-admin-checks.php';
        $networkResult = $manager->purgeConfiguredPath(PurgeRequest::full(scope: PurgeScope::Network));
        check($networkResult->successful && $networkResult->scope === PurgeScope::Network && !is_dir($root . '/a'), 'explicit network full purge removes the shared root entries');
        $withoutPolylang = $config->generate();
        require __DIR__ . '/network-worker-checks.php';
        require __DIR__ . '/polylang-checks.php';
    } finally { restore_current_blog(); }
    echo 'Multisite integration complete: ' . $checks . ' assertions; WordPress ' . $GLOBALS['wp_version'] . '; zero external HTTP.' . PHP_EOL;
} finally { $fs->remove($root); }
