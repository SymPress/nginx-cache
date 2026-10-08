<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Settings\CachePolicy;

final class CachePolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['sympress_nginx_cache_test_options']);
    }

    public function testBoundsAndMalformedValuesCannotInjectNginxDirectives(): void
    {
        $GLOBALS['sympress_nginx_cache_test_options'] = [
            'sympress_nginx_cache_valid_seconds' => '7200; bad;',
            'sympress_nginx_cache_inactive_seconds' => -5,
            'sympress_nginx_cache_max_size_mb' => 999999,
            'sympress_nginx_cache_keys_zone_mb' => 0,
        ];
        $settings = new WordPressCacheSettings('/fixture');
        self::assertSame(['valid_seconds' => 600, 'inactive_seconds' => 60, 'max_size_mb' => 65536, 'keys_zone_mb' => 1], CachePolicy::values());
        $generator = new NginxConfigGenerator($settings, new BypassRuleProvider(), new CacheKeyStrategy());
        self::assertStringContainsString('keys_zone=WORDPRESS:1m inactive=60s max_size=65536m', $generator->generate(context: 'http'));
        self::assertStringContainsString('fastcgi_cache_valid 200 301 600s;', $generator->generate(context: 'fastcgi'));
    }

    #[RunInSeparateProcess]
    public function testConstantsOverrideOptionsAndNginxLevelsAreRestricted(): void
    {
        define('SYMPRESS_NGINX_CACHE_VALID_SECONDS', 1800);
        define('SYMPRESS_NGINX_CACHE_LEVELS', '8:2:1:1');
        $GLOBALS['sympress_nginx_cache_test_options']['sympress_nginx_cache_valid_seconds'] = 900;
        $settings = new WordPressCacheSettings('/fixture');
        self::assertSame(1800, CachePolicy::values()['valid_seconds']);
        self::assertSame('1:2', $settings->cacheLevels());
    }
}
