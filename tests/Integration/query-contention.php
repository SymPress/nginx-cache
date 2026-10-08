<?php

declare(strict_types=1);

use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Purge\Prewarmer;
use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use SymPress\NginxCache\Purge\PurgeSideEffectQueueRepository;
use SymPress\NginxCache\Remote\CloudflarePurgeDispatcher;
use SymPress\NginxCache\Remote\RemotePurgeDispatcher;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use SymPress\NginxCache\Value\PurgeResult;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$schema = getenv('NGINX_TEST_DB_NAME');
if (!is_string($schema) || !preg_match('/^sympress_review_nginx[a-z0-9_]*$/', $schema)) {
    throw new RuntimeException('A fresh disposable sympress_review_nginx schema is mandatory.');
}
[$server, $port] = array_pad(explode(':', getenv('NGINX_TEST_DB_HOST') ?: '127.0.0.1:33079', 2), 2, '3306');
$pdo = new PDO('mysql:host=' . $server . ';port=' . $port, getenv('NGINX_TEST_DB_USER') ?: 'root', getenv('NGINX_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `' . $schema . '`');
$checks = 0;
$failures = [];
$verify = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
};

try {
    require __DIR__ . '/bootstrap.php';
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install('Disposable query/contention regression', 'fixture-admin', 'fixture@example.test', false, '', bin2hex(random_bytes(24)));
    wp_installing(false);
    $index = testIndex();
    $index->install();
    $index->remember('https://example.test/', ['homepage']);
    $wpdb->queries = [];
    foreach (['s=foo', 'p=123', 's=foo&utm_source=ad', 'unknown=1'] as $query) {
        for ($i = 0; $i < 60; ++$i) {
            $index->remember('https://example.test/?' . $query . '&variant=' . $i, ['bypassed']);
        }
    }
    $verify($wpdb->queries === [], '240 bypassed query registrations issue zero DB calls');
    $verify($index->urlsForTags(['bypassed']) === [], 'uncached query variants consume no tag-index capacity');
    $verify($index->urlsForTags(['homepage']) === ['https://example.test/'], 'bypassed queries preserve homepage identity');

    define('SYMPRESS_NGINX_CACHE_ENCRYPTION_KEY', str_repeat('fixture-only-', 8));
    $settings = new WordPressCacheSettings('/fixture-owned-cache', new UrlPolicy());
    $settings->register();
    update_option($settings::OPTION_CLOUDFLARE_ENABLED, 1);
    update_option($settings::OPTION_CLOUDFLARE_ZONE_ID, 'fixture-zone');
    update_option($settings::OPTION_CLOUDFLARE_API_TOKEN, 'fixture-provider-token');
    $clock = new CacheClock(new MockClock('2026-10-05'));
    $side = new PurgeSideEffectQueueRepository(testMutex(), $clock);
    $payloads = [];
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$payloads): MockResponse {
        $payloads[] = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
        return new MockResponse('{"success":true}', ['http_code' => 200]);
    });
    $policy = new UrlPolicy();
    $effects = new PurgeSideEffectProcessor($settings, $side,
        new Prewarmer($http, $settings, $policy, $clock), new CacheLayerCoordinator($settings),
        new RemotePurgeDispatcher($http, $settings, $policy, $clock),
        new CloudflarePurgeDispatcher($http, $settings, new CacheTagResolver(), $policy), $clock);
    $scope = $wpdb->get_var('SELECT DATABASE()') . ':' . $wpdb->prefix . ':sympress_nginx_cache_side_effect_queue';
    $held = 'sympress-cache:' . substr(hash('sha256', $scope), 0, 40);
    $pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$held]);
    $result = PurgeResult::success('/fixture-owned-cache', 0, 0.0);
    $first = PurgeRequest::urls(['https://example.test/one/']);
    $second = PurgeRequest::urls(['https://example.test/two/']);
    $started = microtime(true);
    $planned = $effects->enqueue($result, $first);
    $effects->enqueue($result, $second);
    add_option('sympress_nginx_cache_side_effect_queue_inbox_corrupt_fixture', 'invalid retained payload', '', false);
    $verify(microtime(true) - $started < 0.5, 'contended producers remain nonblocking');
    $verify($planned !== [], 'contended follow-up work is durably accepted');
    $verify($side->count() === 2, 'two contended selective tasks survive in the inbox');
    $verify(get_option('sympress_nginx_cache_side_effect_queue_inbox_corrupt_fixture', null) === null, 'corrupt side-effect inbox record is quarantined without blocking valid tasks');
    $identities = array_column($side->all(), 'id');
    $verify($effects->attentionReason() !== 'storage-error', 'ordinary contention is not a storage failure');
    $effects->process();
    $verify($payloads === [], 'contended worker cannot dispatch unreserved work');
    $verify($effects->attentionReason() !== 'storage-error', 'contended processing does not request a full recovery purge');
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$held]);
    $effects->enqueue($result, PurgeRequest::urls(['https://example.test/three/']));
    $verify(array_diff($identities, array_column($side->all(), 'id')) === [], 'inbox ingestion preserves task identities');
    $effects->process();
    $verify(count($payloads) === 1 && $side->count() === 2, 'one tick handles only one retained provider task');
    $effects->process();
    $effects->process();
    $verify(count($payloads) === 3 && $side->count() === 0, 'all selective provider tasks execute after lock release');
    $verify(!array_filter($payloads, static fn (array $payload): bool => isset($payload['purge_everything'])), 'normal contention never invalidates the Cloudflare zone');
    $files = array_merge(...array_map(static fn (array $payload): array => $payload['files'] ?? [], $payloads));
    sort($files);
    $verify($files === ['https://example.test/one/', 'https://example.test/three/', 'https://example.test/two/'], 'provider payloads retain their original selective URLs');

    $side->push($result, $first);
    $runs = 0;
    $side->process(static function (array $task) use ($pdo, $held, $side, &$runs): bool {
        ++$runs;
        $pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$held]);
        $side->checkpoint($task['id'], 'cloudflare');
        return true;
    });
    $verify($runs === 1 && $side->inspect()[0]['attempts'] === 1, 'checkpoint and acknowledgment contention preserves a single provider attempt');
    for ($i = 0; $i < 6; ++$i) {
        try {
            $side->process(static function () use (&$runs): bool { ++$runs; return true; });
        } catch (\SymPress\NginxCache\Support\MutationLockUnavailable) {
            // The aggregate remains held; no provider work can be reserved.
        }
    }
    $verify($runs === 1 && !$side->inspect()[0]['exhausted'], 'six contended acknowledgments cannot exhaust successful work');
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$held]);
    $side->process(static function () use (&$runs): bool { ++$runs; return true; });
    $verify($side->count() === 0 && $runs === 1, 'durable success is ingested before retry without repeating the provider');

    $side->push($result, $second);
    $side->process(static function (array $task) use ($pdo, $held, $side): bool {
        $pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$held]);
        $side->checkpoint($task['id'], 'cloudflare');
        return false;
    });
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$held]);
    $side->process(static fn (): bool => false);
    $verify($side->all()[0]['completed'] === ['cloudflare'], 'partial successful provider checkpoint survives contention before another provider retry');
    $side->drain();

    for ($i = 0; $i < 64; ++$i) {
        add_option('sympress_nginx_cache_side_effect_queue_inbox_bad_batch_' . $i, 'invalid fixture', '', false);
    }
    $pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$held]);
    $side->push($result, $first);
    wp_clear_scheduled_hook(PurgeSideEffectProcessor::HOOK);
    $effects->schedule();
    $verify(wp_next_scheduled(PurgeSideEffectProcessor::HOOK) !== false, 'a valid inbox task behind 64 corrupt records remains scheduled');
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$held]);
    $side->drain();

    $side->push($result, $first);
    $id = $side->all()[0]['id'];
    $pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$held]);
    for ($i = 0; $i < 129; ++$i) {
        $side->checkpoint($id, 'layer:fixture-' . $i);
    }
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$held]);
    $seen = 0;
    $side->process(static function (array $task) use (&$seen): bool {
        $seen = count($task['completed']);
        return false;
    });
    $verify($seen === 129 && count($side->all()[0]['completed']) === 129, 'replacement returns all 129 durable checkpoints rather than a stale snapshot');
    $side->drain();

    $side->push($result, $first);
    $runs = 0;
    $side->process(static function (array $task) use ($pdo, $held, $side, &$runs): bool {
        ++$runs;
        $pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$held]);
        for ($i = 0; $i < 256; ++$i) {
            $side->checkpoint($task['id'], 'layer:fixture-' . $i);
        }
        return true;
    });
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$held]);
    $clock->sleepMicroseconds(61_000_000);
    $side->process(static function () use (&$runs): bool { ++$runs; return true; });
    $side->process(static function () use (&$runs): bool { ++$runs; return true; });
    $verify($runs === 1 && $side->count() === 0, 'acknowledgment behind 256 checkpoints fences provider replay');

    $breakStorage = static fn (string $sql): string => str_contains($sql, 'sympress_nginx_cache_side_effect_queue') && preg_match('/^(INSERT|UPDATE)\s/i', $sql) === 1 ? 'INVALID FIXTURE STORAGE SQL' : $sql;
    $quiet = $wpdb->suppress_errors(true);
    add_filter('query', $breakStorage);
    try {
        $verify($effects->enqueue($result, $first) === [], 'genuine storage failure cannot claim durable acceptance');
    } finally {
        remove_filter('query', $breakStorage);
        $wpdb->suppress_errors($quiet);
    }
    $verify($effects->attentionReason() === 'storage-error', 'genuine storage failure remains visible');
    $effects->enqueue($result, $second);
    $verify(PurgeRequest::fromArray($side->all()[0]['request'])->requiresFullPurge(), 'genuine loss of an invalidation still requires full recovery');
    $effects->process();
    $verify(($payloads[3]['purge_everything'] ?? false) === true && $side->count() === 0, 'genuine storage recovery retains its full provider purge');
} finally {
    $pdo->exec('DROP DATABASE `' . $schema . '`');
}
echo sprintf('RESULT %d checks, %d failures%s', $checks, count($failures), PHP_EOL);
exit($failures === [] ? 0 : 1);
