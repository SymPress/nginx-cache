<?php

declare(strict_types=1);

use Predis\Client;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\PredisPageCacheStore;
use SymPress\NginxCache\Purge\RedisPageCachePurger;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use Symfony\Component\Clock\NativeClock;

if (getenv('NGINX_TEST_REDIS_FIXTURE') !== '1') {
    throw new RuntimeException('Run only against an explicitly disposable Redis fixture.');
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
define('SYMPRESS_NGINX_CACHE_ENCRYPTION_KEY', 'disposable-redis-fixture-encryption-key');
function home_url(string $path = '/'): string { return 'https://example.test' . $path; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['redis_fixture_options'][$name] ?? $default; }
function checkRedis(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS ' . $message . PHP_EOL;
}

$prefix = 'nginx-fixture-' . bin2hex(random_bytes(8)) . ':';
$GLOBALS['redis_fixture_options'] = [CompatibilitySettings::PREFIX . 'redis_prefix' => $prefix];
$settings = new WordPressCacheSettings('/unused');
$credentials = getenv('NGINX_TEST_REDIS_PASSWORD') ?: '';
if ($credentials !== '') {
    $GLOBALS['redis_fixture_options'][CompatibilitySettings::REDIS_PASSWORD] = $settings->sanitizeStoredSecret($credentials, CompatibilitySettings::REDIS_PASSWORD);
}
$compatibility = new CompatibilitySettings($settings);
$connection = $compatibility->redisConnection();
checkRedis($credentials !== '' || !isset($connection['username'], $connection['password']), 'empty credentials do not trigger Redis AUTH');
$client = new Client($connection);
$keys = [$prefix . 'httpsGETexample.test/post/', $prefix . 'https|GET|example.test|/post/', $prefix . 'other-page', $prefix . 'outside-prefix:retained'];
$otherKey = 'object-fixture-' . bin2hex(random_bytes(8));
try {
    foreach ([...$keys, $otherKey] as $key) { $client->set($key, 'disposable fixture'); }
    $purger = new RedisPageCachePurger($compatibility, new PredisPageCacheStore($compatibility), new UrlPolicy(), new CacheKeyStrategy(), new CacheClock(new NativeClock()));
    checkRedis($purger->purge(PurgeRequest::full(dryRun: true))->successful && (int) $client->exists($keys[0]) === 1, 'dry run preserves real Redis entries');
    $result = $purger->purge(PurgeRequest::urls(['https://example.test/post/']));
    checkRedis($result->successful && $result->removedEntries === 2 && (int) $client->exists($keys[2]) === 1, 'selective purge deletes Helper and SymPress key layouts only');
    for ($i = 0; $i < 450; ++$i) { $client->set($prefix . 'page:' . $i, 'fixture'); }
    $result = $purger->purge(PurgeRequest::full());
    checkRedis($result->successful && $result->removedEntries === 452, 'full purge exhausts real SCAN batches for its prefix');
    checkRedis((int) $client->exists($otherKey) === 1, 'WordPress object-cache keys survive page-cache purge');
} finally {
    // Delete only this run's random fixture names, never flush the database.
    $client->del([...$keys, $otherKey, ...array_map(static fn (int $i): string => $prefix . 'page:' . $i, range(0, 449))]);
}
