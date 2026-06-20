<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Hook;

use SymPress\NginxCache\Settings\WordPressCacheSettings;

final class HeartbeatControlSubscriber
{
    public function __construct(
        private readonly WordPressCacheSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function configure(array $settings): array
    {
        if ($this->settings->heartbeatMode() !== 'reduce') {
            return $settings;
        }

        $current = isset($settings['interval']) ? (int) $settings['interval'] : 15;
        $settings['interval'] = max($current, $this->settings->heartbeatInterval());

        return $settings;
    }

    public function maybeDisable(): void
    {
        if ($this->settings->heartbeatMode() !== 'disable' || !function_exists('wp_deregister_script')) {
            return;
        }

        if ($this->isEditorScreen()) {
            return;
        }

        wp_deregister_script('heartbeat');
    }

    private function isEditorScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        if (!is_object($screen) || !property_exists($screen, 'base')) {
            return false;
        }

        return in_array((string) $screen->base, ['post', 'post-new'], true);
    }
}
