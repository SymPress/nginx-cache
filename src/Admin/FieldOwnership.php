<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Settings\OptionSource;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class FieldOwnership
{
    public static function attributes(string $option): void
    {
        $option = explode('[', $option, 2)[0];
        if (!(new OptionSource())->locked($option)) {
            return;
        }

        echo ' disabled="disabled" title="' . esc_attr__('Managed by the network or a constant', WordPressCacheSettings::TEXT_DOMAIN) . '"';
    }
}
