<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$core = getenv('NGINX_TEST_WORDPRESS_DIR');
$schema = getenv('NGINX_TEST_DB_NAME');
if (!is_string($core) || !is_file($core . '/wp-settings.php') || !is_string($schema) || !preg_match('/^sympress_review_nginx[a-z0-9_]*$/', $schema)) {
    throw new RuntimeException('Set NGINX_TEST_WORDPRESS_DIR and a disposable NGINX_TEST_DB_NAME starting sympress_review_nginx.');
}
define('ABSPATH', rtrim($core, '/') . '/');
define('DB_NAME', $schema);
define('DB_USER', getenv('NGINX_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('NGINX_TEST_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('NGINX_TEST_DB_HOST') ?: '127.0.0.1:33079');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WP_HOME', 'https://example.test');
define('WP_SITEURL', 'https://example.test');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/sympress-nginx-integration-content-' . $schema);
define('DISABLE_WP_CRON', true);
define('WP_INSTALLING', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('SAVEQUERIES', true);
// Ephemeral fixture material, never a deployment credential.
define('AUTH_KEY', str_repeat('fixture-auth-', 8));
define('SECURE_AUTH_SALT', str_repeat('fixture-salt-', 8));
$table_prefix = 'nginx_test_';
require ABSPATH . 'wp-settings.php';
add_filter('pre_wp_mail', static fn (): bool => false);
add_filter('pre_http_request', static fn (): WP_Error => new WP_Error('fixture-network-blocked', 'External requests are forbidden in this harness.'));

function testIndex(): \SymPress\NginxCache\Surrogate\TagIndexRepository
{
    return new \SymPress\NginxCache\Surrogate\TagIndexRepository(new \SymPress\NginxCache\Surrogate\CacheTagResolver(), new \SymPress\NginxCache\Security\UrlPolicy(), new \SymPress\NginxCache\Time\CacheClock(new \Symfony\Component\Clock\NativeClock()), testMutex());
}

function testMutex(): \SymPress\NginxCache\Support\OptionMutex
{
    return new \SymPress\NginxCache\Support\OptionMutex(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()));
}

function testQueue(?\SymPress\NginxCache\Time\CacheClock $clock = null): \SymPress\NginxCache\Purge\PurgeQueueRepository
{
    return new \SymPress\NginxCache\Purge\PurgeQueueRepository(new \SymPress\NginxCache\Purge\PurgeRequestMerger(), testMutex(), $clock ?? new \SymPress\NginxCache\Time\CacheClock(new \Symfony\Component\Clock\NativeClock()));
}
