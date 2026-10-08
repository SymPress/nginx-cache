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

$settings = new WordPressCacheSettings('/tmp/disposable-cache');
$settings->register();
check(sanitize_option('sympress_nginx_cache_valid_seconds', '1800') === 1800, 'cache policy registers numeric settings');
check(sanitize_option('sympress_nginx_cache_valid_seconds', '900; injected') === 600, 'cache policy sanitization rejects invalid directive text');
update_option($settings::OPTION_PREWARM_ENABLED, 1);
update_option($settings::OPTION_CLOUDFLARE_ENABLED, 0);
update_option($settings::OPTION_LAYER_SYNC_ENABLED, 0);
update_option($settings::OPTION_REMOTE_ENDPOINTS, '');
$mockClock = new MockClock('2026-10-08');
$clock = new CacheClock($mockClock);
$side = new PurgeSideEffectQueueRepository(testMutex(), $clock);
$side->drain();
$calls = [];
$failing = true;
$http = new MockHttpClient(static function (string $method, string $url) use (&$calls, &$failing): MockResponse {
    $calls[] = $url;
    return new MockResponse('', ['http_code' => $failing && str_ends_with($url, '/3/') ? 503 : 200]);
});
$policy = new UrlPolicy();
$effects = new PurgeSideEffectProcessor($settings, $side, new Prewarmer($http, $settings, $policy, $clock), new CacheLayerCoordinator($settings), new RemotePurgeDispatcher($http, $settings, $policy, $clock), new CloudflarePurgeDispatcher($http, $settings, new CacheTagResolver(), $policy), $clock);
$urls = array_map(static fn (int $number): string => home_url('/batch/' . $number . '/'), range(1, 20));
$result = PurgeResult::success('/tmp/disposable-cache', 0, 0.0, requestedUrls: $urls);
$effects->enqueue($result, PurgeRequest::urls($urls, prewarm: true));
$effects->process();
check(count($calls) === 5 && $side->count() === 1, 'one side-effect tick warms at most five URLs');
check($side->inspect()[0]['attempts'] === 1, 'HTTP 503 consumes one retry reservation');
$effects->process();
check(count($calls) === 5, 'early ticks respect failed batch backoff');
$failing = false;
$mockClock->sleep(60);
$effects->process();
check(count($calls) === 10 && $side->inspect()[0]['attempts'] === 1, 'successful continuation preserves the prior failure budget');
check(count(array_filter($calls, static fn (string $url): bool => str_ends_with($url, '/3/'))) === 2, 'failed URL is retried');
for ($batch = 0; $batch < 3; ++$batch) {
    $mockClock->sleep(2);
    $effects->process();
}
check($side->count() === 0 && count($calls) === 21, 'all URLs eventually complete with no successful URL replay');
check(count(array_unique($calls)) === 20, 'batching retains the complete target plan');

// More successful batches than the five-failure budget must still complete.
$many = array_map(static fn (int $number): string => home_url('/many/' . $number . '/'), range(1, 40));
add_filter('sympress_nginx_cache_prewarm_limit', $largeLimit = static fn (): int => 40);
$effects->enqueue(PurgeResult::success('/tmp/disposable-cache', 0, 0.0, requestedUrls: $many), PurgeRequest::urls($many, prewarm: true));
for ($batch = 0; $batch < 8; ++$batch) {
    $mockClock->sleep(2);
    $effects->process();
}
check($side->count() === 0 && count($calls) === 61, 'eight successful batches do not exhaust five automatic failure attempts');
remove_filter('sympress_nginx_cache_prewarm_limit', $largeLimit);
update_option($settings::OPTION_PREWARM_ENABLED, 0);
wp_clear_scheduled_hook($effects::HOOK);
