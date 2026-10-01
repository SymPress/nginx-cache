<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class SecretField
{
    public static function render(string $option): void
    {
        ?>
        <input name="<?php echo esc_attr($option); ?>" type="password" class="regular-text code sympress-input" value="" autocomplete="new-password" />
        <span><input type="checkbox" name="<?php echo esc_attr($option . '_clear'); ?>" value="1" /> <?php echo esc_html__('Clear stored secret (leave password blank to preserve)', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
        <?php
    }
}
