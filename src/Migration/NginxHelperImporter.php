<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Migration;

use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\ConfigurationCatalog;
use SymPress\NginxCache\Settings\NetworkConfiguration;
use SymPress\NginxCache\Settings\NetworkSettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class NginxHelperImporter
{
    public function __construct(private NetworkConfiguration $configuration, private NetworkSettings $network)
    {
    }

    /**
     * @param array<string, mixed> $legacy
     * @return array<string, mixed>
     */
    public function map(array $legacy): array
    {
        $prefix = CompatibilitySettings::PREFIX;
        $enabled = static fn (string $name, int $default = 1): int => empty($legacy[$name] ?? $default) ? 0 : 1;
        $redis = ($legacy['cache_method'] ?? '') === 'enable_redis';
        $backend = $redis ? 'redis' : (($legacy['purge_method'] ?? '') === 'unlink_files' ? 'local_files' : 'http');
        $values = [
            WordPressCacheSettings::OPTION_AUTO_PURGE      => $enabled('enable_purge', 0),
            WordPressCacheSettings::OPTION_SELECTIVE_PURGE => 1,
            WordPressCacheSettings::OPTION_PURGE_FEEDS     => $enabled('purge_feeds'),
            WordPressCacheSettings::OPTION_PURGE_AMP       => $enabled('purge_amp_urls'),
            WordPressCacheSettings::OPTION_PREWARM_ENABLED => $enabled('preload_cache', 0),
            $prefix . 'prewarm_sitemap'                    => $enabled('preload_cache', 0),
            $prefix . 'html_stamp'                         => $enabled('enable_stamp', 0),
            $prefix . 'purge_backend'                      => $backend,
            $prefix . 'key_template'                       => '$scheme$request_method$host$request_uri',
            $prefix . 'additional_purge_urls'              => is_string($legacy['purge_url'] ?? null) ? $legacy['purge_url'] : '',
        ];
        $rules = [
            'purge_homepage_on_edit'           => ['home_edit'],
            'purge_homepage_on_del'            => ['home_delete'],
            'purge_page_on_mod'                => ['page_edit', 'page_delete'],
            'purge_page_on_new_comment'        => ['page_comment_new'],
            'purge_page_on_deleted_comment'    => ['page_comment_delete'],
            'purge_archive_on_edit'            => ['archive_edit'],
            'purge_archive_on_del'             => ['archive_delete'],
            'purge_archive_on_new_comment'     => ['archive_comment_new'],
            'purge_archive_on_deleted_comment' => ['archive_comment_delete'],
        ];
        foreach ($rules as $old => $targets) {
            foreach ($targets as $target) {
                $values[$prefix . 'purge_' . $target] = $enabled($old, str_starts_with($target, 'archive_comment') ? 0 : 1);
            }
        }
        foreach (['hostname' => 'host', 'port' => 'port', 'database' => 'database', 'prefix' => 'prefix', 'unix_socket' => 'socket', 'username' => 'username', 'password' => 'password'] as $old => $new) {
            if (!array_key_exists('redis_' . $old, $legacy)) {
                continue;
            }

            $values[$prefix . 'redis_' . $new] = $legacy['redis_' . $old];
        }
        $roles = [];
        foreach ((array) ($legacy['roles_with_purge_cap'] ?? []) as $role => $allowed) {
            if (is_int($role) && is_string($allowed)) {
                $roles[] = $allowed;
            } elseif (is_string($role) && !empty($allowed)) {
                $roles[] = $role;
            }
        }
        $values[$prefix . 'purge_roles'] = array_values(array_diff(array_unique($roles), ['administrator']));
        $path = defined('RT_WP_NGINX_HELPER_CACHE_PATH') ? constant('RT_WP_NGINX_HELPER_CACHE_PATH') : null;
        if (is_string($path) && $path !== '') {
            $values[WordPressCacheSettings::OPTION_PATH] = $path;
        }
        return $values;
    }

    /** @return list<array{option: string, value: mixed, status: string}> */
    public function import(string $source, bool $dryRun = true, bool $network = false): array
    {
        if (!in_array($source, ['nginx-helper', 'nginx-cache'], true)) {
            throw new \InvalidArgumentException('Supported sources: nginx-helper, nginx-cache.');
        }
        if ($network && !$this->network->active()) {
            throw new \InvalidArgumentException('Network import requires Multisite.');
        }
        if ($source === 'nginx-cache') {
            return [['option' => 'nginx_cache_path / nginx_auto_purge', 'value' => '[already supported]', 'status' => 'unchanged']];
        }
        $legacy = $network ? get_site_option('rt_wp_nginx_helper_options', null) : get_option('rt_wp_nginx_helper_options', null);
        $legacy ??= get_site_option('rt_wp_nginx_helper_options', null);
        if (!is_array($legacy)) {
            throw new \RuntimeException('No Nginx Helper settings were found.');
        }
        $this->configuration->register();
        $values = [];
        foreach ($this->map($legacy) as $option => $value) {
            $values[$option] = $this->configuration->sanitize($option, $value);
        }
        $report = [];
        foreach ($values as $option => $value) {
            $managed = !$network && $this->network->managed($option);
            if (!$dryRun && !$managed) {
                if ($network) {
                    $this->network->save($option, $value);
                } else {
                    update_option($option, $value);
                }
            }
            $report[] = ['option' => $option, 'value' => ConfigurationCatalog::secret($option) ? ($value === '' ? '[empty]' : '[configured]') : $value, 'status' => $managed ? 'network-managed' : ($dryRun ? 'preview' : 'imported')];
        }
        return $report;
    }
}
