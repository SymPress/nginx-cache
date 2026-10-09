<?php

declare(strict_types=1);

namespace {
    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(string $key, string $group = ''): bool { return true; }
    }
    if (!function_exists('get_current_network_id')) {
        function get_current_network_id(): int { return 1; }
    }
    if (!function_exists('is_multisite')) {
        function is_multisite(): bool { return (bool) ($GLOBALS['sympress_test_multisite'] ?? false); }
    }
    if (!function_exists('get_site_option')) {
        function get_site_option(string $name, mixed $default = false): mixed { return $GLOBALS['sympress_test_network_options'][$name] ?? $default; }
    }
    if (!function_exists('update_site_option')) {
        function update_site_option(string $name, mixed $value): bool { $GLOBALS['sympress_test_network_options'][$name] = $value; return true; }
    }
    if (!function_exists('get_userdata')) {
        function get_userdata(int $id): object|false { return $GLOBALS['sympress_test_users'][$id] ?? false; }
    }
}

namespace SymPress\NginxCache\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use SymPress\NginxCache\Security\Capabilities;
    use SymPress\NginxCache\Settings\NetworkSettings;
    use SymPress\NginxCache\Settings\OptionSource;
    use SymPress\NginxCache\Settings\WordPressCacheSettings;

    final class NetworkSettingsResolutionTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['sympress_test_multisite'], $GLOBALS['sympress_test_network_options'], $GLOBALS['sympress_test_users'], $GLOBALS['sympress_nginx_cache_test_options']);
        }

        public function testLockedValuesDefaultsAndExistingSitesResolveWithoutOverwritingSiteData(): void
        {
            $GLOBALS['sympress_test_multisite'] = true;
            $network = new NetworkSettings();
            $source = new OptionSource($network);
            $option = WordPressCacheSettings::OPTION_PATH;
            $GLOBALS['sympress_nginx_cache_test_options'][$option] = '/site/cache';
            self::assertSame('/site/cache', $source->value($option, '/code/cache'));
            $network->save($option, '/network/cache', 'default');
            self::assertSame('network', $network->policy($option));
            self::assertSame('/network/cache', $source->value($option, '/code/cache'));
            self::assertSame('/site/cache', $source->protectUpdate('/tampered/cache', $option, '/site/cache'));
            self::assertSame('/site/cache', $GLOBALS['sympress_nginx_cache_test_options'][$option]);
            $network->save(WordPressCacheSettings::OPTION_PROFILE, 'commerce');
            self::assertSame('commerce', $source->value(WordPressCacheSettings::OPTION_PROFILE, 'safe'));
            $GLOBALS['sympress_nginx_cache_test_options'][WordPressCacheSettings::OPTION_PROFILE] = 'public';
            self::assertSame('public', $source->value(WordPressCacheSettings::OPTION_PROFILE, 'safe'));
            $GLOBALS['sympress_test_multisite'] = false;
            self::assertSame('/site/cache', $source->value($option, '/code/cache'));
        }

        public function testRolesOnlyGrantUrlPurgeAndNetworkCapabilityUsesNetworkAdministration(): void
        {
            $caps = new Capabilities();
            $GLOBALS['sympress_test_users'][7] = (object) ['roles' => ['editor']];
            self::assertSame(['manage_options'], $caps->map([], Capabilities::PURGE_URL, 7));
            $GLOBALS['sympress_nginx_cache_test_options'][Capabilities::ROLES_OPTION] = ['editor'];
            self::assertSame(['exist'], $caps->map([], Capabilities::PURGE_URL, 7));
            self::assertSame(['manage_options'], $caps->map([], Capabilities::PURGE_SITE, 7));
            self::assertSame(['manage_options'], $caps->map([], Capabilities::MANAGE, 7));
            $GLOBALS['sympress_test_multisite'] = true;
            self::assertSame(['manage_network_options'], $caps->map([], Capabilities::PURGE_NETWORK, 7));
            self::assertSame(['original'], $caps->map(['original'], 'unrelated_capability', 7));
        }
    }
}
