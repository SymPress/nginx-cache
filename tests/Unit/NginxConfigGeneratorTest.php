<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

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
        self::assertStringContainsString('fastcgi_cache_key "$scheme|$request_method|$host|$request_uri";', $config);
        self::assertStringContainsString('fastcgi_no_cache $sympress_cache_skip $upstream_http_set_cookie $upstream_http_x_accel_expires;', $config);
        self::assertStringContainsString('fastcgi_cache_valid 200 301 10m;', $config);
        self::assertStringContainsString('add_header Cache-Control "public, max-age=31536000, immutable" always;', $config);
        self::assertStringNotContainsString('$http_x_forwarded_proto|$scheme', $config);
        self::assertStringNotContainsString('fastcgi_cache_valid 200 301 302', $config);
    }
}
