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
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PrewarmerTest extends TestCase
{
    public function testHttpErrorsAndRedirectsAreFailuresAndEmptyBatchesDoNotWarmHome(): void
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
        self::assertSame(1, $result->successful());
        self::assertSame(2, $result->failed());
        self::assertCount(3, $calls);
        foreach ($calls as $options) {
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(5.0, $options['max_duration']);
        }
        self::assertSame(1, $prewarmer->warmUrls(['https://foreign.test/'])->failed());
    }

    public function testCliReturnsFailureForAnUnsuccessfulHttpResponse(): void
    {
        $policy = new UrlPolicy();
        $prewarmer = new Prewarmer(new MockHttpClient(new MockResponse('', ['http_code' => 500])), new WordPressCacheSettings('/fixture'), $policy, new CacheClock(new MockClock()));
        $command = new CommandTester(new PrewarmCommand($prewarmer, new UrlInputNormalizer($policy)));
        self::assertSame(1, $command->execute(['urls' => ['https://example.test/error/']]));
        self::assertStringContainsString('HTTP 500', $command->getDisplay());
    }
}
