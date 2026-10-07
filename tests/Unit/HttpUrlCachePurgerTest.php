<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Purge\HttpUrlCachePurger;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use SymPress\NginxCache\Value\PurgeRequest;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpUrlCachePurgerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['sympress_nginx_cache_test_options']);
    }

    public function testSignedHelperCompatibleRequestPreservesQueryAndDisablesRedirects(): void
    {
        $settings = new WordPressCacheSettings('/unused');
        $GLOBALS['sympress_nginx_cache_test_options'][WordPressCacheSettings::OPTION_REMOTE_SECRET] = $settings->sanitizeStoredSecret('private-test-secret', WordPressCacheSettings::OPTION_REMOTE_SECRET);
        $calls = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/purge/post/?page=2', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertStringContainsString('X-SymPress-Signature: sha256=', implode('\n', $options['headers']));
            return new MockResponse('', ['http_code' => 204]);
        });
        $purger = new HttpUrlCachePurger($http, new CompatibilitySettings($settings), $settings, new UrlPolicy(), new CacheClock(new MockClock()));
        $request = PurgeRequest::urls(['https://example.test/post/?page=2']);
        self::assertTrue($purger->purge($request->asDryRun())->successful);
        self::assertSame(0, $calls);
        self::assertTrue($purger->purge($request)->successful);
        self::assertSame(1, $calls);
        self::assertFalse($purger->purge(PurgeRequest::urls(['https://foreign.test/post/']))->successful);
        self::assertSame(1, $calls);
    }
}
