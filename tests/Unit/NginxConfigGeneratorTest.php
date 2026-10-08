<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\CacheProfile;

final class NginxConfigGeneratorTest extends TestCase
{
    public function testGeneratedConfigUsesDefensiveCacheDefaults(): void
    {
        $generator = new NginxConfigGenerator(
            new WordPressCacheSettings('/var/cache/nginx/wordpress'),
            new BypassRuleProvider(),
            new CacheKeyStrategy(),
        );
        $config = $generator->generate();

        self::assertStringContainsString('map $http_authorization $sympress_cache_skip_authorization', $config);
        self::assertStringContainsString('fastcgi_cache_key "$scheme|$request_method|$host|$sympress_cache_request_uri";', $config);
        self::assertStringContainsString('map $query_string $sympress_cache_tracking_query', $config);
        self::assertStringContainsString("map \$query_string \$sympress_cache_skip_query {\n    default 1;\n    \"\" 0;", $config);
        self::assertStringNotContainsString('    ~^$ 0;', $config);
        self::assertStringContainsString('    1 $sympress_cache_original_path;', $config);
        self::assertStringContainsString('fastcgi_param QUERY_STRING $sympress_cache_query_string;', $config);
        self::assertStringContainsString('fastcgi_param REQUEST_URI $sympress_cache_request_uri;', $config);
        self::assertStringContainsString('fastcgi_no_cache $sympress_cache_skip $upstream_http_set_cookie $upstream_http_x_accel_expires;', $config);
        self::assertStringContainsString('fastcgi_cache_valid 200 301 600s;', $config);
        self::assertStringContainsString('add_header Cache-Control "public, max-age=31536000, immutable" always;', $config);
        self::assertStringNotContainsString('$http_x_forwarded_proto|$scheme', $config);
        self::assertStringNotContainsString('fastcgi_cache_valid 200 301 302', $config);
        self::assertStringNotContainsString('sympress_consent', $config);
    }

    public function testConsentDoesNotBypassAnyDefaultProfile(): void
    {
        foreach (CacheProfile::cases() as $profile) {
            self::assertNotContains('sympress_consent', (new BypassRuleProvider())->rules($profile)['cookies']);
        }
    }

    public function testIncludesKeepDirectivesInTheirNginxContexts(): void
    {
        $generator = new NginxConfigGenerator(
            new WordPressCacheSettings('/var/cache/nginx/wordpress'),
            new BypassRuleProvider(),
            new CacheKeyStrategy(),
        );
        $http = $generator->generate(context: 'http');
        $server = $generator->generate(context: 'server');
        $fastcgi = $generator->generate(context: 'fastcgi');
        $logging = $generator->generate(context: 'logging');

        self::assertStringContainsString('fastcgi_cache_path /var/cache/nginx/wordpress ', $http);
        self::assertStringContainsString('map $http_cookie $sympress_cache_skip_cookie', $http);
        self::assertStringContainsString('log_format sympress_cache_metrics escape=json', $http);
        self::assertStringNotContainsString('$remote_addr', $http);
        self::assertStringContainsString('access_log /var/log/nginx/sympress-cache-metrics.jsonl sympress_cache_metrics if=$sympress_cache_metric_loggable;', $logging);
        self::assertStringNotContainsString('location ', $logging);
        self::assertStringNotContainsString('location ~', $http);
        self::assertStringNotContainsString('fastcgi_cache_bypass', $http);
        self::assertStringContainsString('location ~*', $server);
        self::assertStringNotContainsString('fastcgi_cache_path', $server);
        self::assertStringNotContainsString('fastcgi_cache WORDPRESS', $server);
        self::assertStringContainsString('fastcgi_cache WORDPRESS;', $fastcgi);
        self::assertStringContainsString('fastcgi_no_cache $sympress_cache_skip $upstream_http_set_cookie', $fastcgi);
        self::assertStringNotContainsString('fastcgi_cache_path', $fastcgi);
        self::assertStringNotContainsString('map ', $fastcgi);
        self::assertStringNotContainsString('location ~', $fastcgi);
        self::assertSame($http . "\n" . $server . "\n" . $fastcgi, $generator->generate());
        foreach (['http' => $http, 'server' => $server, 'logging' => $logging, 'fastcgi' => $fastcgi] as $context => $config) {
            self::assertSame([], $generator->validate($config, $context));
        }
        self::assertSame(['fastcgi_cache_path'], $generator->validate($fastcgi, 'http'));
    }

    public function testItRejectsUnknownIncludeContexts(): void
    {
        $generator = new NginxConfigGenerator(
            new WordPressCacheSettings('/var/cache/nginx/wordpress'),
            new BypassRuleProvider(),
            new CacheKeyStrategy(),
        );
        $this->expectException(\InvalidArgumentException::class);
        $generator->generate(context: 'unknown');
    }

    #[RunInSeparateProcess]
    public function testItRejectsMetricsLogsInsideThePurgeableCacheRoot(): void
    {
        define('SYMPRESS_NGINX_CACHE_METRICS_LOG', '/var/cache/nginx/wordpress/metrics.jsonl');
        $settings = new WordPressCacheSettings('/var/cache/nginx/wordpress');
        $generator = new NginxConfigGenerator($settings, new BypassRuleProvider(), new CacheKeyStrategy());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the cache root');
        $generator->generate();
    }

    #[RunInSeparateProcess]
    public function testItRejectsUnsafeMetricsLogDirectivePaths(): void
    {
        define('SYMPRESS_NGINX_CACHE_METRICS_LOG', '/var/log/nginx/metrics;$host');
        $settings = new WordPressCacheSettings('/var/cache/nginx/wordpress');
        $generator = new NginxConfigGenerator($settings, new BypassRuleProvider(), new CacheKeyStrategy());
        $this->expectException(\InvalidArgumentException::class);
        $generator->generate();
    }

    public function testOnlyTrackingQueriesUseCanonicalCacheKeys(): void
    {
        $keys = new CacheKeyStrategy();
        self::assertSame($keys->candidates('https://example.test/article/'), $keys->candidates('https://example.test/article/?utm_source=campaign&gclid=123'));
        self::assertNotSame($keys->candidates('https://example.test/article/'), $keys->candidates('https://example.test/article/?utm_source=campaign&s=secret'));
        self::assertSame(0, preg_match('/' . CacheKeyStrategy::TRACKING_QUERY_PATTERN . '/D', 's=search&ut m_source=test'));
        self::assertSame(0, preg_match('/' . CacheKeyStrategy::TRACKING_QUERY_PATTERN . '/D', 'utm_source=test&preview=true'));
    }
}
