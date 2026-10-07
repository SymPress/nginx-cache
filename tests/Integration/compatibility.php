<?php

declare(strict_types=1);

use SymPress\Kernel\Hook\HookCompilerPass;
use SymPress\Kernel\Hook\HookLoader;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\PurgeUrlCollector;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Value\PurgeRequest;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Exercise the consumer's real compiled service graph and hook compiler in WordPress.
$container = new ContainerBuilder();
(new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/Resources/config')))->load('services.yaml');
$container->register(HookLoader::class, HookLoader::class)->setPublic(true);
$container->addCompilerPass(new HookCompilerPass());
foreach ([CacheManager::class, PurgeUrlCollector::class, CompatibilitySettings::class] as $service) {
    $container->getDefinition($service)->setPublic(true);
}
$container->register(HttpClientInterface::class)->setSynthetic(true)->setPublic(true);
$container->compile();
$httpCalls = 0;
$container->set(HttpClientInterface::class, new MockHttpClient(static function () use (&$httpCalls): never {
    ++$httpCalls;
    throw new RuntimeException('Unexpected consumer HTTP request.');
}));
$container->get(HookLoader::class)->register();
do_action('admin_init');
$registered = get_registered_settings();
check(isset($registered[CompatibilitySettings::PREFIX . 'purge_backend'], $registered[CompatibilitySettings::PREFIX . 'prewarm_sitemap'], $registered[CompatibilitySettings::REDIS_PASSWORD]), 'compiled kernel hooks register compatibility settings');
check(has_action('shutdown') !== false && has_action('wp_update_site') !== false, 'compiled kernel installs stamp and multisite hooks');

update_option(CompatibilitySettings::PREFIX . 'purge_backend', 'redis');
$dryResult = $container->get(CacheManager::class)->purgeConfiguredPath(PurgeRequest::full(dryRun: true, prewarm: true));
check($dryResult->successful && $dryResult->dryRun && $dryResult->path === 'redis' && $httpCalls === 0, 'Redis dry run through compiled manager needs no filesystem, Redis or HTTP');
delete_option(CompatibilitySettings::PREFIX . 'purge_backend');

$postId = wp_insert_post(['post_title' => 'Purge rule fixture', 'post_status' => 'publish']);
$permalink = get_permalink($postId);
$commentId = wp_insert_comment(['comment_post_ID' => $postId, 'comment_content' => 'Fixture comment', 'comment_approved' => 1]);
$collectorWithRules = $container->get(PurgeUrlCollector::class);
update_option(CompatibilitySettings::PREFIX . 'purge_home_edit', 0);
update_option(CompatibilitySettings::PREFIX . 'purge_archive_edit', 0);
$ruleUrls = $collectorWithRules->collect('save_post', [$postId, get_post($postId)]);
check(in_array($permalink, $ruleUrls, true) && !in_array(home_url('/'), $ruleUrls, true) && !in_array(home_url('/wp-sitemap.xml'), $ruleUrls, true), 'real post edit obeys homepage and archive exclusions');
check($collectorWithRules->collectTags('save_post', [$postId]) === [], 'limited rules prevent tag expansion from purging excluded scopes');
update_option(CompatibilitySettings::PREFIX . 'purge_page_comment_delete', 0);
$commentUrls = $collectorWithRules->collect('transition_comment_status', ['trash', 'approved', get_comment($commentId)]);
check(!in_array($permalink, $commentUrls, true) && in_array(home_url('/'), $commentUrls, true), 'real comment transition uses comment-delete rules instead of post-edit rules');
check($collectorWithRules->collect('comment_post', [$commentId, 0]) === [], 'unapproved comments do not invalidate public pages');
foreach (['purge_home_edit', 'purge_archive_edit', 'purge_page_comment_delete'] as $name) {
    delete_option(CompatibilitySettings::PREFIX . $name);
}

// Remove only the hooks registered by this disposable consumer; later harness cleanup remains isolated.
foreach ($container->getDefinition(HookLoader::class)->getArgument(1) as $hook) {
    remove_all_filters($hook['hook'], $hook['priority']);
}
