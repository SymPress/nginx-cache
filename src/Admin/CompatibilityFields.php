<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Config\MultisiteMapGenerator;
use SymPress\NginxCache\Security\Capabilities;
use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class CompatibilityFields
{
    public function __construct(
        private CompatibilitySettings $settings,
        private ?MultisiteMapGenerator $maps = null,
    ) {
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
            <?php $this->renderTools(); ?>
        </div>
        <?php
    }

    private function renderRules(): void
    {
        $events = [
            'edit'           => __('Edit / publish', WordPressCacheSettings::TEXT_DOMAIN),
            'delete'         => __('Delete / trash', WordPressCacheSettings::TEXT_DOMAIN),
            'comment_new'    => __('New / approved comment', WordPressCacheSettings::TEXT_DOMAIN),
            'comment_delete' => __('Removed / unapproved comment', WordPressCacheSettings::TEXT_DOMAIN),
        ];
        ?>
        <fieldset>
            <legend><h3><?php echo esc_html__('Automatic purge rules', WordPressCacheSettings::TEXT_DOMAIN); ?></h3></legend>
            <p><?php echo esc_html__('Choose which pages are invalidated by each content event. Existing defaults purge every scope. Whole-cache purges and queue overflow still invalidate the entire selected cache.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
            <div class="sympress-table-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr__('Automatic purge rules', WordPressCacheSettings::TEXT_DOMAIN); ?>">
                <table class="widefat">
                    <thead><tr><th scope="col"><?php echo esc_html__('Scope', WordPressCacheSettings::TEXT_DOMAIN); ?></th>
                        <?php foreach ($events as $label) :
                            ?><th scope="col"><?php echo esc_html($label); ?></th><?php
                        endforeach; ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach (['home' => __('Homepage', WordPressCacheSettings::TEXT_DOMAIN), 'page' => __('Changed page', WordPressCacheSettings::TEXT_DOMAIN), 'archive' => __('Archives / listings', WordPressCacheSettings::TEXT_DOMAIN)] as $scope => $scopeLabel) : ?>
                            <tr><th scope="row"><?php echo esc_html($scopeLabel); ?></th>
                                <?php foreach ($events as $event => $label) :
                                    $name = 'purge_' . $scope . '_' . $event; ?>
                                    <td><label><input type="hidden" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . $name); ?>" value="0" /><input type="checkbox" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . $name); ?>" value="1" <?php checked($this->settings->integer($name) !== 0); ?> /><span class="screen-reader-text"><?php echo esc_html($scopeLabel . ': ' . $label); ?></span></label></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </fieldset>
        <?php
    }

    private function renderTools(): void
    {
        ?>
        <fieldset>
            <legend><h3><?php echo esc_html__('Preload and diagnostics', WordPressCacheSettings::TEXT_DOMAIN); ?></h3></legend>
            <label class="sympress-field">
                <span class="sympress-field__label"><?php echo esc_html__('Additional purge URLs', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                <textarea class="large-text code sympress-input" rows="3" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . 'additional_purge_urls'); ?>"><?php echo esc_textarea($this->settings->string('additional_purge_urls')); ?></textarea>
                <span><?php echo esc_html__('One same-origin URL or site path per line.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
            </label>
            <?php foreach (['prewarm_sitemap' => __('Discover preload URLs from a sitemap after full purges', WordPressCacheSettings::TEXT_DOMAIN), 'html_stamp' => __('Add a rendering timestamp, query count and duration to public HTML', WordPressCacheSettings::TEXT_DOMAIN)] as $name => $label) : ?>
                <label class="sympress-field"><input type="hidden" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . $name); ?>" value="0" /><span><input type="checkbox" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . $name); ?>" value="1" <?php checked($this->settings->integer($name) !== 0); ?> /> <?php echo esc_html($label); ?></span></label>
            <?php endforeach; ?>
            <label class="sympress-field">
                <span class="sympress-field__label"><?php echo esc_html__('Sitemap URL (empty uses the WordPress sitemap)', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                <input type="url" class="regular-text code sympress-input" name="<?php echo esc_attr(CompatibilitySettings::PREFIX . 'prewarm_sitemap_url'); ?>" value="<?php echo esc_attr($this->settings->string('prewarm_sitemap_url')); ?>" placeholder="<?php echo esc_attr(home_url('/wp-sitemap.xml')); ?>" />
            </label>
            <p><?php echo esc_html__('Enable prewarm in the Preload tab as well. Discovery accepts only this site, rejects external XML entities and redirects, and shares the existing preload URL limit.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
        </fieldset>
        <?php
        if ($this->maps === null || !is_multisite() || !current_user_can(Capabilities::PURGE_NETWORK)) {
            return;
        }
        ?>
        <h3><?php echo esc_html__('Multisite Nginx map', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
        <p><a href="<?php echo esc_url(add_query_arg(['page' => 'sympress-nginx-cache', 'tab' => 'network'], network_admin_url('settings.php'))); ?>"><?php echo esc_html__('Open the Multisite map in network administration', WordPressCacheSettings::TEXT_DOMAIN); ?></a></p>
        <?php
        $error = get_site_transient('sympress_nginx_cache_multisite_map_error');
        if (!is_string($error) || $error === '') {
            return;
        }

        echo '<p role="alert">' . esc_html($error) . '</p>';
    }
}
