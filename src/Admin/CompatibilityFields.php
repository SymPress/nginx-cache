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
            <?php $this->renderRules(); ?>
        </div>
        <?php
    }

    private function renderRules(): void
    {
        $events = [
            'edit' => __('Edit / publish', WordPressCacheSettings::TEXT_DOMAIN),
            'delete' => __('Delete / trash', WordPressCacheSettings::TEXT_DOMAIN),
            'comment_new' => __('New / approved comment', WordPressCacheSettings::TEXT_DOMAIN),
            'comment_delete' => __('Removed / unapproved comment', WordPressCacheSettings::TEXT_DOMAIN),
        ];
        ?>
        <fieldset>
            <legend><h3><?php echo esc_html__('Automatic purge rules', WordPressCacheSettings::TEXT_DOMAIN); ?></h3></legend>
            <p><?php echo esc_html__('Choose which pages are invalidated by each content event. Existing defaults purge every scope. Whole-cache purges and queue overflow still invalidate the entire selected cache.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
            <table class="widefat">
                <thead><tr><th scope="col"><?php echo esc_html__('Scope', WordPressCacheSettings::TEXT_DOMAIN); ?></th>
                    <?php foreach ($events as $label) : ?><th scope="col"><?php echo esc_html($label); ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach (['home' => __('Homepage', WordPressCacheSettings::TEXT_DOMAIN), 'page' => __('Changed page', WordPressCacheSettings::TEXT_DOMAIN), 'archive' => __('Archives / listings', WordPressCacheSettings::TEXT_DOMAIN)] as $scope => $scopeLabel) : ?>
                        <tr><th scope="row"><?php echo esc_html($scopeLabel); ?></th>
                            <?php foreach ($events as $event => $label) : $name = 'purge_' . $scope . '_' . $event; ?>
                                <td><label><input type="hidden" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . $name); ?>" value="0" /><input type="checkbox" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . $name); ?>" value="1" <?php checked($this->settings->integer($name) !== 0); ?> /><span class="screen-reader-text"><?php echo esc_html($scopeLabel . ': ' . $label); ?></span></label></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </fieldset>
        <?php
    }
}
