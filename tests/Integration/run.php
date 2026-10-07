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
    $scope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':' . $index::LEGACY_OPTION;
    $indexLock = 'sympress-cache:' . substr(hash('sha256', $scope), 0, 40);
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$indexLock]);
    $started = microtime(true);
    $index->install();
    check(microtime(true) - $started < 0.5 && get_option($index::OPTION_VERSION, null) === null, 'contended schema initialization returns immediately without a success marker');
    $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $statement->execute([$indexLock]);
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
    for ($i = 0; $i < 50; ++$i) {
        $index->remember('https://example.test/tracking/?utm_source=' . $i, ['tracking']);
    }
    check($index->urlsForTags(['tracking']) === ['https://example.test/tracking/'], '50 tracking queries retain one canonical tag-index URL');
    $index->remember('https://example.test/', ['homepage']);
    $index->remember('https://example.test/?s=foo', ['search']);
    $index->remember('https://example.test/?p=123', ['post']);
    $index->remember('https://example.test/?s=foo&utm_source=ad', ['mixed']);
    check($index->urlsForTags(['homepage']) === ['https://example.test/'], 'functional queries do not overwrite homepage tags');
    check($index->urlsForTags(['post']) === [] && $index->urlsForTags(['search']) === [] && $index->urlsForTags(['mixed']) === [], 'bypassed semantic and mixed queries consume no tag-index entries');
    for ($i = 0; $i < 55; ++$i) { $index->remember('https://example.test/bounded/' . $i . '/', ['bounded']); }
    check(count($index->urlsForTags(['bounded'])) === 50, 'per-tag URL retention remains bounded');
    for ($i = 0; $i < 17; ++$i) {
        $tags = [];
        for ($j = 0; $j < 64; ++$j) { $tags[] = 'bounded-tag:' . $i . ':' . $j; }
        $index->remember('https://example.test/bounded-tags/' . $i . '/', $tags);
    }
    check($index->stats()['tags'] <= 1000, 'global tag retention remains bounded');

    $queueClock = new \Symfony\Component\Clock\MockClock('2026-10-02');
    $queue = testQueue(new \SymPress\NginxCache\Time\CacheClock($queueClock));
    $workers = [];
    for ($i = 0; $i < 12; ++$i) {
        $workers[] = startWorker('queue', $i);
    }
    foreach ($workers as $worker) {
        finishWorker($worker);
    }
    check(count($queue->all()[0]->urls) === 12, '12 concurrent queue producers survive merge');
    $scope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':sympress_nginx_cache_queue';
    $heldQueueKey = 'sympress-cache:' . substr(hash('sha256', $scope), 0, 40);
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$heldQueueKey]);
    $started = microtime(true);
    $queue->push(\SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/contended-producer/']));
    check(microtime(true) - $started < 0.5, 'producer persists without waiting for a contended mutation lock');
    check(in_array('https://example.test/contended-producer/', $queue->all()[0]->urls, true), 'essential purge survives lock contention in durable inbox');
    add_option('sympress_nginx_cache_queue_inbox_corrupt_fixture', 'invalid retained payload', '', false);
    check(in_array('https://example.test/contended-producer/', $queue->all()[0]->urls, true), 'corrupt inbox item does not stop valid purges');
    check(get_option('sympress_nginx_cache_queue_inbox_corrupt_fixture', null) === null, 'corrupt inbox item leaves the active inbox');
    check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, 'sympress_nginx_cache_queue_quarantine_%')) === 1, 'corrupt inbox payload retained in quarantine for inspection');
    $called = false;
    try {
        $queue->process(static function () use (&$called): bool { $called = true; return true; });
    } catch (\SymPress\NginxCache\Support\MutationLockUnavailable) {
    }
    check(!$called && $queue->count() === 1, 'contended processing cannot delete or execute unreserved work');
    $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $statement->execute([$heldQueueKey]);
    wp_cache_set('sympress_nginx_cache_queue', [], 'options');
    check($queue->count() === 1, 'stale option cache cannot hide pending work');
    check(!$queue->process(static fn (): bool => false) && $queue->count() === 1, 'failed purge stays queued');
    $queueClock->sleep(60);
    try {
        $queue->process(static function (): bool { throw new RuntimeException('fixture failure'); });
    } catch (RuntimeException) {
    }
    check($queue->count() === 1, 'exception leaves purge queued');
    check($queue->inspect()[0]['attempts'] === 2, 'purge exception consumes a persisted retry attempt');
    $queueClock->sleep(120);
    $queue->process(static function () use ($queue): bool {
        $queue->push(\SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/arrived-during-purge/']));
        return true;
    });
    check(in_array('https://example.test/arrived-during-purge/', $queue->all()[0]->urls, true), 'producer during acknowledgement is retained');
    $queueClock->sleep(240);
    check($queue->process(static fn (): bool => true) && $queue->count() === 0, 'successful retry acknowledges work');
    $identical = \SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/same-event/']);
    $queue->push($identical);
    $queue->process(static function () use ($queue, $identical): bool { $queue->push($identical); return true; });
    check($queue->count() === 1, 'identical event during purge gets a fresh generation and is retained');
    check($queue->inspect()[0]['attempts'] === 0, 'successful purge gives concurrent new work a fresh retry budget');
    $queueClock->sleep(60);
    $queue->process(static fn (): bool => true);
    $queue->push($identical);
    $failedCalls = 0;
    foreach ([60, 120, 240, 300, 300] as $attemptIndex => $delay) {
        $before = $queueClock->now()->getTimestamp();
        $queue->process(static function () use (&$failedCalls): bool { ++$failedCalls; return false; });
        $state = $queue->inspect()[0];
        check($state['attempts'] === $attemptIndex + 1 && $state['retry_at'] === $before + $delay, 'purge persists bounded attempt and backoff ' . ($attemptIndex + 1));
        $queue->process(static function () use (&$failedCalls): bool { ++$failedCalls; return false; });
        check($failedCalls === $attemptIndex + 1, 'early purge worker cannot bypass backoff ' . ($attemptIndex + 1));
        $queueClock->sleep($delay);
    }
    $queue->push(\SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/retained-after-exhaustion/']));
    $queueClock->sleep(10000);
    $queue->process(static function () use (&$failedCalls): bool { ++$failedCalls; return true; });
    check($failedCalls === 5 && $queue->count() === 1 && $queue->inspect()[0]['exhausted'] && $queue->nextAttemptAt() === null, 'exhausted purge work survives new producers without automatic retries');
    check(in_array('https://example.test/retained-after-exhaustion/', $queue->all()[0]->urls, true), 'exhausted purge retains concurrent new URLs');
    $queue->retry();
    check($queue->process(static fn (): bool => true) && $queue->count() === 0, 'explicit purge retry resets exhaustion and acknowledges retained work');
    // Upgrade legacy queue records without losing their first acknowledgement.
    update_option('sympress_nginx_cache_queue', [$identical->toArray()], false);
    check($queue->process(static fn (): bool => true) && $queue->count() === 0, 'legacy purge queue gains metadata and still acknowledges');
    // Real database advisory lock excludes another connection beyond callback lifetime.
    $scope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':blocking-fixture';
    $key = 'sympress-cache:' . substr(hash('sha256', $scope), 0, 40);
    $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $key));
    $worker = startWorker('lock', 0);
    $workers[] = $worker;
    $started = microtime(true);
    finishWorker($worker);
    check(microtime(true) - $started < 1, 'contended database mutex returns without waiting');
    check(get_option('sympress_nginx_cache_worker_entered', false) === false, 'database mutex excludes competing process');
    check(get_option('sympress_nginx_cache_worker_contended') === '1', 'contended worker reports retryable lock failure');
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    finishWorker(startWorker('lock', 0));
    wp_cache_delete('notoptions', 'options');
    check(get_option('sympress_nginx_cache_worker_entered') === '1', 'new attempt proceeds after owner releases');
    $scope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':failed-acquisition';
    $held = 'sympress-cache:' . substr(hash('sha256', $scope), 0, 40);
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$held]);
    $called = false;
    try {
        testMutex()->synchronized('failed-acquisition', static function () use (&$called): void { $called = true; });
        check(false, 'contended lock must fail immediately');
    } catch (RuntimeException) {
        check(!$called, 'lock contention prevents mutation callback');
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
    check($settings->sanitizeSecret(' token<script>blocked</script><style>blocked</style> ') === 'token', 'native WordPress secret sanitization removes script and style bodies');
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
    check(!in_array('sympress_consent', $rules['cookies'], true), 'cookie-invariant SymPress consent keeps page cache enabled');
    update_option($settings::OPTION_BYPASS_COOKIES, 'custom_consent');
    check(in_array('custom_consent', (new \SymPress\NginxCache\Config\BypassRuleProvider($settings))->rules(\SymPress\NginxCache\Value\CacheProfile::Safe)['cookies'], true), 'custom consent cookie bypass');
    $customConsent = static fn (array $cookies): array => [...$cookies, 'sympress_consent'];
    add_filter('sympress_nginx_cache_bypass_cookies', $customConsent);
    check(in_array('sympress_consent', (new \SymPress\NginxCache\Config\BypassRuleProvider($settings))->rules(\SymPress\NginxCache\Value\CacheProfile::Safe)['cookies'], true), 'server-dependent consent integration can explicitly opt in to bypass');
    remove_filter('sympress_nginx_cache_bypass_cookies', $customConsent);
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
    $sideClock = new \Symfony\Component\Clock\MockClock('2026-10-02');
    $clock = new \SymPress\NginxCache\Time\CacheClock($sideClock);
    $side = new \SymPress\NginxCache\Purge\PurgeSideEffectQueueRepository(testMutex(), $clock);
    $result = \SymPress\NginxCache\Value\PurgeResult::success('/tmp/disposable-cache', 0, 0.1);
    $request = \SymPress\NginxCache\Value\PurgeRequest::urls([get_permalink($published)]);
    $side->push($result, $request);
    check(!$side->process(static fn (): bool => false) && $side->count() === 1, 'failed side effect remains stored');
    $sideClock->sleep(60);
    check($side->process(static fn (): bool => true) && $side->count() === 0, 'successful side-effect retry acknowledges');
    for ($i = 0; $i < 50; ++$i) {
        $side->push($result, $request);
    }
    check($side->push($result, $request), 'side-effect overflow is coalesced');
    check($side->count() === 1 && \SymPress\NginxCache\Value\PurgeRequest::fromArray($side->all()[0]['request'])->requiresFullPurge(), 'bounded full invalidation covers every pending task');
    $side->process(static fn (): bool => true);
    $side->push($result, $request);
    $sideCalls = 0;
    foreach ([60, 120, 240, 300, 300] as $attemptIndex => $delay) {
        $before = $sideClock->now()->getTimestamp();
        $side->process(static function () use (&$sideCalls): bool { ++$sideCalls; throw new RuntimeException('fixture provider exception'); });
        $state = $side->inspect()[0];
        check($state['attempts'] === $attemptIndex + 1 && $state['retry_at'] === $before + $delay, 'side-effect exception persists capped retry ' . ($attemptIndex + 1));
        $side->process(static function () use (&$sideCalls): bool { ++$sideCalls; return true; });
        check($sideCalls === $attemptIndex + 1, 'side-effect worker respects backoff ' . ($attemptIndex + 1));
        $sideClock->sleep($delay);
    }
    $side->push($result, $request);
    $side->process(static fn (): bool => true);
    check($sideCalls === 5 && $side->count() === 1 && $side->inspect()[0]['exhausted'] && $side->nextAttemptAt() === null, 'exhausted side effects remain inspectable while newer tasks can succeed');
    $side->retry();
    check($side->process(static fn (): bool => true) && $side->count() === 0, 'explicit side-effect retry resets budget');
    update_option('sympress_nginx_cache_side_effect_queue', [
        ['result' => $result->toArray(), 'request' => $request->toArray(), 'queued_at' => 1],
        ['result' => $result->toArray(), 'request' => $request->toArray(), 'queued_at' => 1],
    ], false);
    check($side->process(static fn (): bool => true) && $side->count() === 0, 'identical legacy side-effect tasks gain distinct stable identities');
    $side->push($result, $request);
    $side->process(static function (array $task) use ($side, $result, $request): bool {
        $side->checkpoint($task['id'], 'fixture-action');
        $side->push($result, $request);
        return true;
    });
    check($side->count() === 1 && $side->inspect()[0]['completed'] === [], 'side-effect checkpoint and acknowledgement preserve concurrent producer');
    $side->process(static fn (): bool => true);
    update_option($settings::OPTION_CLOUDFLARE_ENABLED, 1);
    update_option($settings::OPTION_CLOUDFLARE_ZONE_ID, 'fixture-zone');
    $calls = 0;
    $accept = false;
    $mock = new \Symfony\Component\HttpClient\MockHttpClient(static function () use (&$calls, &$accept): \Symfony\Component\HttpClient\Response\MockResponse {
        ++$calls;
        return new \Symfony\Component\HttpClient\Response\MockResponse($accept ? '{"success":true}' : '{"success":false}', ['http_code' => 200]);
    });
    $effects = new \SymPress\NginxCache\Purge\PurgeSideEffectProcessor($settings, $side,
        new \SymPress\NginxCache\Purge\Prewarmer($mock, $settings, $policy, $clock), new \SymPress\NginxCache\Layer\CacheLayerCoordinator($settings),
        new \SymPress\NginxCache\Remote\RemotePurgeDispatcher($mock, $settings, $policy, $clock),
        new \SymPress\NginxCache\Remote\CloudflarePurgeDispatcher($mock, $settings, new \SymPress\NginxCache\Surrogate\CacheTagResolver(), $policy), $clock);
    $effects->enqueue($result, $request);
    wp_clear_scheduled_hook($effects::HOOK);
    $effects->process();
    check($side->count() === 1 && $calls === 0, 'unreadable Cloudflare token fails closed and preserves provider work');
    $scheduled = wp_next_scheduled($effects::HOOK);
    check($scheduled === $sideClock->now()->getTimestamp() + 60, 'side-effect cron retry waits 60 seconds instead of every second');
    $effects->process();
    check($side->inspect()[0]['attempts'] === 1 && $calls === 0, 'processor cannot retry before persisted due time');
    $sideClock->sleep(60);
    update_option($settings::OPTION_CLOUDFLARE_API_TOKEN, 'fixture-provider-token');
    $effects->process();
    check($side->count() === 1 && $calls === 1, 'provider rejection with HTTP 200 remains queued');
    $sideClock->sleep(120);
    $accept = true;
    $effects->process();
    check($side->count() === 0 && $calls === 2, 'mock provider acceptance acknowledges retry');
    $layerCalls = 0;
    $layerAction = static function () use (&$layerCalls): void { ++$layerCalls; };
    add_action('sympress_nginx_cache_flush_layers', $layerAction);
    update_option($settings::OPTION_LAYER_SYNC_ENABLED, 1);
    $accept = false;
    $effects->enqueue($result, $request);
    $effects->process();
    check($layerCalls === 1 && in_array('layer:wordpress-hooks', $side->inspect()[0]['completed'], true), 'successful layer work is durably checkpointed before failed provider');
    $sideClock->sleep(60);
    $accept = true;
    $effects->process();
    check($layerCalls === 1 && $side->count() === 0, 'provider retry does not duplicate a successful destructive layer action');
    remove_action('sympress_nginx_cache_flush_layers', $layerAction);
    update_option($settings::OPTION_LAYER_SYNC_ENABLED, 0);
    $accept = false;
    $effects->enqueue($result, $request);
    foreach ([60, 120, 240, 300, 300] as $delay) {
        wp_clear_scheduled_hook($effects::HOOK);
        $effects->process();
        $sideClock->sleep($delay);
    }
    check($side->inspect()[0]['exhausted'] && wp_next_scheduled($effects::HOOK) === false, 'exhausted side-effect processor stops scheduling cron and retains task');
    $sideCommand = new \Symfony\Component\Console\Tester\CommandTester(new \SymPress\NginxCache\Cli\Command\SideEffectsCommand($effects));
    $sideCommand->execute(['action' => 'status']);
    check(str_contains($sideCommand->getDisplay(), 'Exhausted side-effect tasks: 1'), 'side-effect CLI status exposes exhausted work');
    $sideCommand->execute(['action' => 'details']);
    $details = json_decode($sideCommand->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    check($details[0]['attempts'] === 5 && $details[0]['exhausted'], 'side-effect CLI details exposes attempts and exhaustion');
    check($sideCommand->execute(['action' => 'flush']) === 1, 'side-effect CLI flush reports retained exhausted work');
    $sideCommand->execute(['action' => 'retry']);
    check($side->inspect()[0]['attempts'] === 0 && wp_next_scheduled($effects::HOOK) !== false, 'side-effect operator retry resets budget and schedules without calling providers');
    $accept = true;
    $effects->process();
    update_option($settings::OPTION_CLOUDFLARE_ENABLED, 0);
    // Public-IP fixtures avoid DNS; MockHttpClient prevents any network call.
    $firstRemote = 'https://1.1.1.1/fixture-first';
    $secondRemote = 'https://1.1.1.1/fixture-second';
    update_option($settings::OPTION_REMOTE_ENDPOINTS, $firstRemote . "\n" . $secondRemote);
    update_option($settings::OPTION_REMOTE_SECRET, 'fixture-remote-signing-secret');
    $remoteCalls = [];
    $acceptRemote = false;
    $remoteMock = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url) use (&$remoteCalls, &$acceptRemote, $firstRemote): \Symfony\Component\HttpClient\Response\MockResponse {
        $remoteCalls[$url] = ($remoteCalls[$url] ?? 0) + 1;
        return new \Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => $url === $firstRemote || $acceptRemote ? 204 : 500]);
    });
    $remoteEffects = new \SymPress\NginxCache\Purge\PurgeSideEffectProcessor($settings, $side,
        new \SymPress\NginxCache\Purge\Prewarmer($remoteMock, $settings, $policy, $clock), new \SymPress\NginxCache\Layer\CacheLayerCoordinator($settings),
        new \SymPress\NginxCache\Remote\RemotePurgeDispatcher($remoteMock, $settings, $policy, $clock),
        new \SymPress\NginxCache\Remote\CloudflarePurgeDispatcher($remoteMock, $settings, new \SymPress\NginxCache\Surrogate\CacheTagResolver(), $policy), $clock);
    $remoteEffects->enqueue($result, $request);
    $side->drain();
    $lockScope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':sympress_nginx_cache_side_effect_queue';
    $sideLock = 'sympress-cache:' . substr(hash('sha256', $lockScope), 0, 40);
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$sideLock]);
    check($remoteEffects->enqueue($result, $request) !== [], 'external enqueue contention durably retains selective follow-up work');
    check($remoteEffects->attentionReason() !== 'storage-error', 'ordinary contention is not an external storage failure');
    $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $statement->execute([$sideLock]);
    check(!\SymPress\NginxCache\Value\PurgeRequest::fromArray($side->all()[0]['request'])->requiresFullPurge(), 'contended selective work keeps its scope after the lock is released');
    $side->drain();
    delete_option($remoteEffects::HEALTH_OPTION);
    $remoteEffects->enqueue($result, $request);
    $remoteEffects->process();
    check(($remoteCalls[$firstRemote] ?? 0) === 1 && ($remoteCalls[$secondRemote] ?? 0) === 1 && $side->count() === 1, 'mixed remote fixture success and failure retains task');
    check(in_array('remote:' . $firstRemote, $side->inspect()[0]['completed'], true), 'successful remote endpoint is durably checkpointed');
    $sideClock->sleep(60);
    $acceptRemote = true;
    $remoteEffects->process();
    check($remoteCalls[$firstRemote] === 1 && $remoteCalls[$secondRemote] === 2 && $side->count() === 0, 'retry skips successful remote endpoint and only repeats the failed endpoint');
    update_option($settings::OPTION_REMOTE_ENDPOINTS, '');
    delete_option($settings::OPTION_REMOTE_SECRET);
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
    $processor = new \SymPress\NginxCache\Purge\PurgeQueueProcessor($settings, $queue, $manager, new \SymPress\NginxCache\Time\CacheClock($queueClock));
    $cacheRoot = sys_get_temp_dir() . '/sympress-nginx-followup-' . bin2hex(random_bytes(8));
    mkdir($cacheRoot, 0700);
    touch($cacheRoot . '/' . \SymPress\NginxCache\Filesystem\CachePathValidator::SENTINEL_FILE);
    file_put_contents($cacheRoot . '/old-cache-entry', 'fixture');
    $fixturePath = static fn (): string => $cacheRoot;
    add_filter('sympress_nginx_cache_path', $fixturePath);
    update_option($settings::OPTION_PREWARM_ENABLED, 1);
    $side->drain();
    for ($i = 0; $i < 50; ++$i) { $side->push($result, $request); }
    $localResult = $manager->purgeConfiguredPath(\SymPress\NginxCache\Value\PurgeRequest::full());
    check($localResult->successful && !is_file($cacheRoot . '/old-cache-entry'), 'saturated external queue cannot stop local purges');
    check($side->count() === 1, 'local success coalesces saturated follow-up queue');
    remove_filter('sympress_nginx_cache_path', $fixturePath);
    update_option($settings::OPTION_PREWARM_ENABLED, 0);
    $side->drain();
    (new \Symfony\Component\Filesystem\Filesystem())->remove($cacheRoot);
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
    foreach ([60, 120, 240, 300, 300] as $attemptIndex => $delay) {
        wp_clear_scheduled_hook($processor::HOOK);
        $processor->process();
        check($queue->inspect()[0]['attempts'] === $attemptIndex + 1, 'purge processor reserves failed attempt ' . ($attemptIndex + 1));
        if ($attemptIndex < 4) {
            check(wp_next_scheduled($processor::HOOK) === $queueClock->now()->getTimestamp() + $delay, 'purge processor schedules capped backoff ' . ($attemptIndex + 1));
        }
        $queueClock->sleep($delay);
    }
    check($queue->inspect()[0]['exhausted'] && wp_next_scheduled($processor::HOOK) === false, 'purge processor stops scheduling exhausted work');
    for ($event = 0; $event < 2000; ++$event) {
        $queue->push(\SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/exhausted/' . $event . '/']));
    }
    $inboxRows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('sympress_nginx_cache_queue_inbox_') . '%'));
    check($inboxRows <= 68, '2000 new invalidations retain at most 68 producer inbox slots');
    $wpdb->queries = [];
    check($queue->nextAttemptAt() === null, 'new work preserves the exhausted retry budget');
    $inboxQueries = array_filter($wpdb->queries, static fn (array $query): bool => str_contains($query[0], 'option_name LIKE'));
    check($inboxQueries === [], 'exhausted scheduling does not load the inbox');
    check($queue->all()[0]->requiresFullPurge(), 'bounded inbox overflow covers every invalidation with a full purge');
    $inboxQueries = array_filter($wpdb->queries, static fn (array $query): bool => str_contains($query[0], 'option_name LIKE'));
    check($inboxQueries !== [] && array_all($inboxQueries, static fn (array $query): bool => str_contains($query[0], 'LIMIT 68')), 'all inbox reads have a fixed batch limit');
    $queueCommand = new \Symfony\Component\Console\Tester\CommandTester(new \SymPress\NginxCache\Cli\Command\QueueCommand($processor));
    $queueCommand->execute(['action' => 'status']);
    check(str_contains($queueCommand->getDisplay(), 'Exhausted purge requests: 1'), 'purge CLI status exposes exhausted work');
    $queueCommand->execute(['action' => 'details']);
    $details = json_decode($queueCommand->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    check($details[0]['attempts'] === 5 && $details[0]['exhausted'], 'purge CLI details exposes retained requests and retry state');
    check($queueCommand->execute(['action' => 'flush']) === 1, 'purge CLI flush reports retained exhausted work');
    $queueCommand->execute(['action' => 'retry']);
    check($queue->inspect()[0]['attempts'] === 0 && wp_next_scheduled($processor::HOOK) !== false, 'purge operator retry schedules preserved work without filesystem execution');
    remove_filter('sympress_nginx_cache_path', $invalidRoot);
    require __DIR__ . '/compatibility.php';
    // Default retention and explicit data removal, without filesystem operations.
    $index->remember('https://example.test/retention-fixture/', ['retained']);
    \SymPress\NginxCache\Support\UninstallPolicy::removeCurrentSiteData();
    check($index->stats()['urls'] > 0 && $queue->count() === 1, 'default uninstall retains index and queue');
    update_option($settings::OPTION_DELETE_ON_UNINSTALL, 1);
    \SymPress\NginxCache\Support\UninstallPolicy::removeCurrentSiteData();
    check(get_option($settings::OPTION_AUTO_PURGE, null) === null && get_option($index::OPTION_VERSION, null) === null, 'explicit uninstall removes owned settings');
    check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $index::TABLE_SUFFIX)) === null, 'explicit uninstall drops only owned index table');
    echo 'Integration complete: ' . $checks . ' assertions; real WordPress ' . $GLOBALS['wp_version'] . '/' . $pdo->query('SELECT VERSION()')->fetchColumn() . '; zero external HTTP.' . PHP_EOL;
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
