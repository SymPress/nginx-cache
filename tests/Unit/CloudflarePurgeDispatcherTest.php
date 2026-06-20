<?php

declare(strict_types=1);

namespace {
    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['sympress_nginx_cache_test_options'][$name] ?? $default;
        }
    }
}

namespace SymPress\NginxCache\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use SymPress\NginxCache\Remote\CloudflarePurgeDispatcher;
    use SymPress\NginxCache\Security\UrlPolicy;
    use SymPress\NginxCache\Settings\WordPressCacheSettings;
    use SymPress\NginxCache\Surrogate\CacheTagResolver;
    use SymPress\NginxCache\Value\PurgeMode;
    use SymPress\NginxCache\Value\PurgeRequest;
    use SymPress\NginxCache\Value\PurgeResult;
    use Symfony\Component\HttpClient\MockHttpClient;
    use Symfony\Component\HttpClient\Response\MockResponse;

    final class CloudflarePurgeDispatcherTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['sympress_nginx_cache_test_options'] = [
                WordPressCacheSettings::OPTION_CLOUDFLARE_ENABLED => 1,
                WordPressCacheSettings::OPTION_CLOUDFLARE_ZONE_ID => 'zone-123',
                WordPressCacheSettings::OPTION_CLOUDFLARE_API_TOKEN => 'token-abc',
            ];
        }

        public function testItDispatchesTagPurgesToCloudflare(): void
        {
            $body = null;
            $authorization = null;
            $client = new MockHttpClient(
                static function (string $method, string $url, array $options) use (&$body, &$authorization): MockResponse {
                    $body = $options['body'] ?? null;
                    $authorization = $options['normalized_headers']['authorization'][0] ?? null;

                    return new MockResponse('{"success":true}', ['http_code' => 200]);
                },
            );
            $dispatcher = new CloudflarePurgeDispatcher(
                $client,
                new WordPressCacheSettings('/tmp/cache'),
                new CacheTagResolver(),
                new UrlPolicy(),
            );
            $request = PurgeRequest::urls(
                ['https://example.test/post/42/'],
                tags: ['post:42', 'rest:post:42'],
            );
            $result = PurgeResult::success(
                '/tmp/cache',
                1,
                0.01,
                PurgeMode::Urls,
                requestedUrls: ['https://example.test/post/42/'],
                purgedUrls: ['https://example.test/post/42/'],
            );

            $responses = $dispatcher->dispatch($result, $request);

            self::assertSame('Authorization: Bearer token-abc', $authorization);
            self::assertTrue($responses[0]['successful']);
            self::assertJson((string) $body);
            self::assertSame(['tags' => ['post:42', 'rest:post:42']], json_decode((string) $body, true));
        }
    }
}
