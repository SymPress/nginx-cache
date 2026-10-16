<?php

declare(strict_types=1);

use SymPress\NginxCache\Migration\LegacyHookBridge;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\OptionSource;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

$roundTripSettings = new WordPressCacheSettings('/tmp/disposable-cache');
$roundTripCompatibility = new CompatibilitySettings($roundTripSettings);
$roundTripSource = new OptionSource();
$roundTripKeys = [
    WordPressCacheSettings::OPTION_PREWARM_ENABLED,
    WordPressCacheSettings::OPTION_PREWARM_URLS,
    WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS,
    WordPressCacheSettings::OPTION_BYPASS_URIS,
    CompatibilitySettings::PREFIX . 'additional_purge_urls',
];
$roundTripMissing = new stdClass();
$roundTripBefore = [];
foreach ($roundTripKeys as $roundTripKey) {
    $roundTripBefore[$roundTripKey] = get_option($roundTripKey, $roundTripMissing);
}
$roundTripRegistered = array_filter(get_registered_settings(), static fn (array $setting): bool => $setting['group'] === 'sympress_nginx_cache');
foreach ($roundTripRegistered as $roundTripKey => $roundTripDefinition) {
    unregister_setting('sympress_nginx_cache', $roundTripKey);
}
$roundTripPost = $_POST;
$roundTripSettings->register(false);
$roundTripCompatibility->register();
$roundTripProtection = $roundTripSource->protectUpdate(...);
add_filter('pre_update_option', $roundTripProtection, 10, 3);

// Real Settings API callbacks and DB updates, using the options.php POST loop.
$roundTripSubmit = static function (array $payload) use ($roundTripKeys): void {
    $_POST = wp_slash(['option_page' => 'sympress_nginx_cache'] + $payload);
    foreach ($roundTripKeys as $option) {
        $value = null;
        if (isset($_POST[$option])) {
            $value = $_POST[$option];
            if (!is_array($value)) {
                $value = trim($value);
            }
            $value = wp_unslash($value);
        }
        update_option($option, $value);
    }
};

try {
    $roundTripUrls = [home_url('/settings-first/'), home_url('/settings-second/')];
    $roundTripUrlText = implode("\n", $roundTripUrls);
    // Public-IP endpoint fixtures require neither DNS resolution nor HTTP.
    $roundTripRemoteUrls = ['https://1.1.1.1/settings-first', 'https://1.1.1.1/settings-second'];
    $roundTripRemoteText = implode("\n", $roundTripRemoteUrls);
    $roundTripRegex = '^/private/.*\.pdf$' . "\n" . '^/account/\d+$';
    $roundTripSubmit([
        WordPressCacheSettings::OPTION_PREWARM_ENABLED => '1',
        WordPressCacheSettings::OPTION_PREWARM_URLS => $roundTripUrlText,
        WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS => $roundTripRemoteText,
        WordPressCacheSettings::OPTION_BYPASS_URIS => $roundTripRegex,
        CompatibilitySettings::PREFIX . 'additional_purge_urls' => $roundTripUrlText,
    ]);
    check($roundTripSettings->prewarmEnabled(), 'registered options.php save activates Prewarm');
    check(get_option(WordPressCacheSettings::OPTION_BYPASS_URIS) === $roundTripRegex, 'registered options.php save preserves regex backslashes');
    check(get_option(WordPressCacheSettings::OPTION_PREWARM_URLS) === $roundTripUrlText, 'registered options.php save preserves multiline Prewarm URLs');
    check(count(array_intersect($roundTripUrls, $roundTripSettings->prewarmUrls())) === 2, 'both saved Prewarm URLs remain separate targets');
    check(get_option(WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS) === $roundTripRemoteText, 'registered options.php save preserves multiline remote endpoints');
    check($roundTripSettings->remoteEndpoints() === $roundTripRemoteUrls, 'both saved remote endpoints remain separate targets');
    check(get_option(CompatibilitySettings::PREFIX . 'additional_purge_urls') === $roundTripUrlText, 'registered options.php save preserves multiline additional purge URLs');
    $roundTripBridge = new LegacyHookBridge($container->get(CacheManager::class), $roundTripCompatibility, $roundTripSettings);
    check($roundTripBridge->additionalUrls([]) === $roundTripUrls, 'legacy bridge receives both additional purge targets after save');

    $roundTripSubmit([WordPressCacheSettings::OPTION_PREWARM_URLS => $roundTripUrlText]);
    check($roundTripSettings->prewarmEnabled(), 'partial settings save preserves enabled Prewarm when its field is absent');
    $roundTripSubmit([WordPressCacheSettings::OPTION_PREWARM_ENABLED => '0']);
    check(!$roundTripSettings->prewarmEnabled(), 'registered options.php save explicitly disables Prewarm');
    check(get_option(WordPressCacheSettings::OPTION_BYPASS_URIS) === $roundTripRegex, 'partial settings save preserves absent multiline regex');
    $roundTripSubmit([WordPressCacheSettings::OPTION_PREWARM_URLS => $roundTripUrlText]);
    check(!$roundTripSettings->prewarmEnabled(), 'partial settings save preserves disabled Prewarm when its field is absent');
    $roundTripSubmit([WordPressCacheSettings::OPTION_PREWARM_ENABLED => '1']);
    check($roundTripSettings->prewarmEnabled(), 'Prewarm can be enabled again after an explicit disable');
} finally {
    remove_filter('pre_update_option', $roundTripProtection, 10);
    $_POST = $roundTripPost;
    foreach (get_registered_settings() as $roundTripKey => $roundTripDefinition) {
        if ($roundTripDefinition['group'] === 'sympress_nginx_cache') {
            unregister_setting('sympress_nginx_cache', $roundTripKey);
        }
    }
    foreach ($roundTripBefore as $roundTripKey => $roundTripValue) {
        if ($roundTripValue === $roundTripMissing) {
            delete_option($roundTripKey);
        } else {
            update_option($roundTripKey, $roundTripValue);
        }
    }
    foreach ($roundTripRegistered as $roundTripKey => $roundTripDefinition) {
        register_setting('sympress_nginx_cache', $roundTripKey, $roundTripDefinition);
    }
}
