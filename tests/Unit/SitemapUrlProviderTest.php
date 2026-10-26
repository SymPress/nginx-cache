<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Purge\SitemapUrlProvider;
use SymPress\NginxCache\Purge\Prewarmer;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use Symfony\Component\HttpClient\Exception\TransportException;
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
        $provider = new SitemapUrlProvider($http, new CompatibilitySettings(new WordPressCacheSettings('/unused')), new UrlPolicy(), new CacheClock(new MockClock()));
        self::assertSame(['urls' => ['https://example.test/a/'], 'errors' => []], $provider->discover(1));
        self::assertCount(2, $calls);
    }

    public function testEntityDeclarationsAreRejected(): void
    {
        $http = new MockHttpClient(new MockResponse('<!DOCTYPE urlset [<!ENTITY leak SYSTEM "file:///etc/passwd">]><urlset><url><loc>&leak;</loc></url></urlset>'));
        $provider = new SitemapUrlProvider($http, new CompatibilitySettings(new WordPressCacheSettings('/unused')), new UrlPolicy(), new CacheClock(new MockClock()));
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
        $provider = new SitemapUrlProvider($http, new CompatibilitySettings($settings), $policy, new CacheClock(new MockClock()));
        $prewarmer = new Prewarmer($http, $settings, $policy, new CacheClock(new MockClock()), $provider);
        self::assertSame(['https://example.test/', 'https://example.test/discovered/'], $prewarmer->prewarm()->urls);
        self::assertSame(['https://example.test/wp-sitemap.xml', 'https://example.test/', 'https://example.test/discovered/'], $calls);
        $calls = [];
        $prewarmer->prewarm(['https://example.test/specific/']);
        self::assertSame(['https://example.test/specific/'], $calls);
    }

    public function testFailuresNameTheSitemapAndAFixedReasonPerFailureKind(): void
    {
        $mock = new MockClock('2026-10-26 12:00:00');
        $maps = ['503', 'stall', 'slow', 'refused', 'big', 'broken', 'html', 'doctype', 'ok'];
        $http = new MockHttpClient(static function (string $method, string $url) use ($mock, $maps): MockResponse {
            return match ((string) parse_url($url, PHP_URL_PATH)) {
                '/wp-sitemap.xml' => new MockResponse('<sitemapindex>' . implode('', array_map(static fn (string $map): string => '<sitemap><loc>https://example.test/' . $map . '.xml</loc></sitemap>', $maps)) . '</sitemapindex>'),
                '/503.xml' => new MockResponse('unavailable', ['http_code' => 503]),
                '/stall.xml' => new MockResponse(['<urlset>', '']),
                // max_duration surfaces as a plain transport error once the five-second budget is spent.
                '/slow.xml' => new MockResponse((static function () use ($mock): \Generator {
                    yield '<urlset>';
                    $mock->sleep(5.1);
                    throw new TransportException('Max duration was reached for "https://example.test/slow.xml?token=provider-detail".');
                })()),
                '/refused.xml' => new MockResponse('', ['error' => 'Connection refused for provider-detail']),
                '/big.xml' => new MockResponse(str_repeat('x', 2_097_153)),
                '/broken.xml' => new MockResponse('<urlset><url>'),
                '/html.xml' => new MockResponse('<html><body>Maintenance</body></html>'),
                '/doctype.xml' => new MockResponse('<!DOCTYPE urlset><urlset/>'),
                default => new MockResponse('<urlset><url><loc>https://example.test/known/</loc></url></urlset>'),
            };
        });
        $provider = new SitemapUrlProvider($http, new CompatibilitySettings(new WordPressCacheSettings('/unused')), new UrlPolicy(), new CacheClock($mock));
        $result = $provider->discover(20);

        self::assertSame(['https://example.test/known/'], $result['urls']);
        self::assertSame([
            'https://example.test/503.xml: Sitemap HTTP 503.',
            'https://example.test/stall.xml: Sitemap request timed out.',
            'https://example.test/slow.xml: Sitemap request timed out.',
            'https://example.test/refused.xml: Sitemap request failed.',
            'https://example.test/big.xml: Sitemap exceeds the 2 MiB byte budget.',
            'https://example.test/broken.xml: Sitemap XML is invalid.',
            'https://example.test/html.xml: Sitemap XML is not a sitemap or sitemap index.',
            'https://example.test/doctype.xml: Sitemap XML declarations are forbidden.',
        ], $result['errors']);
        self::assertStringNotContainsString('provider-detail', implode(' ', $result['errors']));

        $GLOBALS['sympress_nginx_cache_test_options'][CompatibilitySettings::PREFIX . 'prewarm_sitemap_url'] = 'https://user:secret@foreign.test/sitemap.xml';
        self::assertSame(['Sitemap URL must use the configured site origin.'], $provider->discover(20)['errors']);
    }

    public function testRedirectAndOversizedResponsesFailWithoutFollowingAnotherOrigin(): void
    {
        foreach ([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://foreign.test/']]), new MockResponse(str_repeat('x', 2_097_153))] as $response) {
            $http = new MockHttpClient($response);
            $provider = new SitemapUrlProvider($http, new CompatibilitySettings(new WordPressCacheSettings('/unused')), new UrlPolicy(), new CacheClock(new MockClock()));
            $result = $provider->discover(20);
            self::assertSame([], $result['urls']);
            self::assertNotEmpty($result['errors']);
            self::assertSame(1, $http->getRequestsCount());
        }
    }
}
