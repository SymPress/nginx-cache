<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Cli\Command\PrewarmCommand;
use SymPress\NginxCache\Cli\UrlInputNormalizer;
use SymPress\NginxCache\Purge\Prewarmer;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Time\CacheClock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PrewarmerTest extends TestCase
{
    public function testAffectedOnlyPreservesEveryChangedObjectAndExcludesRelatedArchives(): void
    {
        $GLOBALS['sympress_nginx_cache_test_options']['sympress_nginx_cache_prewarm_affected_only'] = 1;
        try {
            $prewarmer = new Prewarmer(new MockHttpClient(), new WordPressCacheSettings('/fixture'), new UrlPolicy(), new CacheClock(new MockClock()));
            $affected = ['https://example.test/changed-a/', 'https://example.test/changed-b/'];
            $urls = [$affected[0], 'https://example.test/archive/', 'https://example.test/', $affected[1]];
            self::assertSame($affected, $prewarmer->plan($urls, true, $affected)->urls);
            self::assertSame($affected, $prewarmer->plan([], true, $affected)->urls);
            self::assertSame([], $prewarmer->plan([], true)->urls);
        } finally {
            unset($GLOBALS['sympress_nginx_cache_test_options']['sympress_nginx_cache_prewarm_affected_only']);
        }
    }

    public function testTransientErrorsFailWhileOutdatedTargetsAreSkippedAndEmptyBatchesDoNotWarmHome(): void
    {
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $options;
            return new MockResponse('', ['http_code' => str_contains($url, '/error/') ? 503 : (str_contains($url, '/redirect/') ? 302 : 200)]);
        });
        $prewarmer = new Prewarmer($http, new WordPressCacheSettings('/fixture'), new UrlPolicy(), new CacheClock(new MockClock()));
        self::assertSame(0, $prewarmer->warmUrls([])->attempted());
        self::assertSame([], $calls);
        $result = $prewarmer->prewarm(['https://example.test/good/', 'https://example.test/error/', 'https://example.test/redirect/', 'https://foreign.test/']);
        self::assertSame(3, $result->attempted());
        self::assertSame(['https://example.test/good/' => 200], $result->responses);
        self::assertSame(['https://example.test/error/: HTTP 503.'], $result->errors);
        self::assertSame(['https://example.test/redirect/'], $result->stale());
        self::assertCount(3, $calls);
        foreach ($calls as $options) {
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(5.0, $options['max_duration']);
        }
        $foreign = $prewarmer->warmUrls(['https://foreign.test/']);
        self::assertSame([0, ['https://foreign.test/']], [$foreign->failed(), $foreign->stale()]);
        self::assertCount(3, $calls);
    }

    public function testRedirectsAreRevalidatedPerHopAndRemovedTargetsAreStale(): void
    {
        $requested = [];
        $routes = [
            '/moved/'   => [301, '/new/'],
            '/new/'     => [200, null],
            '/sibling/' => [302, 'target/'],
            '/sibling/target/' => [200, null],
            '/escape/'  => [301, 'https://foreign.test/'],
            '/loop-a/'  => [302, 'https://example.test/loop-b/'],
            '/loop-b/'  => [302, '/loop-a/'],
            '/chain-1/' => [301, '/chain-2/'],
            '/chain-2/' => [301, '/chain-3/'],
            '/chain-3/' => [301, '/chain-4/'],
            '/chain-4/' => [301, '/chain-5/'],
            '/chain-5/' => [200, null],
            '/removed/' => [404, null],
            '/gone/'    => [410, null],
            '/limited/' => [429, null],
        ];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requested, $routes): MockResponse {
            $requested[] = $url;
            [$status, $location] = $routes[(string) parse_url($url, PHP_URL_PATH)];
            return new MockResponse('', ['http_code' => $status, 'response_headers' => $location === null ? [] : ['Location: ' . $location]]);
        });
        $prewarmer = new Prewarmer($http, new WordPressCacheSettings('/fixture'), new UrlPolicy(), new CacheClock(new MockClock()));
        $urls = array_map(static fn (string $path): string => 'https://example.test' . $path, ['/moved/', '/sibling/', '/escape/', '/loop-a/', '/chain-1/', '/removed/', '/gone/', '/limited/']);
        $result = $prewarmer->warmUrls($urls);

        self::assertSame(['https://example.test/moved/' => 200, 'https://example.test/sibling/' => 200], $result->responses);
        self::assertSame([$urls[2], $urls[3], $urls[4], $urls[5], $urls[6]], $result->stale());
        self::assertSame(['https://example.test/limited/: HTTP 429.'], $result->errors);
        self::assertStringContainsString('HTTP 404', $result->issues[$urls[5]]['reason']);
        self::assertStringNotContainsString('foreign', implode(' ', array_column($result->issues, 'reason')));
        self::assertNotContains('https://foreign.test/', $requested);
        self::assertNotContains('https://example.test/chain-5/', $requested);
        self::assertSame(2, count(array_keys($requested, 'https://example.test/loop-a/', true)) + count(array_keys($requested, 'https://example.test/loop-b/', true)));
    }

    public function testIncompleteBodyTransfersStayRetryableWhileCompleteSiblingsSucceed(): void
    {
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $options;
            $body = match ((string) parse_url($url, PHP_URL_PATH)) {
                // Headers arrived, then the stream broke or went idle before Content-Length was reached.
                '/broken/' => ['<html>', new TransportException('HTTP/2 stream 1 was not closed cleanly: INTERNAL_ERROR')],
                '/stalled/' => ['<html>', ''],
                default => ['<html>', '</html>'],
            };
            return new MockResponse($body, ['http_code' => 200]);
        });
        $prewarmer = new Prewarmer($http, new WordPressCacheSettings('/fixture'), new UrlPolicy(), new CacheClock(new MockClock()));
        $urls = ['https://example.test/broken/', 'https://example.test/complete/', 'https://example.test/stalled/'];
        $result = $prewarmer->warmUrls($urls);

        self::assertSame(['https://example.test/complete/' => 200], $result->responses);
        self::assertSame([$urls[0] . ': HTTP 200 response transfer was incomplete.', $urls[2] . ': HTTP 200 response transfer was incomplete.'], $result->errors);
        self::assertSame(['reason' => 'HTTP 200 response transfer was incomplete.', 'stale' => false], $result->issues[$urls[0]]);
        self::assertSame([], $result->stale());
        self::assertCount(3, $calls);
        foreach ($calls as $options) {
            self::assertFalse($options['buffer']);
            self::assertSame(5.0, $options['max_duration']);
        }
    }

    public function testCliReturnsFailureForAnUnsuccessfulHttpResponse(): void
    {
        $policy = new UrlPolicy();
        $prewarmer = new Prewarmer(new MockHttpClient(new MockResponse('', ['http_code' => 500])), new WordPressCacheSettings('/fixture'), $policy, new CacheClock(new MockClock()));
        $command = new CommandTester(new PrewarmCommand($prewarmer, new UrlInputNormalizer($policy)));
        self::assertSame(1, $command->execute(['urls' => ['https://example.test/error/']]));
        self::assertStringContainsString('HTTP 500', $command->getDisplay());
    }

    public function testAffectedPageHomeAndArchivesArePrioritizedAndRateLimited(): void
    {
        $clock = new CacheClock(new MockClock('2026-10-09'));
        $settings = new WordPressCacheSettings('/fixture');
        $prewarmer = new Prewarmer(new MockHttpClient(new MockResponse('', ['http_code' => 200])), $settings, new UrlPolicy(), $clock);
        $plan = $prewarmer->plan(['https://example.test/changed/', 'https://example.test/category/news/', 'https://foreign.test/'], withRelated: true);
        self::assertSame(['https://example.test/changed/', 'https://example.test/', 'https://example.test/category/news/'], $plan->urls);
        self::assertSame(0, $plan->priorities['https://example.test/changed/']);
        self::assertSame(1, $plan->priorities['https://example.test/']);
        self::assertSame(2, $plan->priorities['https://example.test/category/news/']);
        $started = $clock->highResolutionTimestamp();
        $prewarmer->warmUrls($plan->urls);
        self::assertGreaterThanOrEqual(0.599, $clock->elapsedSince($started));
        self::assertSame(5, $settings->prewarmRequestsPerSecond());
    }
}
