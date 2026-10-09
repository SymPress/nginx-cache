<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Migration\NginxHelperImporter;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\NetworkConfiguration;
use SymPress\NginxCache\Settings\NetworkSettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final class NginxHelperImporterTest extends TestCase
{
    public function testHelper241BackendsRuleMatrixAndAssociativeRolesAreMapped(): void
    {
        $settings = new WordPressCacheSettings('/tmp/nginx-cache-import-fixture');
        $network = new NetworkSettings();
        $importer = new NginxHelperImporter(new NetworkConfiguration($network, $settings, new CompatibilitySettings($settings)), $network);
        foreach ([['enable_fastcgi', 'unlink_files', 'local_files'], ['enable_fastcgi', 'get_request', 'http'], ['enable_redis', 'get_request', 'redis']] as [$method, $purge, $expected]) {
            $mapped = $importer->map(['cache_method' => $method, 'purge_method' => $purge, 'enable_purge' => 1, 'purge_homepage_on_edit' => 0, 'preload_cache' => 1, 'roles_with_purge_cap' => ['administrator' => 1, 'editor' => 1, 'subscriber' => 0], 'redis_hostname' => 'cache.internal', 'redis_unix_socket' => '/run/redis/cache.sock']);
            self::assertSame($expected, $mapped['sympress_nginx_cache_purge_backend']);
            self::assertSame('$scheme$request_method$host$request_uri', $mapped['sympress_nginx_cache_key_template']);
            self::assertSame(1, $mapped['nginx_auto_purge']);
            self::assertSame(0, $mapped['sympress_nginx_cache_purge_home_edit']);
            self::assertSame(0, $mapped['sympress_nginx_cache_purge_archive_comment_new']);
            self::assertSame(1, $mapped['sympress_nginx_cache_purge_archive_edit']);
            self::assertSame(['editor'], $mapped['sympress_nginx_cache_purge_roles']);
            self::assertSame('cache.internal', $mapped['sympress_nginx_cache_redis_host']);
            self::assertSame('/run/redis/cache.sock', $mapped['sympress_nginx_cache_redis_socket']);
            self::assertSame(1, $mapped['sympress_nginx_cache_prewarm_enabled']);
            self::assertSame(1, $mapped['sympress_nginx_cache_prewarm_sitemap']);
        }
        self::assertSame([['option' => 'nginx_cache_path / nginx_auto_purge', 'value' => '[already supported]', 'status' => 'unchanged']], $importer->import('nginx-cache'));
    }
}
