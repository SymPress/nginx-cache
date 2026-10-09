<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Security\Capabilities;
use SymPress\NginxCache\Settings\ConfigurationCatalog;
use SymPress\NginxCache\Settings\NetworkSettings;
use SymPress\NginxCache\Settings\OptionSource;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class PermissionFields
{
    public function __construct(private OptionSource $options, private NetworkSettings $network)
    {
    }

    public function register(): void
    {
        register_setting('sympress_nginx_cache', Capabilities::ROLES_OPTION, ['type' => 'array', 'default' => [], 'sanitize_callback' => static fn (mixed $roles): array => array_values(array_intersect(array_filter(is_array($roles) ? $roles : [], is_string(...)), array_keys(wp_roles()->get_names())))]);
    }

    public function render(): void
    {
        $selected = $this->options->value(Capabilities::ROLES_OPTION, []);
        $selected = is_array($selected) ? $selected : [];
        ?>
        <fieldset class="sympress-form-field">
            <legend><strong><?php echo esc_html__('Purge permissions', WordPressCacheSettings::TEXT_DOMAIN); ?></strong></legend>
            <p><?php echo esc_html__('Selected roles may purge public URLs. Full purges and settings remain restricted to administrators.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
            <input type="hidden" name="<?php echo esc_attr(Capabilities::ROLES_OPTION); ?>[]" value="" />
            <?php foreach (wp_roles()->get_names() as $role => $name) : ?>
                <?php if ($role === 'administrator') { continue; } ?>
                <label><input type="checkbox" name="<?php echo esc_attr(Capabilities::ROLES_OPTION); ?>[]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, $selected, true)); ?> /> <?php echo esc_html(translate_user_role($name)); ?></label><br />
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    public function managedFields(): void
    {
        $screen = get_current_screen();
        if ($screen === null || $screen->id !== 'settings_page_sympress-nginx-cache' || !$this->network->active()) {
            return;
        }
        $managed = array_values(array_filter(array_keys(ConfigurationCatalog::defaults()), $this->network->managed(...)));
        ?>
        <script>
            (() => {
                const managed = <?php echo wp_json_encode($managed); ?>;
                const message = <?php echo wp_json_encode(__('Managed by the network', WordPressCacheSettings::TEXT_DOMAIN)); ?>;
                const root = document.querySelector('.sympress-cache-admin');
                if (!root) return;
                for (const field of root.querySelectorAll('input[name], select[name], textarea[name]')) {
                    if (!managed.includes(field.name.replace(/\[.*$/, ''))) continue;
                    field.disabled = true;
                    field.readOnly = true;
                    field.title = message;
                    const group = field.closest('.sympress-form-field') || field.parentElement;
                    if (group && !group.querySelector('[data-network-managed]')) {
                        const hint = document.createElement('p');
                        hint.className = 'description';
                        hint.dataset.networkManaged = 'true';
                        hint.textContent = message;
                        group.append(hint);
                    }
                }
            })();
        </script>
        <?php
    }
}
