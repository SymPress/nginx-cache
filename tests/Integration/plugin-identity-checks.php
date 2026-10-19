<?php

declare(strict_types=1);

use SymPress\NginxCache\Admin\CacheMetricsPanel;
use Symfony\Component\Yaml\Yaml;

// Use real WordPress discovery and update checks; intercept every update HTTP request.
(static function (): void {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $root = dirname(__DIR__, 2);
    $composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $basename = $composer['extra']['kernel']['entry'];
    $slug = $composer['extra']['installer-name'];
    $mainFile = $root . '/' . basename($basename);
    check($slug === 'ns-nginx-cache' && dirname($basename) === $slug && is_file($mainFile), 'Composer install name resolves the real plugin entry');
    $headers = get_plugin_data($mainFile, false, false);
    check($headers['Name'] === 'NS Nginx Cache' && $headers['UpdateURI'] === 'https://github.com/SymPress/nginx-cache', 'WordPress reads the distinct plugin name and external Update URI');
    $workflow = Yaml::parseFile($root . '/.github/workflows/archive.yml');
    $archive = $workflow['jobs']['archive']['with'];
    check($archive['package_name'] === $slug && $archive['package_folder'] === $slug && $archive['main_file'] === basename($basename) && $workflow['jobs']['archive-check']['with']['package_folder'] === $slug, 'release archive and archive check use the Composer plugin basename');

    $pluginDirectory = WP_PLUGIN_DIR . '/' . $slug;
    wp_mkdir_p(WP_PLUGIN_DIR);
    check(!file_exists($pluginDirectory) && symlink($root, $pluginDirectory), 'plugin discovery fixture owns a new symlink');
    $originalPaths = $GLOBALS['wp_plugin_paths'];
    $originalCache = wp_cache_get('plugins', 'plugins');
    $originalUpdates = get_site_transient('update_plugins');
    $requestCount = 0;
    $externalChecks = 0;
    $active = [];
    $activePlugins = static function () use (&$active): array { return $active; };
    $externalUpdate = static function (mixed $update, array $data, string $file) use (&$externalChecks, $basename, $headers): bool {
        check($file === $basename && $data['UpdateURI'] === $headers['UpdateURI'], 'WordPress routes this plugin to its external update host');
        ++$externalChecks;
        return false;
    };
    $updateRequest = static function (mixed $pre, array $args, string $url) use (&$requestCount, &$active, $basename, $headers): mixed {
        if (!str_contains($url, 'api.wordpress.org/plugins/update-check/')) { return $pre; }
        ++$requestCount;
        $payload = json_decode($args['body']['plugins'], true, 512, JSON_THROW_ON_ERROR);
        check($payload['plugins'][$basename]['UpdateURI'] === $headers['UpdateURI'] && $payload['active'] === $active, 'update request carries the Update URI with the current activation state');
        // WordPress.org excludes non-directory Update URIs; unrelated updates remain available.
        return ['response' => ['code' => 200], 'body' => wp_json_encode(['plugins' => ['fixture/fixture.php' => ['slug' => 'fixture', 'plugin' => 'fixture/fixture.php', 'new_version' => '2.0.0', 'package' => 'https://example.test/fixture.zip']], 'no_update' => [], 'translations' => []])];
    };
    add_filter('pre_option_active_plugins', $activePlugins);
    add_filter('pre_http_request', $updateRequest, 100, 3);
    add_filter('update_plugins_github.com', $externalUpdate, 10, 3);
    try {
        wp_register_plugin_realpath($pluginDirectory . '/' . basename($basename));
        wp_clean_plugins_cache(false);
        $plugins = get_plugins();
        check(isset($plugins[$basename]) && $plugins[$basename]['UpdateURI'] === $headers['UpdateURI'] && !is_plugin_active($basename), 'WordPress discovers the protected plugin while inactive');
        $plugins['fixture/fixture.php'] = array_replace($headers, ['Name' => 'Unrelated update fixture', 'UpdateURI' => '', 'Version' => '1.0.0']);
        wp_cache_set('plugins', ['' => $plugins], 'plugins');
        foreach ([[], [$basename]] as $active) {
            delete_site_transient('update_plugins');
            wp_update_plugins();
            $updates = get_site_transient('update_plugins');
            check(!isset($updates->response[$basename]) && isset($updates->response['fixture/fixture.php']), 'protected plugin receives no directory update while unrelated updates remain visible');
        }
        check($requestCount === 2 && $externalChecks === 2, 'inactive and active plugins both retain the external update identity');
        (new CacheMetricsPanel())->enqueueStyles();
        foreach (['simple', 'metrics'] as $style) {
            check(str_ends_with(wp_styles()->registered['sympress-nginx-cache-' . $style]->src, '/' . $slug . '/Resources/assets/cache-' . $style . '.css'), 'dashboard stylesheet resolves under the new plugin folder: ' . $style);
        }
    } catch (Throwable $error) {
        fwrite(STDERR, (string) $error . PHP_EOL);
        throw $error;
    } finally {
        remove_filter('pre_option_active_plugins', $activePlugins);
        remove_filter('pre_http_request', $updateRequest, 100);
        remove_filter('update_plugins_github.com', $externalUpdate);
        $GLOBALS['wp_plugin_paths'] = $originalPaths;
        wp_cache_delete('plugins', 'plugins');
        if ($originalCache !== false) { wp_cache_set('plugins', $originalCache, 'plugins'); }
        if ($originalUpdates === false) { delete_site_transient('update_plugins'); }
        else { set_site_transient('update_plugins', $originalUpdates); }
        unlink($pluginDirectory);
    }
})();
