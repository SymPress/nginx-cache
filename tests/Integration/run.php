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
    require __DIR__ . '/simple-checks.php';
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
    $wpdb->query("ALTER TABLE {$wpdb->prefix}sympress_cache_tags DROP PRIMARY KEY, DROP COLUMN id, DROP INDEX tag_url, ADD PRIMARY KEY (tag,url_hash)");
    update_option($index::OPTION_VERSION, '1', false);
    $index->install();
    check(get_option($index::OPTION_VERSION) === '3' && $index->urlsForTags(['legacy']) === ['https://example.test/legacy/'], 'legacy composite-key SQL index upgrades without losing URL mappings');
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
    $maintenance = new \SymPress\NginxCache\Surrogate\TagIndexMaintenance(new \SymPress\NginxCache\Settings\TagIndexSettings(), new \SymPress\NginxCache\Time\CacheClock(new \Symfony\Component\Clock\NativeClock()), testMutex());
    $index->remember('https://example.test/global-old/', ['global-old']);
    $index->remember('https://example.test/global-recent/old/', ['global-recent']);
    $index->remember('https://example.test/global-recent/new/', ['global-recent']);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sympress_cache_tags SET touched=%d WHERE tag='global-old' OR url='https://example.test/global-recent/old/'", time() - 200));
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sympress_cache_tags SET touched=%d WHERE url='https://example.test/global-recent/new/'", time() + 10));
    check($index->stats()['tags'] > 1000, 'inserts defer global index pruning to maintenance');
    $index->remember('https://example.test/rollback-expired/', ['rollback-expired']);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sympress_cache_tags SET touched=%d WHERE tag='rollback-expired'", time() - 604801));
    $cursorBefore = get_option($maintenance::CURSOR, '');
    wp_clear_scheduled_hook($maintenance::HOOK);
    $fault = static function (string $query): string {
        if (str_starts_with($query, 'DELETE FROM') && str_contains($query, 'sympress_cache_tags') && str_contains($query, 'WHERE tag IN')) {
            return 'DELETE FROM `' . $GLOBALS['wpdb']->prefix . 'sympress_cache_tags` WHERE fixture_missing_column=1';
        }
        return $query;
    };
    $previousErrors = $wpdb->suppress_errors(true);
    add_filter('query', $fault);
    $rolledBack = false;
    try { $maintenance->prune(); } catch (RuntimeException) { $rolledBack = true; }
    finally { remove_filter('query', $fault); $wpdb->suppress_errors($previousErrors); }
    check($rolledBack && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sympress_cache_tags WHERE tag='rollback-expired'") === 1, 'failed global maintenance rolls back preceding TTL deletion');
    check(get_option($maintenance::CURSOR, '') === $cursorBefore && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sympress_cache_tags WHERE tag='bounded'") === 55, 'failed maintenance restores retention rows and cached cursor');
    check(wp_next_scheduled($maintenance::HOOK) !== false, 'failed maintenance reschedules a consumed cron event');
    for ($tick = 0; $tick < 200 && $index->stats()['tags'] > 1000; ++$tick) {
        check($maintenance->prune() <= 500, 'maintenance tick deletes at most 500 rows');
    }
    check($index->stats()['tags'] <= 1000, 'deferred global tag retention remains bounded');
    check($index->urlsForTags(['global-old']) === [], 'global retention removes the least recently touched tag');
    check(count($index->urlsForTags(['global-recent'])) === 2, 'global retention ranks a tag by its newest URL timestamp');
    $index->remember('https://example.test/ttl-fixture/', ['ttl-fixture']);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}sympress_cache_tags SET touched=%d WHERE tag='ttl-fixture'", time() - 604801));
    $maintenance->prune();
    check($index->urlsForTags(['ttl-fixture']) === [], 'TTL maintenance removes expired URL mappings');

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
    $failure = $queue->inspect()[0]['last_failure'] ?? null;
    check(is_array($failure) && $failure['failed_at'] === $queueClock->now()->getTimestamp() && $failure['phase'] === 'purge', 'failed callback retains its time and processing phase');
    $queueClock->sleep(60);
    $fixtureException = new RuntimeException('fixture-private-password https://user:secret@example.test/?token=private', 37);
    try {
        $queue->process(static function () use ($fixtureException): bool { throw $fixtureException; });
    } catch (RuntimeException) {
    }
    check($queue->count() === 1, 'exception leaves purge queued');
    check($queue->inspect()[0]['attempts'] === 2, 'purge exception consumes a persisted retry attempt');
    $failure = $queue->inspect()[0]['last_failure'];
    check($failure['exception'] === ['type' => RuntimeException::class, 'code' => 37, 'file' => basename($fixtureException->getFile()), 'line' => $fixtureException->getLine()], 'exception diagnostics identify the throwing code');
    check(!str_contains(serialize($failure), 'fixture-private-password') && !str_contains(serialize($failure), 'https://') && !str_contains(serialize($failure), dirname(__DIR__)), 'exception diagnostics omit raw messages, URLs, stack traces and absolute paths');
    $queueClock->sleep(120);
    $queue->process(static function () use ($queue): bool {
        $queue->push(\SymPress\NginxCache\Value\PurgeRequest::urls(['https://example.test/arrived-during-purge/']));
        return true;
    });
    check(in_array('https://example.test/arrived-during-purge/', $queue->all()[0]->urls, true), 'producer during acknowledgement is retained');
    $queueClock->sleep(240);
    check($queue->process(static fn (): bool => true) && $queue->count() === 0, 'successful retry acknowledges work');
    check($queue->lastFailure() === null, 'drained queue clears its previous failure');
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
    $failure = $queue->inspect()[0]['last_failure'];
    $queue->retry();
    check($queue->inspect()[0]['last_failure'] === $failure, 'operator retry preserves failure evidence until successful recovery');
    check($queue->process(static fn (): bool => true) && $queue->count() === 0, 'explicit purge retry resets exhaustion and acknowledges retained work');
    $queue->push($identical);
    $failedResult = \SymPress\NginxCache\Value\PurgeResult::failure('/tmp/disposable-cache', 'Could not acquire cache purge lock.');
    check(!$queue->process(static fn (): \SymPress\NginxCache\Value\PurgeResult => $failedResult), 'failed purge results retain pending work');
    check($queue->inspect()[0]['last_failure']['message'] === $failedResult->message, 'purge result diagnostics retain the existing safe failure explanation');
    check($wpdb->get_var($wpdb->prepare('SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, 'sympress_nginx_cache_queue_failure')) === 'off', 'failure evidence is not autoloaded on public requests');
    $queue->retry();
    check($queue->process(static fn (): \SymPress\NginxCache\Value\PurgeResult => \SymPress\NginxCache\Value\PurgeResult::success('/tmp/disposable-cache', 0, 0.0)) && $queue->lastFailure() === null, 'successful purge results acknowledge work and clear failure evidence');
    $queue->push($identical);
    $denyAcknowledgement = static fn (mixed $value, mixed $old): mixed => $old;
    try {
        $acknowledged = $queue->process(static function () use ($denyAcknowledgement): bool {
            add_filter('pre_update_option_sympress_nginx_cache_queue', $denyAcknowledgement, 10, 2);
            return true;
        });
    } finally {
        remove_filter('pre_update_option_sympress_nginx_cache_queue', $denyAcknowledgement);
    }
    check(!$acknowledged && $queue->count() === 1 && $queue->lastFailure()['phase'] === 'acknowledge', 'successful purge with failed acknowledgement retains work and identifies the acknowledgement failure');
    $queue->retry();
    $queue->process(static fn (): bool => true);
    $queue->push($identical);
    $diagnosticLog = tempnam(sys_get_temp_dir(), 'sympress-diagnostic-');
    $previousLog = ini_get('error_log');
    $denyDiagnostics = static function (): never { throw new RuntimeException('fixture-private-diagnostic-storage'); };
    ini_set('error_log', $diagnosticLog);
    add_filter('pre_update_option_sympress_nginx_cache_queue_failure', $denyDiagnostics);
    try {
        $processed = $queue->process(static function () use ($fixtureException): bool { throw $fixtureException; });
        $signal = file_get_contents($diagnosticLog);
    } finally {
        remove_filter('pre_update_option_sympress_nginx_cache_queue_failure', $denyDiagnostics);
        ini_set('error_log', $previousLog);
        unlink($diagnosticLog);
    }
    check(!$processed && $queue->count() === 1 && $queue->inspect()[0]['attempts'] === 1, 'diagnostic storage exception cannot drop work or change its retry reservation');
    check(str_contains($signal, 'queue failure') && str_contains($signal, 'RuntimeException') && !str_contains($signal, 'fixture-private') && !str_contains($signal, 'https://'), 'credential-free log evidence survives unavailable diagnostic storage');
    $queue->retry();
    $queue->process(static fn (): bool => true);
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
    // Issue #28: a stored plan with outdated targets must not block valid targets.
    $side->drain();
    delete_option($remoteEffects::HEALTH_OPTION);
    delete_option(\SymPress\NginxCache\Purge\PurgeSideEffectProcessor::PREWARM_ISSUES_OPTION);
    update_option($settings::OPTION_PREWARM_ENABLED, 1);
    $prewarmCalls = [];
    $flakyCalls = 0;
    $truncatedCalls = 0;
    $prewarmMock = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url) use (&$prewarmCalls, &$flakyCalls, &$truncatedCalls): \Symfony\Component\HttpClient\Response\MockResponse {
        $prewarmCalls[] = $url;
        if ((string) wp_parse_url($url, PHP_URL_PATH) === '/truncated/' && ++$truncatedCalls === 1) {
            // Issue #40: HTTP 200 headers, then the body transfer breaks.
            return new \Symfony\Component\HttpClient\Response\MockResponse(['<html>', new \Symfony\Component\HttpClient\Exception\TransportException('stream was not closed cleanly')], ['http_code' => 200]);
        }
        [$status, $location] = match ((string) wp_parse_url($url, PHP_URL_PATH)) {
            '/stale-external/' => [301, 'https://foreign.example/stale-external/'],
            '/stale-loop-a/' => [302, '/stale-loop-b/'],
            '/stale-loop-b/' => [302, '/stale-loop-a/'],
            '/stale-removed/' => [404, null],
            '/stale-gone/' => [410, null],
            '/stale-moved/' => [301, '/current-moved/'],
            '/flaky/' => [++$flakyCalls === 1 ? 503 : 200, null],
            default => [200, null],
        };
        return new \Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => $status, 'response_headers' => $location === null ? [] : ['Location: ' . $location]]);
    });
    $prewarmEffects = new \SymPress\NginxCache\Purge\PurgeSideEffectProcessor($settings, $side,
        new \SymPress\NginxCache\Purge\Prewarmer($prewarmMock, $settings, $policy, $clock), new \SymPress\NginxCache\Layer\CacheLayerCoordinator($settings),
        new \SymPress\NginxCache\Remote\RemotePurgeDispatcher($prewarmMock, $settings, $policy, $clock),
        new \SymPress\NginxCache\Remote\CloudflarePurgeDispatcher($prewarmMock, $settings, new \SymPress\NginxCache\Surrogate\CacheTagResolver(), $policy), $clock);
    $stalePlan = array_map(static fn (string $path): string => home_url($path), ['/stale-external/', '/stale-loop-a/', '/stale-removed/', '/stale-gone/', '/stale-moved/', '/flaky/', '/valid-a/', '/valid-b/', '/truncated/']);
    $side->push($result, $request);
    $side->checkpoint($side->all()[0]['id'], 'prewarm-plan:' . wp_json_encode($stalePlan));
    $prewarmEffects->process();
    $state = $side->inspect()[0];
    $issueUrls = array_column(\SymPress\NginxCache\Purge\PurgeSideEffectProcessor::prewarmIssues(), 'url');
    check($state['attempts'] === 0 && count(array_filter($state['completed'], static fn (string $item): bool => str_starts_with($item, 'prewarm-url:'))) === 5, 'outdated prewarm targets settle without spending the retry budget');
    check($issueUrls === array_slice($stalePlan, 0, 4) && !in_array('https://foreign.example/stale-external/', $prewarmCalls, true) && in_array(home_url('/current-moved/'), $prewarmCalls, true), 'stale targets are reported and only same-origin redirects are followed');
    $sideClock->sleep(2);
    $prewarmEffects->process();
    $state = $side->inspect()[0];
    $flakyIssue = array_values(array_filter(\SymPress\NginxCache\Purge\PurgeSideEffectProcessor::prewarmIssues(), static fn (array $issue): bool => $issue['url'] === home_url('/flaky/')));
    check($state['attempts'] === 1 && !$state['exhausted'] && in_array(home_url('/valid-b/'), $prewarmCalls, true), 'valid targets behind stale ones are warmed while a transient failure keeps bounded retry');
    check(count($flakyIssue) === 1 && $flakyIssue[0]['stale'] === false && $flakyIssue[0]['reason'] === 'HTTP 503.' && $flakyIssue[0]['at'] > 0 && $flakyIssue[0]['at'] <= $clock->timestamp(), 'transient prewarm failure records URL, safe reason and time');
    $truncatedIssue = array_values(array_filter(\SymPress\NginxCache\Purge\PurgeSideEffectProcessor::prewarmIssues(), static fn (array $issue): bool => $issue['url'] === home_url('/truncated/')));
    check(!in_array('prewarm-url:' . hash('sha256', home_url('/truncated/')), $state['completed'], true) && in_array('prewarm-url:' . hash('sha256', home_url('/valid-b/')), $state['completed'], true), 'an HTTP 200 with a broken body transfer is not checkpointed while complete siblings keep their progress');
    check(count($truncatedIssue) === 1 && $truncatedIssue[0]['stale'] === false && $truncatedIssue[0]['reason'] === 'HTTP 200 response transfer was incomplete.', 'a broken body transfer is reported as a retryable URL failure');
    $sideCommand = new \Symfony\Component\Console\Tester\CommandTester(new \SymPress\NginxCache\Cli\Command\SideEffectsCommand($prewarmEffects));
    $sideCommand->execute(['action' => 'details']);
    $details = json_decode($sideCommand->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    check(count($details[0]['prewarm_issues']) === 6 && !str_contains($sideCommand->getDisplay(), 'foreign.example'), 'side-effect details expose prewarm issues without redirect targets');
    $warmedValid = count(array_keys($prewarmCalls, home_url('/valid-a/'), true));
    $sideClock->sleep(60);
    $prewarmEffects->process();
    $remainingIssues = array_column(\SymPress\NginxCache\Purge\PurgeSideEffectProcessor::prewarmIssues(), 'url');
    check($side->count() === 0 && $flakyCalls === 2 && $truncatedCalls === 2 && count(array_keys($prewarmCalls, home_url('/valid-a/'), true)) === $warmedValid, 'retry warms only the transient targets and completes the task');
    check(!in_array(home_url('/flaky/'), $remainingIssues, true) && !in_array(home_url('/truncated/'), $remainingIssues, true) && count($remainingIssues) === 4 && $remoteEffects->attentionReason() === '', 'recovered targets clear their issues while stale reports remain');
    // Issue #41: failed sitemap discovery must not keep already known targets cold.
    $side->drain();
    $sitemapOption = \SymPress\NginxCache\Settings\CompatibilitySettings::PREFIX . 'prewarm_sitemap';
    update_option($sitemapOption, 1);
    update_option($settings::OPTION_PREWARM_URLS, implode("\n", array_map(static fn (int $page): string => home_url('/known-' . $page . '/'), range(1, 6))));
    $discoveryCalls = [];
    $sitemapCalls = 0;
    $failingKnownCalls = 0;
    $discoveryMock = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url) use (&$discoveryCalls, &$sitemapCalls, &$failingKnownCalls): \Symfony\Component\HttpClient\Response\MockResponse {
        if ((string) wp_parse_url($url, PHP_URL_PATH) === '/wp-sitemap.xml') {
            return ++$sitemapCalls <= 2
                ? new \Symfony\Component\HttpClient\Response\MockResponse('unavailable', ['http_code' => 503])
                : new \Symfony\Component\HttpClient\Response\MockResponse('<urlset><url><loc>' . home_url('/known-1/') . '</loc></url><url><loc>' . home_url('/discovered-a/') . '</loc></url><url><loc>' . home_url('/discovered-b/') . '</loc></url></urlset>');
        }
        $discoveryCalls[] = $url;
        // A known target that keeps failing must not postpone the next discovery attempt.
        $failing = (string) wp_parse_url($url, PHP_URL_PATH) === '/known-6/' && ++$failingKnownCalls <= 2;
        return new \Symfony\Component\HttpClient\Response\MockResponse('<html></html>', ['http_code' => $failing ? 503 : 200]);
    });
    $compatibility = new \SymPress\NginxCache\Settings\CompatibilitySettings($settings);
    $discoveryEffects = new \SymPress\NginxCache\Purge\PurgeSideEffectProcessor($settings, $side,
        new \SymPress\NginxCache\Purge\Prewarmer($discoveryMock, $settings, $policy, $clock, new \SymPress\NginxCache\Purge\SitemapUrlProvider($discoveryMock, $compatibility, $policy, $clock)),
        new \SymPress\NginxCache\Layer\CacheLayerCoordinator($settings),
        new \SymPress\NginxCache\Remote\RemotePurgeDispatcher($discoveryMock, $settings, $policy, $clock),
        new \SymPress\NginxCache\Remote\CloudflarePurgeDispatcher($discoveryMock, $settings, new \SymPress\NginxCache\Surrogate\CacheTagResolver(), $policy), $clock);
    $processed = [];
    $observeProcessed = static function (mixed $purge, mixed $purgeRequest, array $effects) use (&$processed): void { $processed[] = $effects; };
    add_action('sympress_nginx_cache_side_effects_processed', $observeProcessed, 10, 3);
    $known = [home_url('/'), ...array_map(static fn (int $page): string => home_url('/known-' . $page . '/'), range(1, 6))];
    $warmedKnown = static function () use ($known, &$discoveryCalls): int { return count(array_intersect($known, $discoveryCalls)); };
    $side->push(\SymPress\NginxCache\Value\PurgeResult::success('/tmp/disposable-cache', 0, 0.1), \SymPress\NginxCache\Value\PurgeRequest::full('discovery-fixture', 'test', prewarm: true));
    $discoveryEffects->process();
    $state = $side->inspect()[0];
    $failure = \SymPress\NginxCache\Purge\SitemapUrlProvider::lastFailure();
    check($sitemapCalls === 1 && $warmedKnown() === 5 && $state['attempts'] === 0 && !in_array('prewarm', $state['completed'], true), 'sitemap HTTP 503 still warms the first batch of known targets without spending the retry budget');
    check(count(array_filter($state['completed'], static fn (string $item): bool => str_starts_with($item, 'prewarm-known:'))) === 1 && count(array_filter($state['completed'], static fn (string $item): bool => str_starts_with($item, 'prewarm-plan:'))) === 0, 'known targets are snapshotted while the incomplete discovery is not stored as a plan');
    check(($processed[0]['prewarm']['discovery_complete'] ?? null) === false && array_key_exists('remote', $processed[0]) && array_key_exists('layers', $processed[0]), 'downstream layer and remote work still runs after failed discovery');
    check($failure !== null && $failure['errors'] === [home_url('/wp-sitemap.xml') . ': Sitemap HTTP 503.'] && $failure['failed_at'] > 0 && $failure['failed_at'] <= $clock->timestamp(),'failed discovery records the sitemap URL, HTTP status and time');
    $sideCommand = new \Symfony\Component\Console\Tester\CommandTester(new \SymPress\NginxCache\Cli\Command\SideEffectsCommand($discoveryEffects));
    $sideCommand->execute(['action' => 'details']);
    check(str_contains($sideCommand->getDisplay(), 'Sitemap HTTP 503.') && !str_contains($sideCommand->getDisplay(), 'unavailable'), 'side-effect details expose the safe discovery failure without the response body');
    $sideClock->sleep(2);
    $discoveryEffects->process();
    $state = $side->inspect()[0];
    check($sitemapCalls === 1 && $warmedKnown() === 7 && count($discoveryCalls) === 7 && $state['attempts'] === 1 && !$state['exhausted'], 'remaining known targets reuse the snapshot without repeating discovery; one failing target spends one bounded retry');
    $sideClock->sleep(61);
    $discoveryEffects->process();
    $state = $side->inspect()[0];
    check($sitemapCalls === 2 && count($discoveryCalls) === 8 && end($discoveryCalls) === home_url('/known-6/') && $state['attempts'] === 2 && $side->count() === 1, 'each retry discovers again while a failing known target is retried; settled targets are never re-requested');
    $sideClock->sleep(121);
    $discoveryEffects->process();
    check($sitemapCalls === 3 && $side->count() === 0 && count($discoveryCalls) === 11 && count(array_unique($discoveryCalls)) === 9 && in_array(home_url('/discovered-b/'), $discoveryCalls, true), 'successful discovery adds only the missing targets and completes the task');
    check(\SymPress\NginxCache\Purge\SitemapUrlProvider::lastFailure() === null && end($processed)['prewarm']['discovery_complete'] === true, 'successful discovery clears the recorded failure');
    remove_action('sympress_nginx_cache_side_effects_processed', $observeProcessed, 10);
    delete_option($sitemapOption);
    update_option($settings::OPTION_PREWARM_URLS, '');
    update_option($settings::OPTION_PREWARM_ENABLED, 0);
    delete_option(\SymPress\NginxCache\Purge\PurgeSideEffectProcessor::PREWARM_ISSUES_OPTION);
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
    // Keep-warm refresh: bounded batches, honest failure evidence and opt-in eviction; MockHttpClient only.
    $warmClock = new \Symfony\Component\Clock\MockClock('2026-10-03');
    $warmCalls = [];
    $warmHttp = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url) use (&$warmCalls): \Symfony\Component\HttpClient\Response\MockResponse {
        $warmCalls[] = $method . ' ' . $url;
        return new \Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => str_contains($url, '/broken/') ? 503 : 200]);
    });
    $warmTime = new \SymPress\NginxCache\Time\CacheClock($warmClock);
    $keepWarm = new \SymPress\NginxCache\Purge\KeepWarmRefresher($settings, new \SymPress\NginxCache\Purge\Prewarmer($warmHttp, $settings, $policy, $warmTime), $warmTime, testMutex());
    $retention = \SymPress\NginxCache\Settings\CachePolicy::RETENTION_OPTION;
    delete_option($retention);
    delete_option('sympress_nginx_cache_inactive_seconds');
    update_option($settings::OPTION_PREWARM_URLS, implode("\n", [...array_map(static fn (int $page): string => home_url('/warm-' . $page . '/'), range(1, 6)), home_url('/broken/')]));
    check(!$keepWarm->enabled(), 'keep-warm refresh stays idle while prewarm is disabled');
    update_option($settings::OPTION_PREWARM_ENABLED, 1);
    wp_clear_scheduled_hook($keepWarm::HOOK);
    check(\SymPress\NginxCache\Settings\CachePolicy::retention() === 'keep_warm' && $keepWarm->enabled(), 'prewarmed pages are kept warm by default');
    $keepWarm->schedule();
    check(wp_next_scheduled($keepWarm::HOOK) !== false, 'keep-warm refresh is scheduled for prewarm targets');
    wp_clear_scheduled_hook($keepWarm::HOOK);
    $started = $warmClock->now()->getTimestamp();
    check($keepWarm->process() === 5 && $keepWarm->inspect()['pending'] === 3 && wp_next_scheduled($keepWarm::HOOK) === $warmClock->now()->getTimestamp() + 1, 'keep-warm refresh warms one bounded batch and continues promptly');
    check(count($warmCalls) === 5 && str_starts_with($warmCalls[0], 'GET ') && $keepWarm->inspect()['last_failure'] === null, 'keep-warm uses public GET requests and reports failure only after the cycle');
    wp_clear_scheduled_hook($keepWarm::HOOK);
    $warmClock->sleep(1);
    $previousLog = ini_get('error_log');
    $warmLog = tempnam(sys_get_temp_dir(), 'sympress-keep-warm-');
    ini_set('error_log', $warmLog);
    try {
        check($keepWarm->process() === 3 && count($warmCalls) === 8, 'keep-warm completes the cycle without retrying earlier targets');
        $signal = file_get_contents($warmLog);
    } finally {
        ini_set('error_log', $previousLog);
        unlink($warmLog);
    }
    $state = $keepWarm->inspect();
    check($state['pending'] === 0 && $state['completed_at'] === $warmClock->now()->getTimestamp() && $state['last_failure']['failed'] === 1 && $state['last_failure']['targets'] === 8 && str_contains($state['last_failure']['errors'][0], '/broken/: HTTP 503'), 'keep-warm failure evidence names the failed target and keeps the others warm');
    check(str_contains($signal, 'keep-warm refresh failed (1 of 8 targets)') && !str_contains($signal, 'https://'), 'keep-warm failure emits a URL-free log signal');
    check(wp_next_scheduled($keepWarm::HOOK) === $started + \SymPress\NginxCache\Settings\CachePolicy::keepWarmInterval() && \SymPress\NginxCache\Settings\CachePolicy::keepWarmInterval() === 1800, 'next keep-warm cycle starts inside the default one-hour inactivity window');
    check(!$state['overdue'], 'a recent keep-warm cycle is not overdue');
    $warmClock->sleep(3601);
    check($keepWarm->inspect()['overdue'], 'a missed inactivity window is exposed as overdue keep-warm work');
    update_option($settings::OPTION_PREWARM_URLS, home_url('/warm-1/'));
    wp_clear_scheduled_hook($keepWarm::HOOK);
    $keepWarm->process();
    check($keepWarm->inspect()['last_failure'] === null && !$keepWarm->inspect()['overdue'], 'a successful keep-warm cycle clears previous failure evidence');
    // Issue #42: a first cycle that never completes must not be reported as healthy.
    $health = new \SymPress\NginxCache\Inspection\SiteHealth($settings, new \SymPress\NginxCache\Settings\CompatibilitySettings($settings), new \SymPress\NginxCache\Filesystem\CachePathValidator(new \Symfony\Component\Filesystem\Filesystem()), $processor, $prewarmEffects, new \SymPress\NginxCache\Purge\SiteScopeResolver(), $warmTime, $index, $keepWarm);
    $firstCycle = static fn (int $startedAt): array => ['urls' => [home_url('/warm-1/')], 'done' => [], 'started_at' => $startedAt, 'completed_at' => null, 'last_failure' => null];
    wp_clear_scheduled_hook($keepWarm::HOOK);
    update_option($keepWarm::STATE, $firstCycle($warmClock->now()->getTimestamp() - 600), false);
    check(!$keepWarm->inspect()['overdue'] && $health->check('keep_warm')['status'] === 'good', 'a first keep-warm cycle inside its start-up window stays unremarkable');
    $warmClock->sleep(7200);
    update_option(\SymPress\NginxCache\Purge\CacheWorker::HEARTBEAT, $warmClock->now()->getTimestamp());
    $state = $keepWarm->inspect();
    check($state['overdue'] && $state['completed_at'] === null && $state['last_failure'] === null && $health->check('keep_warm')['status'] === 'critical', 'a first cycle stuck past the inactivity window is overdue despite a fresh worker heartbeat');
    delete_option(\SymPress\NginxCache\Purge\CacheWorker::HEARTBEAT);
    delete_option($keepWarm::STATE);
    $keepWarm->schedule();
    check(!$keepWarm->inspect()['overdue'] && $health->check('keep_warm')['status'] === 'good', 'a first cycle that is not yet due is not overdue');
    $warmClock->sleep(3602);
    check($keepWarm->inspect()['overdue'] && $health->check('keep_warm')['status'] === 'critical', 'a first cycle whose event stayed due for a whole inactivity window is overdue');
    wp_clear_scheduled_hook($keepWarm::HOOK);
    delete_option($keepWarm::STATE);
    $crash = static function (): int { throw new \RuntimeException('keep-warm crash fixture'); };
    add_filter('sympress_nginx_cache_prewarm_limit', $crash);
    $firstAttempt = $warmClock->now()->getTimestamp();
    $keepWarm->process();
    $warmClock->sleep(1800);
    wp_clear_scheduled_hook($keepWarm::HOOK);
    $keepWarm->process();
    $state = $keepWarm->inspect();
    check($state['started_at'] === $firstAttempt && $state['last_failure'] !== null && !$state['overdue'], 'a crashing first cycle keeps its first attempt as start');
    $warmClock->sleep(1801);
    check($keepWarm->inspect()['overdue'] && $health->check('keep_warm')['status'] === 'critical', 'a first cycle that keeps crashing becomes overdue instead of staying a warning');
    remove_filter('sympress_nginx_cache_prewarm_limit', $crash);
    wp_clear_scheduled_hook($keepWarm::HOOK);
    $recoveredAt = $warmClock->now()->getTimestamp();
    $keepWarm->process();
    $state = $keepWarm->inspect();
    check($state['completed_at'] !== null && $state['completed_at'] >= $recoveredAt && !$state['overdue'] && $state['last_failure'] === null && $health->check('keep_warm')['status'] === 'good', 'a completed cycle clears the overdue finding');
    update_option($keepWarm::STATE, $firstCycle($warmClock->now()->getTimestamp() - 7200), false);
    update_option($settings::OPTION_PREWARM_ENABLED, 0);
    check(!$keepWarm->inspect()['overdue'] && str_contains($health->check('keep_warm')['description'], 'does not apply'), 'disabled keep-warm raises no overdue alarm');
    update_option($settings::OPTION_PREWARM_ENABLED, 1);
    update_option($retention, 'evict_inactive');
    wp_clear_scheduled_hook($keepWarm::HOOK);
    $calls = count($warmCalls);
    $keepWarm->schedule();
    check(!$keepWarm->enabled() && wp_next_scheduled($keepWarm::HOOK) === false, 'optional inactivity eviction does not schedule refreshes');
    check($keepWarm->process() === 0 && count($warmCalls) === $calls && get_option($keepWarm::STATE, null) === null, 'a stale keep-warm event after opting into eviction makes no request and clears its state');
    delete_option($retention);
    update_option('sympress_nginx_cache_inactive_seconds', 7200);
    check(\SymPress\NginxCache\Settings\CachePolicy::retention() === 'evict_inactive', 'upgrade keeps eviction for an explicitly changed inactivity timeout');
    update_option('sympress_nginx_cache_inactive_seconds', 3600);
    check(\SymPress\NginxCache\Settings\CachePolicy::retention() === 'keep_warm', 'upgrade adopts keep-warm when the stored timeout is the previous default');
    update_option($retention, 'evict_inactive');
    check(\SymPress\NginxCache\Settings\CachePolicy::retention() === 'evict_inactive', 'a saved retention choice wins over upgrade inference');
    delete_option($retention);
    delete_option('sympress_nginx_cache_inactive_seconds');
    update_option($settings::OPTION_PREWARM_ENABLED, 0);
    update_option($settings::OPTION_PREWARM_URLS, '');
    wp_clear_scheduled_hook($keepWarm::HOOK);
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
    $failure = $processor->lastFailure();
    check($failure['phase'] === 'purge' && $failure['message'] === $history->last()['message'], 'processor preserves the actual safe purge failure instead of reducing it to a boolean');
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
    check($details[0]['last_failure'] === $failure, 'purge CLI details exposes the retained failure diagnosis');
    check($queueCommand->execute(['action' => 'flush']) === 1, 'purge CLI flush reports retained exhausted work');
    $queueCommand->execute(['action' => 'retry']);
    check($queue->inspect()[0]['attempts'] === 0 && wp_next_scheduled($processor::HOOK) !== false, 'purge operator retry schedules preserved work without filesystem execution');
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$heldQueueKey]);
    try {
        $processor->process();
        check($processor->lastFailure()['phase'] === 'queue' && $processor->lastFailure()['exception']['type'] === \SymPress\NginxCache\Support\MutationLockUnavailable::class, 'processor records exceptions before the purge callback is reached');
        check($queue->inspect()[0]['attempts'] === 0, 'diagnosing queue lock contention cannot consume an unreserved retry');
    } finally {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$heldQueueKey]);
    }
    $failure = $processor->lastFailure();
    $queueClock->sleep(1);
    $processScope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':sympress_nginx_cache_queue.process';
    $processLock = 'sympress-cache:' . substr(hash('sha256', $processScope), 0, 40);
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$processLock]);
    try {
        check($processor->process() === 0, 'process-lock contender cannot execute a purge');
        check($processor->lastFailure() === $failure, 'contending worker cannot overwrite the owner failure outside the process lock');
    } finally {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$processLock]);
    }
    remove_filter('sympress_nginx_cache_path', $invalidRoot);
    require __DIR__ . '/compatibility.php';
    require __DIR__ . '/optimization.php';
    require __DIR__ . '/settings-checks.php';
    // Default retention and explicit data removal, without filesystem operations.
    $index->remember('https://example.test/retention-fixture/', ['retained']);
    wp_schedule_single_event(time() + 120, 'fixture_unrelated_cron');
    \SymPress\NginxCache\Support\UninstallPolicy::removeCurrentSiteData();
    check($index->stats()['urls'] > 0 && $queue->count() === 1, 'default uninstall retains index and queue');
    check(wp_next_scheduled(\SymPress\NginxCache\Surrogate\TagIndexMaintenance::HOOK) !== false, 'default uninstall retains tag-maintenance scheduling');
    update_option($settings::OPTION_DELETE_ON_UNINSTALL, 1);
    wp_schedule_single_event(time() + 120, \SymPress\NginxCache\Purge\KeepWarmRefresher::HOOK);
    \SymPress\NginxCache\Support\UninstallPolicy::removeCurrentSiteData();
    check(get_option($settings::OPTION_AUTO_PURGE, null) === null && get_option($index::OPTION_VERSION, null) === null, 'explicit uninstall removes owned settings');
    check($queue->lastFailure() === null, 'explicit uninstall removes private queue failure evidence');
    check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $index::TABLE_SUFFIX)) === null, 'explicit uninstall drops only owned index table');
    check(wp_next_scheduled(\SymPress\NginxCache\Surrogate\TagIndexMaintenance::HOOK) === false && wp_next_scheduled('fixture_unrelated_cron') !== false, 'explicit uninstall clears tag maintenance and preserves unrelated cron');
    check(wp_next_scheduled(\SymPress\NginxCache\Purge\KeepWarmRefresher::HOOK) === false, 'explicit uninstall clears keep-warm refreshes');
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
