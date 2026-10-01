<?php

declare(strict_types=1);

$schema = getenv('NGINX_TEST_DB_NAME');
$host = getenv('NGINX_TEST_DB_HOST') ?: '127.0.0.1:33079';
if (!is_string($schema) || !preg_match('/^sympress_review_nginx[a-z0-9_]*$/', $schema)) {
    throw new RuntimeException('A fresh disposable schema starting sympress_review_nginx is mandatory.');
}
[$server, $port] = array_pad(explode(':', $host, 2), 2, '3306');
$pdo = new PDO('mysql:host=' . $server . ';port=' . $port, getenv('NGINX_TEST_DB_USER') ?: 'root', getenv('NGINX_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// CREATE without IF NOT EXISTS prevents reuse or deletion of existing site data.
$pdo->exec('CREATE DATABASE `' . $schema . '`');
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo 'PASS ' . $message . PHP_EOL;
}
function startWorker(string $operation, int $number): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $operation, (string) $number], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Worker creation failed.');
    }
    fclose($pipes[0]);
    return [$process, $pipes];
}
function finishWorker(array $worker): void
{
    [$process, $pipes] = $worker;
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Worker failed: ' . $output);
    }
}
try {
    require __DIR__ . '/bootstrap.php';
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install('Disposable Nginx review', 'fixture-admin', 'fixture@example.test', false, '', bin2hex(random_bytes(24)));
    wp_installing(false);
    update_option('permalink_structure', '/%postname%/');
    $GLOBALS['wp_rewrite']->init();
    $index = testIndex();
    update_option($index::LEGACY_OPTION, ['legacy' => ['https://example.test/legacy/' => time()]], false);
    $denySchema = static fn (string $sql): string => str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS') ? 'INVALID SCHEMA SQL' : $sql;
    $quiet = $wpdb->suppress_errors(true);
    add_filter('query', $denySchema);
    try { $index->install(); } catch (RuntimeException) {}
    remove_filter('query', $denySchema);
    $wpdb->suppress_errors($quiet);
    check(get_option($index::LEGACY_OPTION) !== false && get_option($index::OPTION_VERSION, null) === null, 'failed schema creation retains migration source and no success marker');
    $index->install();
    check($index->urlsForTags(['legacy']) === ['https://example.test/legacy/'], 'legacy index migration');
    check(get_option($index::LEGACY_OPTION, null) === null, 'legacy large option removed after successful migration');
    $workers = [];
    for ($i = 0; $i < 20; ++$i) {
        $workers[] = startWorker('index', $i);
    }
    foreach ($workers as $worker) {
        finishWorker($worker);
    }
    check(count($index->urlsForTags(['concurrent'])) === 20, '20 concurrent index additions survive');
    $wpdb->queries = [];
    $index->remember('https://example.test/concurrent/1/', ['concurrent', 'item:1']);
    $writes = array_filter($wpdb->queries, static fn (array $query): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|START|COMMIT)/i', $query[0]) === 1);
    check($writes === [], 'repeated identical request issues zero database writes');
    for ($i = 0; $i < 55; ++$i) { $index->remember('https://example.test/bounded/' . $i . '/', ['bounded']); }
    check(count($index->urlsForTags(['bounded'])) === 50, 'per-tag URL retention remains bounded');
    for ($i = 0; $i < 17; ++$i) {
        $tags = [];
        for ($j = 0; $j < 64; ++$j) { $tags[] = 'bounded-tag:' . $i . ':' . $j; }
        $index->remember('https://example.test/bounded-tags/' . $i . '/', $tags);
    }
    check($index->stats()['tags'] <= 1000, 'global tag retention remains bounded');

    $queue = testQueue();
    $workers = [];
    for ($i = 0; $i < 12; ++$i) {
        $workers[] = startWorker('queue', $i);
    }
    foreach ($workers as $worker) {
        finishWorker($worker);
    }
    check(count($queue->all()[0]->urls) === 12, '12 concurrent queue producers survive merge');
    wp_cache_set('sympress_nginx_cache_queue', [], 'options');
    check($queue->count() === 1, 'stale option cache cannot hide pending work');
    check(!$queue->process(static fn (): bool => false) && $queue->count() === 1, 'failed purge stays queued');
    try {
        $queue->process(static function (): bool { throw new RuntimeException('fixture failure'); });
    } catch (RuntimeException) {
    }
    check($queue->count() === 1, 'exception leaves purge queued');
    $queue->process(static function () use ($queue): bool {
        $queue->push(\SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/arrived-during-purge/']));
        return true;
    });
    check(in_array('https://example.test/arrived-during-purge/', $queue->all()[0]->urls, true), 'producer during acknowledgement is retained');
    check($queue->process(static fn (): bool => true) && $queue->count() === 0, 'successful retry acknowledges work');
    $identical = \SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/same-event/']);
    $queue->push($identical);
    $queue->process(static function () use ($queue, $identical): bool { $queue->push($identical); return true; });
    check($queue->count() === 1, 'identical event during purge gets a fresh generation and is retained');
    $queue->process(static fn (): bool => true);
    // Real database advisory lock excludes another connection beyond callback lifetime.
    $scope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':blocking-fixture';
    $key = 'sympress-cache:' . substr(hash('sha256', $scope), 0, 40);
    $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $key));
    $worker = startWorker('lock', 0);
    $workers[] = $worker;
    usleep(800000);
    check(get_option('sympress_nginx_cache_worker_entered', false) === false, 'database mutex excludes competing process');
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    finishWorker($worker);
    wp_cache_delete('notoptions', 'options');
    check(get_option('sympress_nginx_cache_worker_entered') === '1', 'blocked process proceeds after owner releases');
    $scope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':failed-acquisition';
    $held = 'sympress-cache:' . substr(hash('sha256', $scope), 0, 40);
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$held]);
    $called = false;
    try {
        testMutex()->synchronized('failed-acquisition', static function () use (&$called): void { $called = true; });
        check(false, 'contended lock must time out');
    } catch (RuntimeException) {
        check(!$called, 'lock acquisition timeout prevents mutation callback');
    } finally {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$held]);
    }

    $clock = new \Symfony\Component\Clock\MockClock('2026-10-01');
    $store = new \SymPress\NginxCache\Support\WordPressOptionLockStore(new \SymPress\NginxCache\Time\CacheClock($clock));
    $old = new \Symfony\Component\Lock\Key('takeover');
    $new = new \Symfony\Component\Lock\Key('takeover');
    $store->save($old);
    $clock->sleep(31);
    $store->save($new);
    $store->delete($old);
    check($store->exists($new), 'expired owner cannot delete replacement lock');
    try {
        $store->putOffExpiration($old, 60);
        check(false, 'expired owner refresh rejected');
    } catch (\Symfony\Component\Lock\Exception\LockConflictedException) {
        check($store->exists($new), 'expired owner cannot refresh replacement lock');
    }
    $workers = [];
    for ($i = 0; $i < 12; ++$i) { $workers[] = startWorker('option-lock', $i); }
    foreach (['ready', 'result'] as $stage) {
        $deadline = microtime(true) + 20;
        while ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('sympress_nginx_cache_' . $stage . '_') . '%')) !== 12) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Concurrent lock barrier timed out.'); }
            usleep(10000);
        }
        if ($stage === 'ready') { update_option('sympress_nginx_cache_start_lock', true, false); }
    }
    $winners = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND option_value = %s', $wpdb->options, $wpdb->esc_like('sympress_nginx_cache_result_') . '%', 'won'));
    update_option('sympress_nginx_cache_finish_lock', true, false);
    foreach ($workers as $worker) { finishWorker($worker); }
    check($winners === 1, '12 simultaneous option lock contenders have exactly one owner');
    $settings = new \SymPress\NginxCache\Settings\WordPressCacheSettings('/tmp/sympress-nginx-missing-cache-' . $schema);
    $settings->register();
    wp_set_current_user(1);
    $_POST['_wpnonce'] = wp_create_nonce('sympress_nginx_cache-options');
    foreach ([$settings::OPTION_CLOUDFLARE_API_TOKEN, $settings::OPTION_REMOTE_SECRET] as $option) {
        update_option($option, 'fixture-secret-value', false);
        $stored = get_option($option);
        check(is_string($stored) && str_starts_with($stored, \SymPress\NginxCache\Security\SecretCipher::PREFIX) && !str_contains($stored, 'fixture-secret-value'), 'encrypted option ' . $option);
        update_option($option, '', false);
        check(get_option($option) === $stored, 'blank preserves ' . $option);
        $_POST[$option . '_clear'] = '1';
        $nonce = $_POST['_wpnonce'];
        $_POST['_wpnonce'] = 'invalid-fixture-nonce';
        update_option($option, '', false);
        check(get_option($option) === $stored, 'invalid nonce cannot clear ' . $option);
        $_POST['_wpnonce'] = $nonce;
        wp_set_current_user(0);
        update_option($option, '', false);
        check(get_option($option) === $stored, 'anonymous request cannot clear ' . $option);
        wp_set_current_user(1);
        update_option($option, '', false);
        unset($_POST[$option . '_clear']);
        check(get_option($option) === '', 'explicit clear ' . $option);
    }
    $cipher = new \SymPress\NginxCache\Security\SecretCipher();
    $wpdb->update($wpdb->options, ['option_value' => 'legacy-plaintext'], ['option_name' => $settings::OPTION_CLOUDFLARE_API_TOKEN]);
    wp_cache_delete($settings::OPTION_CLOUDFLARE_API_TOKEN, 'options');
    check($settings->cloudflareApiToken() === null, 'legacy plaintext fails closed');
    $settings->migrateLegacySecrets();
    check($settings->cloudflareApiToken() === 'legacy-plaintext' && str_starts_with(get_option($settings::OPTION_CLOUDFLARE_API_TOKEN), $cipher::PREFIX), 'legacy secret migration stores authenticated ciphertext');
    $wpdb->update($wpdb->options, ['option_value' => $cipher::PREFIX . 'corrupted'], ['option_name' => $settings::OPTION_CLOUDFLARE_API_TOKEN]);
    wp_cache_delete($settings::OPTION_CLOUDFLARE_API_TOKEN, 'options');
    check($settings->cloudflareApiToken() === null, 'corrupted ciphertext fails closed');
    foreach ([$settings::OPTION_CLOUDFLARE_API_TOKEN, $settings::OPTION_REMOTE_SECRET] as $option) {
        ob_start();
        \SymPress\NginxCache\Admin\SecretField::render($option);
        $html = ob_get_clean();
        check(str_contains($html, 'value=""') && !str_contains($html, 'fixture-secret-value') && !str_contains($html, 'sympress-secret:v1:'), 'rendered password field contains no plaintext or ciphertext');
        check(str_contains($html, $option . '_clear'), 'rendered explicit clear control');
    }
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.66';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.67';
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $policy = new \SymPress\NginxCache\Security\UrlPolicy();
    check($policy->normalizeSameOriginHttpUrl('https://attacker.example/path') === '', 'Host spoof cannot extend canonical origins');
    $history = new \SymPress\NginxCache\Purge\PurgeHistoryRepository(new \SymPress\NginxCache\Time\CacheClock(new \Symfony\Component\Clock\NativeClock()));
    $method = new ReflectionMethod($history, 'clientIp');
    check($method->invoke($history) === '192.0.2.10', 'IP history ignores forwarded headers');
    $rules = (new \SymPress\NginxCache\Config\BypassRuleProvider($settings))->rules(\SymPress\NginxCache\Value\CacheProfile::Safe);
    check(in_array('sympress_consent', $rules['cookies'], true), 'default consent cookie bypass');
    update_option($settings::OPTION_BYPASS_COOKIES, 'custom_consent');
    check(in_array('custom_consent', (new \SymPress\NginxCache\Config\BypassRuleProvider($settings))->rules(\SymPress\NginxCache\Value\CacheProfile::Safe)['cookies'], true), 'custom consent cookie bypass');
    wp_set_current_user(0);
    $published = wp_insert_post(['post_title' => 'Published target', 'post_status' => 'publish']);
    $draft = wp_insert_post(['post_title' => 'Draft target', 'post_status' => 'draft']);
    $foreign = wp_insert_post(['post_title' => 'Unrelated published', 'post_status' => 'publish']);
    $collector = new \SymPress\NginxCache\Purge\PurgeUrlCollector(new \SymPress\NginxCache\Surrogate\CacheTagResolver(), $index, $policy, $settings);
    check(!$collector->publicMutation('save_post', [$draft, get_post($draft)]), 'draft save does not invalidate public cache');
    check(!$collector->publicMutation('transition_post_status', ['draft', 'pending', get_post($draft)]), 'nonpublic transition ignored');
    check($collector->publicMutation('transition_post_status', ['draft', 'publish', get_post($published)]), 'unpublish invalidates previous public state');
    $index->remember(get_permalink($foreign), ['site:1', 'post:' . $foreign, 'author:1', 'posts']);
    check(!in_array(get_permalink($foreign), $collector->collect('save_post', [$published, get_post($published)]), true), 'targeted post purge excludes shared-site and author-tag peers');
    check(in_array(rest_url('wp/v2/posts'), $collector->collect('save_post', [$published, get_post($published)]), true), 'post change includes canonical REST collection without shared post tags');
    check($collector->postId('clean_user_cache', [$foreign]) === null, 'user id cannot masquerade as post id');
    check(!in_array(get_permalink($foreign), $collector->collect('clean_user_cache', [$foreign]), true), 'user hook excludes colliding foreign post URL');
    $comment = wp_insert_comment(['comment_post_ID' => $published, 'comment_content' => 'fixture', 'comment_approved' => 1]);
    check(in_array(get_permalink($published), $collector->collect('clean_comment_cache', [[$comment]]), true), 'comment array resolves actual parent post');
    check(!in_array(get_permalink($foreign), $collector->collect('clean_comment_cache', [[$comment]]), true), 'comment id never masquerades as foreign post id');
    $draftComment = wp_insert_comment(['comment_post_ID' => $draft, 'comment_content' => 'private fixture', 'comment_approved' => 1]);
    check(!$collector->publicMutation('clean_comment_cache', [[$draftComment]]), 'comment on draft does not invalidate public cache');
    check(!$collector->requiresFullPurge('woocommerce_delete_product_transients'), 'WooCommerce transient deletion is selective');
    check($collector->collect('woocommerce_delete_product_transients', [0]) === [], 'missing WooCommerce product id produces no full purge');
    check(!$collector->publicMutation('woocommerce_update_product', [$draft]), 'draft product update ignored');
    $order = new class($published) {
        public function __construct(private int $product) {}
        public function get_id(): int { return 999999; }
        public function get_items(): array { return [new class($this->product) {
            public function __construct(private int $product) {}
            public function get_product(): object { return new class($this->product) {
                public function __construct(private int $product) {}
                public function get_id(): int { return $this->product; }
                public function get_parent_id(): int { return 0; }
            }; }
        }]; }
    };
    $orderUrls = $collector->collect('woocommerce_reduce_order_stock', [$order]);
    check(in_array(get_permalink($published), $orderUrls, true) && !in_array(home_url('/?p=999999'), $orderUrls, true), 'order stock maps order items to products, never order id');
    // No provider calls: side effects use only MockHttpClient.
    $side = new \SymPress\NginxCache\Purge\PurgeSideEffectQueueRepository(testMutex(), new \SymPress\NginxCache\Time\CacheClock(new \Symfony\Component\Clock\NativeClock()));
    $result = \SymPress\NginxCache\Value\PurgeResult::success('/tmp/disposable-cache', 0, 0.1);
    $request = \SymPress\NginxCache\Value\PurgeRequest::urls([get_permalink($published)]);
    $side->push($result, $request);
    check(!$side->process(static fn (): bool => false) && $side->count() === 1, 'failed side effect remains stored');
    check($side->process(static fn (): bool => true) && $side->count() === 0, 'successful side-effect retry acknowledges');
    for ($i = 0; $i < 50; ++$i) {
        $side->push($result, $request);
    }
    try {
        $side->push($result, $request);
        check(false, 'queue overflow rejected');
    } catch (RuntimeException) {
        check($side->count() === 50, 'side-effect overflow preserves all previous work');
    }
    $side->process(static fn (): bool => true);
    update_option($settings::OPTION_CLOUDFLARE_ENABLED, 1);
    update_option($settings::OPTION_CLOUDFLARE_ZONE_ID, 'fixture-zone');
    $calls = 0;
    $accept = false;
    $mock = new \Symfony\Component\HttpClient\MockHttpClient(static function () use (&$calls, &$accept): \Symfony\Component\HttpClient\Response\MockResponse {
        ++$calls;
        return new \Symfony\Component\HttpClient\Response\MockResponse($accept ? '{"success":true}' : '{"success":false}', ['http_code' => 200]);
    });
    $clock = new \SymPress\NginxCache\Time\CacheClock(new \Symfony\Component\Clock\NativeClock());
    $effects = new \SymPress\NginxCache\Purge\PurgeSideEffectProcessor($settings, $side,
        new \SymPress\NginxCache\Purge\Prewarmer($mock, $settings, $policy, $clock), new \SymPress\NginxCache\Layer\CacheLayerCoordinator($settings),
        new \SymPress\NginxCache\Remote\RemotePurgeDispatcher($mock, $settings, $policy, $clock),
        new \SymPress\NginxCache\Remote\CloudflarePurgeDispatcher($mock, $settings, new \SymPress\NginxCache\Surrogate\CacheTagResolver(), $policy), $clock);
    $effects->enqueue($result, $request);
    $effects->process();
    check($side->count() === 1 && $calls === 0, 'unreadable Cloudflare token fails closed and preserves provider work');
    update_option($settings::OPTION_CLOUDFLARE_API_TOKEN, 'fixture-provider-token');
    $effects->process();
    check($side->count() === 1 && $calls === 1, 'provider rejection with HTTP 200 remains queued');
    $accept = true;
    $effects->process();
    check($side->count() === 0 && $calls === 2, 'mock provider acceptance acknowledges retry');
    update_option($settings::OPTION_CLOUDFLARE_ENABLED, 0);
    $calls = 0;
    update_option($settings::OPTION_FULL_PURGE_ENDPOINT, 'https://example.test/__purge');
    $endpoint = new \SymPress\NginxCache\Purge\FullPurgeEndpointDispatcher($mock, $settings, $policy, $clock);
    $failed = $endpoint->purge(\SymPress\NginxCache\Value\PurgeRequest::full(), $clock->highResolutionTimestamp(), $clock->timestamp());
    check(!$failed->successful && $calls === 0, 'full endpoint refuses unsigned provider calls');
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $probe = 'require ' . var_export($autoload, true) . '; $cipher = new \SymPress\NginxCache\Security\SecretCipher(); try { $cipher->encrypt("fixture", "remote"); exit(1); } catch (\RuntimeException) { exit(0); }';
    $process = proc_open([PHP_BINARY, '-r', $probe], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    foreach ($pipes as $pipe) { fclose($pipe); }
    check(proc_close($process) === 0, 'missing private key refuses plaintext storage');
    $ciphertext = $cipher->encrypt('fixture-token', 'cloudflare');
    $probe = 'require ' . var_export($autoload, true) . '; define("SYMPRESS_NGINX_CACHE_ENCRYPTION_KEY", "wrong-material-with-at-least-32-bytes"); exit((new \SymPress\NginxCache\Security\SecretCipher())->decrypt(' . var_export($ciphertext, true) . ', "cloudflare") === null ? 0 : 1);';
    $process = proc_open([PHP_BINARY, '-r', $probe], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    foreach ($pipes as $pipe) { fclose($pipe); }
    check(proc_close($process) === 0, 'wrong private key refuses stored credentials');
    // Register after an earlier action: never reconstruct an event without arguments.
    $http = new \Symfony\Component\HttpClient\MockHttpClient();
    $clock = new \SymPress\NginxCache\Time\CacheClock(new \Symfony\Component\Clock\NativeClock());
    $manager = new \SymPress\NginxCache\Purge\CacheManager($settings,
        new \SymPress\NginxCache\Purge\CachePurger(new \Symfony\Component\Filesystem\Filesystem(), new \SymPress\NginxCache\Filesystem\CachePathValidator(new \Symfony\Component\Filesystem\Filesystem()), new \SymPress\NginxCache\Purge\CacheFileResolver($settings, new \SymPress\NginxCache\Key\CacheKeyStrategy()), new \SymPress\NginxCache\Purge\FullPurgeEndpointDispatcher($http, $settings, $policy, $clock), $clock),
        $history, new \SymPress\NginxCache\Purge\PurgeEventEmitter(), $index,
        new \SymPress\NginxCache\Purge\PurgeSideEffectProcessor($settings, $side, new \SymPress\NginxCache\Purge\Prewarmer($http, $settings, $policy, $clock), new \SymPress\NginxCache\Layer\CacheLayerCoordinator($settings), new \SymPress\NginxCache\Remote\RemotePurgeDispatcher($http, $settings, $policy, $clock), new \SymPress\NginxCache\Remote\CloudflarePurgeDispatcher($http, $settings, new \SymPress\NginxCache\Surrogate\CacheTagResolver(), $policy), $clock));
    $processor = new \SymPress\NginxCache\Purge\PurgeQueueProcessor($settings, $queue, $manager, $clock);
    $subscriber = new \SymPress\NginxCache\Hook\AutomaticPurgeSubscriber($settings, $manager, $processor, $collector, new \SymPress\NginxCache\Purge\PurgeRequestMerger());
    update_option($settings::OPTION_AUTO_PURGE, 1);
    do_action('save_post', $published, get_post($published), true);
    $subscriber->register();
    $subscriber->flushPending();
    check($queue->count() === 0, 'late registration does not replay hooks without arguments');
    $subscriber->purgeOnce('woocommerce_delete_product_transients', 0);
    $subscriber->purgeOnce('save_post', $draft, get_post($draft));
    $subscriber->flushPending();
    check($queue->count() === 0, 'missing URLs and draft events do not become full purge');
    $subscriber->purgeOnce('clean_comment_cache', [$comment]);
    $subscriber->flushPending();
    check($queue->count() === 1 && !$queue->all()[0]->requiresFullPurge(), 'comment hook queues a selective request');
    wp_set_current_user(0);
    $queue->process(static fn (): bool => true);
    update_option($settings::OPTION_QUEUE_ENABLED, 0);
    $invalidRoot = static fn (): string => 'invalid-relative-root-fixture';
    add_filter('sympress_nginx_cache_path', $invalidRoot);
    check($settings->cachePath() === 'invalid-relative-root-fixture', 'immediate failure fixture is rejected before filesystem mutation');
    $immediate = new \SymPress\NginxCache\Hook\AutomaticPurgeSubscriber($settings, $manager, $processor, $collector, new \SymPress\NginxCache\Purge\PurgeRequestMerger());
    $immediate->purgeOnce('save_post', $published, get_post($published));
    $immediate->flushPending();
    check($queue->count() === 1, 'failed immediate purge falls back to persistent retry');
    remove_filter('sympress_nginx_cache_path', $invalidRoot);
    // Default retention and explicit data removal, without filesystem operations.
    \SymPress\NginxCache\Support\UninstallPolicy::removeCurrentSiteData();
    check($index->stats()['urls'] > 0 && $queue->count() === 1, 'default uninstall retains index and queue');
    update_option($settings::OPTION_DELETE_ON_UNINSTALL, 1);
    \SymPress\NginxCache\Support\UninstallPolicy::removeCurrentSiteData();
    check(get_option($settings::OPTION_AUTO_PURGE, null) === null && get_option($index::OPTION_VERSION, null) === null, 'explicit uninstall removes owned settings');
    check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $index::TABLE_SUFFIX)) === null, 'explicit uninstall drops only owned index table');
    echo 'Integration complete: ' . $checks . ' assertions; real WordPress ' . $GLOBALS['wp_version'] . '/MariaDB; zero external HTTP.' . PHP_EOL;
} finally {
    foreach ($workers ?? [] as $worker) {
        [$process, $pipes] = $worker;
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($process);
        }
    }
    $pdo->exec('DROP DATABASE `' . $schema . '`');
}
