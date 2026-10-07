<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Purge\SitemapUrlProvider;
use SymPress\NginxCache\Purge\Prewarmer;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use SymPress\NginxCache\Time\CacheClock;
use Symfony\Component\Clock\MockClock;

final class SitemapUrlProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['sympress_nginx_cache_test_options']);
    }

    public function testIndexCyclesForeignOriginsAndPageBudget(): void
    {
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $url;
            self::assertSame(0, $options['max_redirects']);
            $xml = str_ends_with($url, '/wp-sitemap.xml')
                ? '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.test/posts.xml</loc></sitemap><sitemap><loc>https://foreign.test/private.xml</loc></sitemap><sitemap><loc>https://example.test/wp-sitemap.xml</loc></sitemap></sitemapindex>'
                : '<urlset><url><loc>https://foreign.test/</loc></url><url><loc>https://example.test/a/</loc></url><url><loc>https://example.test/b/</loc></url></urlset>';
            return new MockResponse($xml);
        });
        $provider = new SitemapUrlProvider($http, new CompatibilitySettings(new WordPressCacheSettings('/unused')), new UrlPolicy());
        self::assertSame(['urls' => ['https://example.test/a/'], 'errors' => []], $provider->discover(1));
        self::assertCount(2, $calls);
    }

    public function testEntityDeclarationsAreRejected(): void
    {
        $http = new MockHttpClient(new MockResponse('<!DOCTYPE urlset [<!ENTITY leak SYSTEM "file:///etc/passwd">]><urlset><url><loc>&leak;</loc></url></urlset>'));
        $provider = new SitemapUrlProvider($http, new CompatibilitySettings(new WordPressCacheSettings('/unused')), new UrlPolicy());
        $result = $provider->discover(20);
        self::assertSame([], $result['urls']);
        self::assertNotEmpty($result['errors']);
        self::assertSame(['urls' => [], 'errors' => []], $provider->discover(0));
    }

    public function testFullPrewarmDiscoversSitemapButSelectivePrewarmUsesOnlyRequestedUrls(): void
    {
        $GLOBALS['sympress_nginx_cache_test_options'][CompatibilitySettings::PREFIX . 'prewarm_sitemap'] = 1;
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $url;
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse(str_ends_with($url, '.xml') ? '<urlset><url><loc>https://example.test/discovered/</loc></url></urlset>' : 'HTML');
        });
        $settings = new WordPressCacheSettings('/unused');
        $policy = new UrlPolicy();
        $provider = new SitemapUrlProvider($http, new CompatibilitySettings($settings), $policy);
        $prewarmer = new Prewarmer($http, $settings, $policy, new CacheClock(new MockClock()), $provider);
        self::assertSame(['https://example.test/', 'https://example.test/discovered/'], $prewarmer->prewarm()->urls);
        self::assertSame(['https://example.test/wp-sitemap.xml', 'https://example.test/', 'https://example.test/discovered/'], $calls);
        $calls = [];
        $prewarmer->prewarm(['https://example.test/specific/']);
        self::assertSame(['https://example.test/specific/'], $calls);
    }

    public function testRedirectAndOversizedResponsesFailWithoutFollowingAnotherOrigin(): void
    {
        foreach ([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://foreign.test/']]), new MockResponse(str_repeat('x', 2_097_153))] as $response) {
            $http = new MockHttpClient($response);
            $provider = new SitemapUrlProvider($http, new CompatibilitySettings(new WordPressCacheSettings('/unused')), new UrlPolicy());
            $result = $provider->discover(20);
            self::assertSame([], $result['urls']);
            self::assertNotEmpty($result['errors']);
            self::assertSame(1, $http->getRequestsCount());
        }
    }
}
