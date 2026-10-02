<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
        self::assertStringContainsString('    1 $sympress_cache_original_path;', $config);
        self::assertStringContainsString('fastcgi_no_cache $sympress_cache_skip $upstream_http_set_cookie $upstream_http_x_accel_expires;', $config);
        self::assertStringContainsString('fastcgi_cache_valid 200 301 10m;', $config);
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

    public function testOnlyTrackingQueriesUseCanonicalCacheKeys(): void
    {
        $keys = new CacheKeyStrategy();
        self::assertSame($keys->candidates('https://example.test/article/'), $keys->candidates('https://example.test/article/?utm_source=campaign&gclid=123'));
        self::assertNotSame($keys->candidates('https://example.test/article/'), $keys->candidates('https://example.test/article/?utm_source=campaign&s=secret'));
        self::assertSame(0, preg_match('/' . CacheKeyStrategy::TRACKING_QUERY_PATTERN . '/D', 's=search&ut m_source=test'));
        self::assertSame(0, preg_match('/' . CacheKeyStrategy::TRACKING_QUERY_PATTERN . '/D', 'utm_source=test&preview=true'));
    }
}
