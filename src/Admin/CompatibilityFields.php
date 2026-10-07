<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class CompatibilityFields
{
    public function __construct(private CompatibilitySettings $settings)
    {
    }

    public function render(): void
    {
        ?>
        <div class="sympress-card sympress-form-card">
            <h3><?php echo esc_html__('Purge backend', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
            <label class="sympress-field">
                <span class="sympress-field__label"><?php echo esc_html__('Cache storage', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                <select name="<?php echo esc_attr(CompatibilitySettings::PREFIX . 'purge_backend'); ?>">
                    <?php foreach (['local_files' => __('Local cache files', WordPressCacheSettings::TEXT_DOMAIN), 'redis' => __('Redis page cache', WordPressCacheSettings::TEXT_DOMAIN), 'http' => __('Nginx GET purge location', WordPressCacheSettings::TEXT_DOMAIN)] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($this->settings->string('purge_backend'), $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p><?php echo esc_html__('Redis uses a dedicated page-cache prefix. HTTP purges require a protected Nginx location and the remote signing secret. Full HTTP purges also require the configured full-purge endpoint.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
            <?php
            foreach (
                [
                'redis_host'        => __('Redis hostname', WordPressCacheSettings::TEXT_DOMAIN),
                'redis_port'        => __('Redis port', WordPressCacheSettings::TEXT_DOMAIN),
                'redis_database'    => __('Redis database', WordPressCacheSettings::TEXT_DOMAIN),
                'redis_prefix'      => __('Redis page-cache prefix (dedicated to this site)', WordPressCacheSettings::TEXT_DOMAIN),
                'redis_socket'      => __('Redis Unix socket (optional)', WordPressCacheSettings::TEXT_DOMAIN),
                'redis_username'    => __('Redis ACL username (optional)', WordPressCacheSettings::TEXT_DOMAIN),
                'http_purge_prefix' => __('Nginx URL purge prefix', WordPressCacheSettings::TEXT_DOMAIN),
                ] as $name => $label
            ) :
                $numeric = in_array($name, ['redis_port', 'redis_database'], true);
                ?>
                <label class="sympress-field">
                    <span class="sympress-field__label"><?php echo esc_html($label); ?></span>
                    <input type="<?php echo $numeric ? 'number' : 'text'; ?>" class="regular-text code sympress-input" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . $name); ?>" value="<?php echo esc_attr($numeric ? (string) $this->settings->integer($name) : $this->settings->string($name)); ?>" />
                </label>
            <?php endforeach; ?>
            <div class="sympress-field">
                <span class="sympress-field__label"><?php echo esc_html__('Redis password', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                <?php SecretField::render(CompatibilitySettings::REDIS_PASSWORD); ?>
            </div>
        </div>
        <?php
    }
}
