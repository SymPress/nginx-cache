<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Purge\FullPurgeEndpointDispatcher;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FullPurgeEndpointDispatcherTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRemoteEndpointRequestIsPinnedToTheValidatedAddress(): void
    {
        define('SYMPRESS_NGINX_CACHE_FULL_PURGE_MODE', 'endpoint');
        define('SYMPRESS_NGINX_CACHE_FULL_PURGE_ENDPOINT', 'https://1.1.1.1/full-purge');
        define('SYMPRESS_NGINX_CACHE_REMOTE_SECRET', 'fixture-endpoint-secret');
        $resolve = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$resolve): MockResponse {
            $resolve = $options['resolve'] ?? [];

            return new MockResponse('', ['http_code' => 204]);
        });
        $clock = new CacheClock(new MockClock('2026-11-16 12:00:00'));
        $urls = new UrlPolicy();
        $dispatcher = new FullPurgeEndpointDispatcher($http, new WordPressCacheSettings('/fixture', $urls), $urls, $clock);

        self::assertTrue($dispatcher->purge(PurgeRequest::full(), $clock->highResolutionTimestamp(), $clock->timestamp())->successful);
        self::assertSame(['1.1.1.1' => '1.1.1.1'], $resolve);
    }
}
