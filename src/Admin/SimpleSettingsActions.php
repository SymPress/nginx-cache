<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Security\Capabilities;
use SymPress\NginxCache\Settings\CachePresets;
use SymPress\NginxCache\Settings\NetworkConfiguration;
use SymPress\NginxCache\Settings\OptionSource;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class SimpleSettingsActions
{
    public const string ACTION = 'sympress_nginx_cache_simple';

    public function __construct(private CachePresets $presets, private NetworkConfiguration $configuration, private OptionSource $options)
    {
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $this->configuration->register();
        $values = [];
        foreach ([WordPressCacheSettings::OPTION_PATH, WordPressCacheSettings::OPTION_AUTO_PURGE, WordPressCacheSettings::OPTION_PREWARM_ENABLED] as $option) {
            if ($this->options->locked($option) || !array_key_exists($option, $input)) {
                continue;
            }

            $values[$option] = $this->configuration->sanitize($option, $input[$option]);
        }
        foreach ($values as $option => $value) {
            update_option($option, $value, false);
        }
    }

    public function handle(): void
    {
        check_admin_referer(self::ACTION);
        if (!current_user_can(Capabilities::MANAGE)) {
            wp_die(esc_html__('Cache management permission is required.', WordPressCacheSettings::TEXT_DOMAIN));
        }
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $preset = isset($_POST['preset']) && is_string($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
        $url = admin_url('tools.php?page=sympress-nginx-cache');
        if ($operation === 'save') {
            $this->save(wp_unslash($_POST));
            $url = add_query_arg('settings-updated', 'true', $url);
        } elseif (in_array($operation, ['preview', 'apply'], true) && in_array($preset, ['small', 'standard', 'large'], true)) {
            if ($operation === 'apply') {
                $this->presets->apply($preset);
                $url = add_query_arg('settings-updated', 'true', $url);
            } else {
                $url = add_query_arg('preset-preview', $preset, $url);
            }
        } else {
            wp_die(esc_html__('Unknown cache settings action.', WordPressCacheSettings::TEXT_DOMAIN));
        }
        wp_safe_redirect($url);
        exit;
    }
}
