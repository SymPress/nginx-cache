<?php

declare(strict_types=1);

use SymPress\NginxCache\Admin\SimpleSettingsActions;
use SymPress\NginxCache\Admin\SimpleSettingsPage;
use SymPress\NginxCache\Settings\CachePolicy;
use SymPress\NginxCache\Settings\CachePresets;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\ConfigurationCatalog;
use SymPress\NginxCache\Settings\NetworkConfiguration;
use SymPress\NginxCache\Settings\NetworkSettings;
use SymPress\NginxCache\Settings\OptionSource;
use SymPress\NginxCache\Settings\UiMode;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\CacheStatus;

$ui = new UiMode();
$ui->initialize();
check($ui->simple(), 'fresh install selects the simple view before index initialization');
delete_option(UiMode::OPTION);
update_option(WordPressCacheSettings::OPTION_QUEUE_ENABLED, 1);
$ui->initialize();
check(!$ui->simple(), 'existing cache option preserves the advanced view');
delete_option(WordPressCacheSettings::OPTION_QUEUE_ENABLED);
update_option(UiMode::OPTION, 'simple', false);

$uiSettings = new WordPressCacheSettings('/var/cache/nginx/wordpress');
$uiOptions = new OptionSource();
$uiConfig = new NetworkConfiguration(new NetworkSettings(), $uiSettings, new CompatibilitySettings($uiSettings));
$presets = new CachePresets($uiConfig, $uiOptions);
$beforePresets = $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A);
$presets->preview('large');
check($beforePresets === $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A), 'preset preview writes no options');
$limits = [];
foreach (CachePolicy::defaults() as $key => $value) { $limits[$key] = get_option($key, $value); }
foreach (['small' => [0, 0, 50, 1000], 'standard' => [1, 5, 50, 1000], 'large' => [1, 15, 500, 50000]] as $preset => [$queued, $debounce, $urls, $tags]) {
    $presets->apply($preset);
    check($uiSettings->queueEnabled() === (bool) $queued && $uiSettings->debounceSeconds() === $debounce, 'preset queue and debounce: ' . $preset);
    check(get_option('sympress_nginx_cache_tag_urls_per_tag') === $urls && get_option('sympress_nginx_cache_tag_max_tags') === $tags, 'preset index limits: ' . $preset);
    foreach ($limits as $key => $value) { check(get_option($key, CachePolicy::defaults()[$key]) === $value, 'preset leaves Nginx limit unchanged: ' . $key); }
}
$actions = new SimpleSettingsActions($presets, $uiConfig, $uiOptions);
$actions->save([WordPressCacheSettings::OPTION_AUTO_PURGE => 1, WordPressCacheSettings::OPTION_PREWARM_ENABLED => 0, WordPressCacheSettings::OPTION_QUEUE_ENABLED => 0]);
check($uiSettings->autoPurgeEnabled() && !$uiSettings->prewarmEnabled() && $uiSettings->queueEnabled(), 'compact form saves only its three permitted fields');
$_POST = ['option_page' => 'sympress_nginx_cache'];
check($uiOptions->protectUpdate(null, WordPressCacheSettings::OPTION_QUEUE_ENABLED, 1) === 1 && $uiOptions->protectUpdate('new', 'unrelated_option', 'old') === 'new', 'partial settings submission preserves absent fields without affecting unrelated options');
$_POST = [];

require_once ABSPATH . 'wp-admin/includes/template.php';
$_GET['preset-preview'] = 'large';
$simple = new SimpleSettingsPage($uiSettings, $ui, $presets, $uiOptions);
$uiDiagnostics = ['metrics' => ['requests' => 9, 'hits' => 8, 'hit_rate' => 88.9, 'small_sample' => true, 'states' => ['HIT' => 8, 'MISS' => 1]], 'queue' => ['pending' => 0], 'tag_index' => ['tags' => 4, 'rows' => 4]];
ob_start();
$simple->render(new CacheStatus('/var/cache/nginx/wordpress', true, true, true, 1, 1, 39014, true), $uiDiagnostics, 'fastcgi_cache sympress_wordpress;', '1.0.0', ['Purge Cache' => '#purge', 'Dry Run' => '#dry', 'Prewarm' => '#warm', 'Flush Queue' => '#queue']);
$markup = ob_get_clean();
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
check($xpath->query('//h1')->length === 1 && $xpath->query('//form//form')->length === 0, 'simple view has one title and independent, unnested forms');
check(str_contains($markup, 'operation" value="apply') && str_contains($markup, 'name="preset" value="large"') && !str_contains($markup, 'action="options.php"'), 'preview renders an explicit authenticated apply step');
check($xpath->query('//input[@type="text"]')->length === 1 && $xpath->query('//input[@type="checkbox"]')->length === 2, 'simple view exposes only the path and two switches');
$artifact = getenv('NGINX_TEST_UI_ARTIFACT_DIR');
if (is_string($artifact) && is_dir($artifact)) {
    $page = file_get_contents(dirname(__DIR__, 2) . '/src/Admin/SettingsPage.php');
    preg_match('/<style>(.*?)<\/style>/s', $page, $styles);
    $css = $styles[1] . file_get_contents(dirname(__DIR__, 2) . '/Resources/assets/cache-metrics.css') . file_get_contents(dirname(__DIR__, 2) . '/Resources/assets/cache-simple.css');
    $base = 'body{margin:20px;background:#f0f0f1;font:14px/1.5 system-ui;color:#1d2327}input,textarea,button{font:inherit}input[type=text],textarea{padding:8px;border:1px solid #8c8f94;border-radius:4px}textarea{width:100%}.button{display:inline-block;text-decoration:none;cursor:pointer;padding:8px 14px;border:1px solid;border-radius:4px;line-height:1.5}.screen-reader-text{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}.widefat{width:100%;border-collapse:collapse}.widefat th,.widefat td{padding:12px;text-align:left;vertical-align:top}.striped tr:nth-child(even){background:#f6f7f7}';
    file_put_contents($artifact . '/simple-prototype.html', '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nginx Cache – einfacher Modus</title><style>' . $base . $css . '</style><div class="sympress-cache-admin">' . $markup . '</div></html>');
}
unset($_GET['preset-preview']);
$saved = array_column($beforePresets, 'option_value', 'option_name');
foreach (ConfigurationCatalog::defaults() as $option => $_) {
    if (array_key_exists($option, $saved)) { update_option($option, maybe_unserialize($saved[$option])); }
    else { delete_option($option); }
}
delete_option(UiMode::OPTION);
