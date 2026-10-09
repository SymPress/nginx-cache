<?php

declare(strict_types=1);

use SymPress\NginxCache\Integration\Polylang\PolylangIntegration;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Value\CacheProfile;

function pll_get_post_translations(int $id): array {
    if (isset($GLOBALS['fixture_pll_reentry'])) { ($GLOBALS['fixture_pll_reentry'])(); }
    return $GLOBALS['fixture_pll_posts'][$id] ?? [];
}
function pll_get_term_translations(int $id): array { return $GLOBALS['fixture_pll_terms'][$id] ?? []; }
function pll_home_url(string $language): string { return home_url('/' . $language . '/'); }
function pll_languages_list(array $args = []): array { return ['de', 'en', 'fr']; }
function pll_get_post(int $id, string $language): int { return $GLOBALS['fixture_pll_posts'][$id][$language] ?? 0; }

$integration = new PolylangIntegration($collector);
check($withoutPolylang === $config->generate(), 'optional integration leaves config unchanged when no filters are registered');
$hooks = ['affected_urls' => ['affectedUrls', 3], 'purge_urls' => ['purgeUrls', 3], 'post_tags' => ['postTags', 2], 'term_tags' => ['termTags', 2], 'purge_tags' => ['purgeTags', 3], 'purge_actions' => ['actions', 1], 'full_purge_hooks' => ['fullHooks', 3], 'bypass_cookies' => ['cookies', 1], 'bypass_uris' => ['uris', 1], 'query_allowlist' => ['queryAllowlist', 1], 'prewarm_urls' => ['prewarmUrls', 1]];
foreach ($hooks as $hook => [$method, $accepted]) { add_filter('sympress_nginx_cache_' . $hook, [$integration, $method], 10, $accepted); }
$posts = [];
$terms = [];
foreach (['de', 'en', 'fr'] as $language) {
    $id = wp_insert_post(['post_title' => 'Translation ' . $language, 'post_status' => 'publish', 'post_name' => $language . '-translation']);
    $posts[$language] = $id;
    $created = wp_insert_term('Term ' . $language, 'category');
    $terms[$language] = $created['term_id'];
    wp_set_post_terms($id, [$created['term_id']], 'category');
}
foreach ($posts as $id) { $GLOBALS['fixture_pll_posts'][$id] = $posts; }
foreach ($terms as $id) { $GLOBALS['fixture_pll_terms'][$id] = $terms; }
check(count($collector->affectedUrls('save_post', [$posts['de']])) === 3, 'all translated primary URLs retain prewarm priority');
$blogs = [];
foreach (['de', 'en', 'fr'] as $language) { $blogs[$language] = wp_insert_post(['post_title' => 'Blog ' . $language, 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $language . '-blog']); }
foreach ($blogs as $id) { $GLOBALS['fixture_pll_posts'][$id] = $blogs; }
update_option('page_for_posts', $blogs['de']);
$withBlog = $collector->collect('save_post', [$posts['de']]);
check(in_array(get_permalink($blogs['en']), $withBlog, true) && in_array(get_permalink($blogs['fr']), $withBlog, true), 'translated posts pages are collected through the public language API');
delete_option('page_for_posts');
$reentries = 0;
$GLOBALS['fixture_pll_reentry'] = static function () use ($integration, $posts, &$reentries): void {
    ++$reentries;
    check($integration->purgeUrls(['unchanged'], 'save_post', [$posts['de']]) === ['unchanged'], 'integration refuses recursive filter reentry');
};
$integration->purgeUrls([], 'save_post', [$posts['de']]);
unset($GLOBALS['fixture_pll_reentry']);
check($reentries === 1, 'language collection guard is released after one bounded API call');
update_option('polylang', ['force_lang' => 1, 'browser' => 1]);
$translated = $collector->collect('save_post', [$posts['de'], get_post($posts['de'])]);
foreach ($posts as $language => $id) {
    check(in_array(get_permalink($id), $translated, true) && in_array(pll_home_url($language), $translated, true), 'translated permalink and language home collected: ' . $language);
    check(in_array(get_term_link($terms[$language]), $translated, true), 'translated term archive collected: ' . $language);
}
$termUrls = $collector->collect('edited_term', [$terms['de']]);
check(in_array(get_term_link($terms['en']), $termUrls, true) && in_array(get_term_link($terms['fr']), $termUrls, true), 'term changes include all translation archives');
$resolver = new CacheTagResolver();
check(in_array('pll-group:' . min($posts), $resolver->postTags($posts['de']), true) && in_array('pll-group:' . min($posts), $collector->collectTags('save_post', [$posts['de']]), true), 'group tag is emitted and invalidated through the public tag API');
check(in_array('pll-term-group:' . min($terms), $resolver->termTags($terms['de']), true), 'term translation group uses the smallest term ID');
foreach (CacheProfile::cases() as $profile) {
    $generated = $rules->rules($profile);
    check(!in_array('pll_language', $generated['cookies'], true) && in_array('^/$', $generated['uris'], true), 'directory mode does not bypass language cookies and limits negotiation to root: ' . $profile->value);
}
check(in_array('pll_save_post', apply_filters('sympress_nginx_cache_purge_actions', []), true), 'verified pll_save_post hook is registered');
check($collector->requiresFullPurge('pll_add_language') && $collector->requiresFullPurge('delete_term', [$terms['de'], 1, 'language']) && !$collector->requiresFullPurge('delete_term', [$terms['de'], 1, 'category']), 'language mutations invalidate the site without widening ordinary term edits');
$prewarmPlan = $settings->prewarmUrls();
check(in_array(pll_home_url('en'), $prewarmPlan, true) && in_array(pll_home_url('fr'), $prewarmPlan, true), 'full purge prewarm includes every language home');
update_option('polylang', ['force_lang' => 0, 'browser' => 1]);
$queryRules = $rules->rules(CacheProfile::Publishing);
check(in_array('pll_language', $queryRules['cookies'], true) && in_array('^lang=[a-zA-Z0-9_-]+$', $queryRules['query_allowlist'], true) && !in_array('^/$', $queryRules['uris'], true), 'query mode preserves the language cookie and permits functional lang queries');
check($keys->candidates(home_url('/?lang=en')) !== $keys->candidates(home_url('/?lang=de')), 'language query values remain distinct cache key candidates');
check($integration->diagnostic()['warning'] !== '', 'query-language mode carries an optimization diagnostic');
$foreign = static fn (array $urls): array => [...$urls, 'https://foreign.test/en/'];
add_filter('sympress_nginx_cache_purge_urls', $foreign, 20);
check(!in_array('https://foreign.test/en/', $collector->collect('pll_save_post', [$posts['de'], get_post($posts['de']), $posts]), true), 'translated URL filters still pass the same-origin boundary');
remove_filter('sympress_nginx_cache_purge_urls', $foreign, 20);
foreach ($hooks as $hook => [$method, $accepted]) { remove_filter('sympress_nginx_cache_' . $hook, [$integration, $method]); }
delete_option('polylang');
