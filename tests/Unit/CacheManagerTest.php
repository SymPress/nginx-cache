<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\CachePurger;
use SymPress\NginxCache\Purge\FullPurgeEndpointDispatcher;
use SymPress\NginxCache\Purge\Prewarmer;
use SymPress\NginxCache\Purge\PurgeEventEmitter;
use SymPress\NginxCache\Purge\PurgeHistoryRepository;
use SymPress\NginxCache\Purge\PurgeSideEffectProcessor;
use SymPress\NginxCache\Purge\PurgeSideEffectQueueRepository;
use SymPress\NginxCache\Remote\CloudflarePurgeDispatcher;
use SymPress\NginxCache\Remote\RemotePurgeDispatcher;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Support\WordPressOptionLockStore;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Surrogate\TagIndexRepository;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;

final class CacheManagerTest extends TestCase
{
    public function testDryRunOrchestrationDoesNotDeleteOrDispatchSideEffects(): void
    {
        $filesystem = new Filesystem();
        $path = sys_get_temp_dir() . '/sympress-nginx-cache-manager-' . bin2hex(random_bytes(8));
        $cachedFile = $path . '/cache/' . str_repeat('a', 32);
        $filesystem->mkdir(dirname($cachedFile));
        file_put_contents($cachedFile, 'cached');
        $httpRequests = 0;
        $http = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$httpRequests): MockResponse {
                ++$httpRequests;

                return new MockResponse('', ['http_code' => 204]);
            },
        );
        $clock = new CacheClock(new MockClock('2026-07-15 12:00:00'));
        $urls = new UrlPolicy();
        $settings = new WordPressCacheSettings($path, $urls);
        $tags = new CacheTagResolver();
        $sideEffects = new PurgeSideEffectProcessor(
            $settings,
            new PurgeSideEffectQueueRepository(
                new OptionMutex(new LockFactory(new WordPressOptionLockStore($clock))),
                $clock,
            ),
            new Prewarmer($http, $settings, $urls, $clock),
            new CacheLayerCoordinator($settings),
            new RemotePurgeDispatcher($http, $settings, $urls, $clock),
            new CloudflarePurgeDispatcher($http, $settings, $tags, $urls),
            $clock,
        );
        $manager = new CacheManager(
            $settings,
            new CachePurger(
                $filesystem,
                new CachePathValidator($filesystem),
                new CacheFileResolver($settings, new CacheKeyStrategy()),
                new FullPurgeEndpointDispatcher($http, $settings, $urls, $clock),
                $clock,
            ),
            new PurgeHistoryRepository($clock),
            new PurgeEventEmitter(),
            new TagIndexRepository($tags, $urls, $clock),
            $sideEffects,
        );

        try {
            $result = $manager->purgeConfiguredPath(PurgeRequest::full('smoke', 'test', true, true));

            self::assertTrue($result->successful);
            self::assertTrue($result->dryRun);
            self::assertFileExists($cachedFile);
            self::assertSame([], $result->sideEffects);
            self::assertSame(0, $sideEffects->count());
            self::assertSame(0, $httpRequests);
        } finally {
            $filesystem->remove($path);
        }
    }
}
