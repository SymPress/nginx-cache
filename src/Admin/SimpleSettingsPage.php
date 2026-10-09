<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Settings\CachePresets;
use SymPress\NginxCache\Settings\OptionSource;
use SymPress\NginxCache\Settings\UiMode;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\CacheStatus;

final readonly class SimpleSettingsPage
{
    public function __construct(private WordPressCacheSettings $settings, private UiMode $mode, private CachePresets $presets, private OptionSource $options)
    {
    }

    /**
     * @param array<string, mixed> $diagnostics
     * @param array<string, string> $actions
     */
    public function render(CacheStatus $status, array $diagnostics, string $config, string $version, array $actions): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted preset preview.
        $candidate = isset($_GET['preset-preview']) && is_string($_GET['preset-preview']) ? sanitize_key(wp_unslash($_GET['preset-preview'])) : '';
        $preview = in_array($candidate, ['small', 'standard', 'large'], true) ? $candidate : null;
        $metrics = is_array($diagnostics['metrics'] ?? null) ? $diagnostics['metrics'] : [];
        $worker = is_array($diagnostics['worker'] ?? null) ? $diagnostics['worker'] : [];
        $tagStats = is_array($diagnostics['tag_index'] ?? null) ? $diagnostics['tag_index'] : [];
        ?>
        <header class="sympress-product-bar">
            <div class="sympress-product-brand"><span class="sympress-product-logo" aria-hidden="true">N</span><h1>Nginx Cache</h1><span class="sympress-version"><?php echo esc_html('v' . $version); ?></span></div>
            <div class="sympress-product-actions"><a class="button" href="<?php echo esc_url($this->mode->toggleUrl()); ?>"><?php echo esc_html__('Erweiterte Einstellungen', WordPressCacheSettings::TEXT_DOMAIN); ?></a></div>
        </header>
        <main class="sympress-simple-content">
            <?php settings_errors('sympress_nginx_cache'); ?>
            
        <?php if (!empty($worker['warning'])) : ?>
                <p class="sympress-simple-warning" role="status"><?php echo esc_html__('Der Cache-Worker wurde seit mehr als fünf Minuten nicht gesehen. Prüfe den Worker oder den System-Cron.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
            
        <?php endif; ?>

            <div class="sympress-cache-overview">
                <?php (new CacheMetricsPanel())->render($metrics); ?>
                <dl class="sympress-simple-stats">
                    
        <?php foreach ([__('Cache-Dateien', WordPressCacheSettings::TEXT_DOMAIN) => $status->error === null && $status->exists ? $status->files . ($status->scanComplete ? '' : '+') : '—', __('Cache-Größe', WordPressCacheSettings::TEXT_DOMAIN) => $status->error === null && $status->exists ? $status->formattedSize() : '—', __('Wartende Anfragen', WordPressCacheSettings::TEXT_DOMAIN) => $diagnostics['queue']['pending'] ?? 0, __('Tag-Index', WordPressCacheSettings::TEXT_DOMAIN) => sprintf(__('%1$d Tags · %2$d Zeilen', WordPressCacheSettings::TEXT_DOMAIN), (int) ($tagStats['tags'] ?? 0), (int) ($tagStats['rows'] ?? 0))] as $label => $value) : ?>
                        <div><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html((string) $value); ?></dd></div>
                    
        <?php endforeach; ?>

                </dl>
            </div>
            <div class="sympress-quick-actions">
                
        <?php foreach ($actions as $label => $url) : ?>
                    <a class="button <?php echo array_key_first($actions) === $label ? 'button-primary' : ''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                
        <?php endforeach; ?>

            </div>
            <section class="sympress-card sympress-simple-settings" aria-labelledby="sympress-simple-title">
                <h2 id="sympress-simple-title"><?php echo esc_html__('Cache einrichten', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                <p><?php echo esc_html__('Pfad und automatische Aktualisierung für diese Website. Weitere Optionen findest du in den erweiterten Einstellungen.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php $this->formFields('save'); ?>
                    <div class="sympress-simple-field"><label for="sympress-simple-path"><?php echo esc_html__('Cache-Pfad', WordPressCacheSettings::TEXT_DOMAIN); ?></label><input id="sympress-simple-path" class="code" type="text" name="<?php echo esc_attr(WordPressCacheSettings::OPTION_PATH); ?>" value="<?php echo esc_attr($this->settings->cachePath()); ?>" <?php disabled($this->options->locked(WordPressCacheSettings::OPTION_PATH)); ?> aria-describedby="sympress-simple-path-help" /><p id="sympress-simple-path-help" class="description"><?php echo esc_html($this->options->locked(WordPressCacheSettings::OPTION_PATH) ? __('Durch Netzwerk oder Konstante vorgegeben.', WordPressCacheSettings::TEXT_DOMAIN) : __('Verzeichnis des Nginx-Caches. Ein leeres Verzeichnis bestätigt noch keine aktive Cache-Nutzung.', WordPressCacheSettings::TEXT_DOMAIN)); ?></p></div>
                    
        <?php foreach ([WordPressCacheSettings::OPTION_AUTO_PURGE => [__('Automatisch leeren', WordPressCacheSettings::TEXT_DOMAIN), $this->settings->autoPurgeEnabled()], WordPressCacheSettings::OPTION_PREWARM_ENABLED => [__('Cache vorwärmen', WordPressCacheSettings::TEXT_DOMAIN), $this->settings->prewarmEnabled()]] as $option => [$label, $checked]) : ?>
                        <div class="sympress-simple-field"><input type="hidden" name="<?php echo esc_attr($option); ?>" value="0" <?php disabled($this->options->locked($option)); ?> /><label><input type="checkbox" name="<?php echo esc_attr($option); ?>" value="1" <?php checked($checked); ?> <?php disabled($this->options->locked($option)); ?> /> <?php echo esc_html($label); ?></label>
            <?php if ($this->options->locked($option)) : ?>
<p class="description"><?php echo esc_html__('Durch Netzwerk oder Konstante vorgegeben.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
            <?php endif; ?>
</div>
                    
        <?php endforeach; ?>

                    <?php submit_button(__('Änderungen speichern', WordPressCacheSettings::TEXT_DOMAIN), 'primary', 'submit', false); ?>
                </form>
            </section>
            <section class="sympress-card" aria-labelledby="sympress-presets-title">
                <h2 id="sympress-presets-title"><?php echo esc_html__('Passende Voreinstellungen', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                <p><?php echo esc_html__('Sieh dir die Änderungen an, bevor du ein Preset anwendest. Nginx-Limits und Cache-Laufzeiten bleiben unverändert.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <div class="sympress-preset-list">
                    
        <?php foreach (['small' => [__('Kleine Website', WordPressCacheSettings::TEXT_DOMAIN), __('Direkt leeren, Startseite und Sitemap vorwärmen. Ohne Tag-Index.', WordPressCacheSettings::TEXT_DOMAIN)], 'standard' => [__('Standard', WordPressCacheSettings::TEXT_DOMAIN), __('Anfragen fünf Sekunden bündeln und den Tag-Index nutzen.', WordPressCacheSettings::TEXT_DOMAIN)], 'large' => [__('Große Website', WordPressCacheSettings::TEXT_DOMAIN), __('Anfragen 15 Sekunden bündeln und nur betroffene URLs vorwärmen. Für den Betrieb mit Worker.', WordPressCacheSettings::TEXT_DOMAIN)]] as $id => [$title, $description]) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($description); ?></p></div><?php $this->formFields('preview', $id); ?><button class="button" type="submit"><?php echo esc_html__('Vorschau', WordPressCacheSettings::TEXT_DOMAIN); ?><span class="screen-reader-text">: <?php echo esc_html($title); ?></span></button></form>
                    
        <?php endforeach; ?>

                </div>
                <?php if ($preview !== null) {
                    $this->preview($preview);
                } ?>
            </section>
            <details class="sympress-card sympress-simple-config"><summary class="button"><?php echo esc_html__('Nginx-Konfiguration anzeigen', WordPressCacheSettings::TEXT_DOMAIN); ?></summary><label class="screen-reader-text" for="sympress-simple-config"><?php echo esc_html__('Generierte Nginx-Konfiguration', WordPressCacheSettings::TEXT_DOMAIN); ?></label><textarea id="sympress-simple-config" class="large-text code" rows="16" readonly><?php echo esc_textarea($config); ?></textarea><p><?php echo esc_html__('Die Vorschau muss auf dem Server eingerichtet werden. Änderungen im Plugin laden Nginx nicht neu.', WordPressCacheSettings::TEXT_DOMAIN); ?></p></details>
        </main>
        <?php
    }

    private function formFields(string $operation, string $preset = ''): void
    {
        wp_nonce_field(SimpleSettingsActions::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(SimpleSettingsActions::ACTION) . '" /><input type="hidden" name="operation" value="' . esc_attr($operation) . '" /><input type="hidden" name="preset" value="' . esc_attr($preset) . '" />';
    }

    private function displayValue(string $option, mixed $value): string
    {
        $key = str_replace('sympress_nginx_cache_', '', $option);
        if (in_array($key, ['selective_purge', 'queue_enabled', 'prewarm_enabled', 'prewarm_sitemap', 'prewarm_affected_only', 'tag_index_enabled'], true)) {
            return (int) $value !== 0 ? __('Aktiv', WordPressCacheSettings::TEXT_DOMAIN) : __('Inaktiv', WordPressCacheSettings::TEXT_DOMAIN);
        }
        return match ($value) {
            'local_files' => __('Lokale Cache-Dateien', WordPressCacheSettings::TEXT_DOMAIN),
            '' => __('Keine', WordPressCacheSettings::TEXT_DOMAIN),
            default => (string) $value,
        };
    }

    private function preview(string $preset): void
    {
        $labels = ['purge_backend' => __('Purge-Backend', WordPressCacheSettings::TEXT_DOMAIN), 'full_purge_mode' => __('Vollständiges Leeren', WordPressCacheSettings::TEXT_DOMAIN), 'selective_purge' => __('Selektives Leeren', WordPressCacheSettings::TEXT_DOMAIN), 'queue_enabled' => __('Queue', WordPressCacheSettings::TEXT_DOMAIN), 'debounce_seconds' => __('Bündeln (Sekunden)', WordPressCacheSettings::TEXT_DOMAIN), 'prewarm_enabled' => __('Vorwärmen', WordPressCacheSettings::TEXT_DOMAIN), 'prewarm_urls' => __('Feste Prewarm-URLs', WordPressCacheSettings::TEXT_DOMAIN), 'prewarm_sitemap' => __('Sitemap vorwärmen', WordPressCacheSettings::TEXT_DOMAIN), 'prewarm_affected_only' => __('Nur betroffene URLs vorwärmen', WordPressCacheSettings::TEXT_DOMAIN), 'tag_index_enabled' => __('Tag-Index', WordPressCacheSettings::TEXT_DOMAIN), 'tag_urls_per_tag' => __('URLs pro Tag', WordPressCacheSettings::TEXT_DOMAIN), 'tag_max_tags' => __('Maximale Tags', WordPressCacheSettings::TEXT_DOMAIN)];
        ?>
        <div class="sympress-preset-preview" id="sympress-preset-preview"><h3><?php echo esc_html__('Änderungsvorschau', WordPressCacheSettings::TEXT_DOMAIN); ?></h3><div class="sympress-table-scroll"><table class="widefat striped"><thead><tr><th><?php echo esc_html__('Einstellung', WordPressCacheSettings::TEXT_DOMAIN); ?></th><th><?php echo esc_html__('Aktuell', WordPressCacheSettings::TEXT_DOMAIN); ?></th><th><?php echo esc_html__('Nach Anwendung', WordPressCacheSettings::TEXT_DOMAIN); ?></th></tr></thead><tbody>
            
        <?php foreach ($this->presets->preview($preset) as $row) : ?>
<tr><th scope="row"><?php echo esc_html($labels[str_replace('sympress_nginx_cache_', '', $row['option'])] ?? $row['option']); ?></th><td data-label="<?php echo esc_attr__('Aktuell', WordPressCacheSettings::TEXT_DOMAIN); ?>"><?php echo esc_html($this->displayValue($row['option'], $row['current'])); ?></td><td data-label="<?php echo esc_attr__('Nach Anwendung', WordPressCacheSettings::TEXT_DOMAIN); ?>"><?php echo esc_html($row['managed'] ? __('Vorgegeben · bleibt unverändert', WordPressCacheSettings::TEXT_DOMAIN) : $this->displayValue($row['option'], $row['value'])); ?></td></tr>
        <?php endforeach; ?>

        </tbody></table></div>
        
        <?php if ($preset === 'large') : ?>
<p class="sympress-simple-warning"><?php echo esc_html__('Richte einen regelmäßigen Cache-Worker ein. Ohne Worker oder zuverlässigen Cron können Anfragen in der Queue liegen bleiben.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $this->formFields('apply', $preset); ?><button type="submit" class="button button-primary"><?php echo esc_html__('Preset anwenden', WordPressCacheSettings::TEXT_DOMAIN); ?></button> <a class="button" href="<?php echo esc_url(admin_url('tools.php?page=sympress-nginx-cache')); ?>"><?php echo esc_html__('Abbrechen', WordPressCacheSettings::TEXT_DOMAIN); ?></a></form></div>
        <?php
    }
}
