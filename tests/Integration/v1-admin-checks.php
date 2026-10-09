<?php

declare(strict_types=1);

use SymPress\NginxCache\Admin\EditorActions;
use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Inspection\CacheMetricsReader;
use SymPress\NginxCache\Inspection\CacheProbe;
use SymPress\NginxCache\Inspection\CacheStatusInspector;
use SymPress\NginxCache\Inspection\Diagnostics;
use SymPress\NginxCache\Inspection\EnvironmentDetector;
use SymPress\NginxCache\Inspection\ResponseCacheability;
use SymPress\NginxCache\Inspection\SiteHealth;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Layer\CacheLayerCoordinator;
use SymPress\NginxCache\Migration\NginxHelperImporter;
use SymPress\NginxCache\Purge\CacheFileResolver;
use SymPress\NginxCache\Purge\PurgeUrlCollector;
use SymPress\NginxCache\Rest\CacheRestController;
use SymPress\NginxCache\Security\SecretCipher;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\ConfigurationCatalog;
use SymPress\NginxCache\Settings\NetworkConfiguration;
use SymPress\NginxCache\Surrogate\CacheTagResolver;

$configuration = new NetworkConfiguration($network, $settings, new CompatibilitySettings($settings));
$importer = new NginxHelperImporter($configuration, $network);
$legacy = ['cache_method' => 'enable_redis', 'enable_purge' => 1, 'roles_with_purge_cap' => ['editor' => 1], 'redis_password' => 'fixture-redis-password'];
update_option('rt_wp_nginx_helper_options', $legacy);
$original = $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A);
$beforeMigration = hash('sha256', serialize($original));
$preview = $importer->import('nginx-helper', true);
check($beforeMigration === hash('sha256', serialize($wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A))), 'migration preview performs zero option writes');
check(!str_contains(serialize($preview), 'fixture-redis-password'), 'migration report never exposes Redis credentials');
$importer->import('nginx-helper', false);
$ciphertext = get_option(CompatibilitySettings::REDIS_PASSWORD);
check(str_starts_with($ciphertext, SecretCipher::PREFIX) && (new SecretCipher())->decrypt($ciphertext, CompatibilitySettings::REDIS_PASSWORD) === 'fixture-redis-password', 'imported Redis password is authenticated ciphertext');
$beforeAdoption = hash('sha256', serialize($wpdb->get_results("SELECT meta_key,meta_value FROM {$wpdb->sitemeta} ORDER BY meta_id", ARRAY_A)));
$adopted = $configuration->adoption(get_current_blog_id(), true);
check($beforeAdoption === hash('sha256', serialize($wpdb->get_results("SELECT meta_key,meta_value FROM {$wpdb->sitemeta} ORDER BY meta_id", ARRAY_A))) && !str_contains(serialize($adopted), 'fixture-redis-password'), 'network adoption preview writes nothing and redacts credentials');
$saved = array_column($original, 'option_value', 'option_name');
foreach (ConfigurationCatalog::defaults() as $option => $_) {
    if (array_key_exists($option, $saved)) { update_option($option, maybe_unserialize($saved[$option])); }
    else { delete_option($option); }
}
delete_option('rt_wp_nginx_helper_options');

$rules = new BypassRuleProvider($settings);
$keys = new CacheKeyStrategy();
$files = new CacheFileResolver($settings, $keys);
$config = new NginxConfigGenerator($settings, $rules, $keys);
$layers = new CacheLayerCoordinator($settings);
$inspector = new CacheStatusInspector();
$diagnostics = new Diagnostics($settings, $inspector, $history, $queue, new EnvironmentDetector(), $config, $index, $layers, new CacheMetricsReader($clock));
$probe = new CacheProbe($http, $settings, $files, $rules, $policy, $keys, new ResponseCacheability(), $clock);
$rest = new CacheRestController($settings, $manager, $queue, $diagnostics, $config, $probe, $layers, $index, $policy);
wp_set_current_user($editor);
$nonce = wp_create_nonce('wp_rest');
$request = new WP_REST_Request('POST', '/sympress-nginx-cache/v1/purge');
$request->set_header('X-WP-Nonce', $nonce);
$request->set_param('urls', ['https://mapped.test/public/']);
check($rest->permission($request), 'delegated editor may purge same-origin URLs through REST');
$request->set_param('urls', []);
check(!$rest->permission($request), 'editor cannot request a REST full purge');
$request->set_param('urls', ['https://foreign.test/']);
check(!$rest->permission($request), 'invalid URL request cannot turn into an authorized full purge');
$request->set_param('urls', ['https://mapped.test/public/']);
$request->set_header('X-WP-Nonce', 'invalid');
check(!$rest->permission($request), 'cookie REST mutation requires the correct nonce');
wp_set_current_user($admin);
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$request->set_param('scope', 'network');
check(!$rest->permission($request), 'site administrator cannot use the network REST scope');
$request->set_param('scope', 'site');
$request->set_param('queue', true);
$request->set_param('dry_run', true);
$queueBefore = $queue->count();
$dryResponse = $rest->purge($request);
check($dryResponse->get_status() === 200 && $queue->count() === $queueBefore && $effects->count() === 0, 'REST dry-run with queue flag enqueues no work');

$collector = new PurgeUrlCollector(new CacheTagResolver(), $index, $policy, $settings);
$editorActions = new EditorActions($manager, $queue, $collector, $policy, $settings);
$publicId = wp_insert_post(['post_title' => 'Public fixture', 'post_status' => 'publish']);
$privateId = wp_insert_post(['post_title' => 'Private fixture', 'post_status' => 'private']);
wp_set_current_user($editor);
check(count($editorActions->postActions([], get_post($publicId))) === 1 && $editorActions->postActions([], get_post($privateId)) === [], 'editor row actions only appear for public posts');
check($editorActions->objectUrls('post', (string) $privateId) === [] && $editorActions->objectUrls('url', 'https://foreign.test/') === [], 'object purge refuses private posts and foreign origins');
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
$originalQuery = $GLOBALS['wp_query'];
try {
    $GLOBALS['wp_query'] = new WP_Query(['p' => $publicId]);
    $bar = new WP_Admin_Bar();
    $editorActions->adminBar($bar);
    $node = $bar->get_node('sympress-nginx-cache-current');
    $query = [];
    if ($node) { parse_str(wp_parse_url(html_entity_decode($node->href), PHP_URL_QUERY), $query); }
    check($node && ($query['value'] ?? '') === wp_get_canonical_url($publicId) && wp_verify_nonce($query['_wpnonce'] ?? '', 'sympress_nginx_cache_purge_object'), 'frontend editor action uses the canonical public URL and a valid nonce');
    $GLOBALS['wp_query'] = new WP_Query(['p' => $privateId]);
    check($editorActions->currentUrl() === '', 'frontend action refuses private or unavailable pages');
} finally { $GLOBALS['wp_query'] = $originalQuery; }
wp_set_current_user(0);
check($editorActions->postActions([], get_post($publicId)) === [] && $editorActions->bulkActions([]) === [], 'guests receive no editor purge actions');
wp_set_current_user($admin);
update_option(CompatibilitySettings::REDIS_PASSWORD, (new SecretCipher())->encrypt('fixture-debug-redis', CompatibilitySettings::REDIS_PASSWORD));
update_option($settings::OPTION_CLOUDFLARE_API_TOKEN, (new SecretCipher())->encrypt('fixture-debug-cloudflare', $settings::OPTION_CLOUDFLARE_API_TOKEN));
$health = new SiteHealth($settings, new CompatibilitySettings($settings), new \SymPress\NginxCache\Filesystem\CachePathValidator($fs), $queue, $effects, $scope, $clock, $index);
$debug = serialize($health->debug([]));
check(!str_contains($debug, 'fixture-debug-redis') && !str_contains($debug, 'fixture-debug-cloudflare') && !str_contains($debug, SecretCipher::PREFIX) && !str_contains($debug, 'redis_password'), 'Site Health export whitelists fields and omits credentials');
check(count($health->tests([])['direct']) === 6 && $health->check('worker')['status'] === 'good', 'direct Site Health checks recognize the recent worker heartbeat');
delete_option(CompatibilitySettings::REDIS_PASSWORD);
delete_option($settings::OPTION_CLOUDFLARE_API_TOKEN);
