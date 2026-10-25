<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Inspection\PageCoverageInspector;
use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class PageCoveragePanel
{
    /** @param array<string, mixed> $coverage */
    public function render(array $coverage): void
    {
        $count = static fn (string $key): int => max(0, (int) ($coverage[$key] ?? 0));
        $available = ($coverage['state'] ?? 'unavailable') === 'ok';
        [$total, $checked, $cached, $expired, $missing, $excluded, $unknown] = array_map($count, ['total', 'checked', 'cached', 'expired', 'missing', 'excluded', 'unknown']);
        // Excluded and undeterminable pages are reported separately, never as missing coverage.
        $cacheable = $cached + $expired + $missing;
        $rate = $available && $cacheable > 0 ? 100.0 * $cached / $cacheable : null;
        $limited = $available && $checked < $total;
        $measuredAt = (int) ($coverage['measured_at'] ?? time());
        ?>
        <section class="sympress-cache-performance" aria-labelledby="sympress-cache-coverage-title" data-sympress-page-coverage>
            <div class="sympress-performance-heading">
                <h2 id="sympress-cache-coverage-title"><?php echo esc_html__('Cache-Abdeckung', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                <span class="sympress-performance-window"><?php echo esc_html__('Öffentliche Seiten', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
            </div>
            <div class="sympress-performance-result">
                <strong class="sympress-performance-rate" data-sympress-coverage-rate><?php echo esc_html($rate !== null ? number_format_i18n($rate, 1) . ' %' : '—'); ?></strong>
                <p><?php echo esc_html($available ? sprintf(__('%1$s von %2$s cachefähigen Seiten im Cache', WordPressCacheSettings::TEXT_DOMAIN), number_format_i18n($cached), number_format_i18n($cacheable)) : __('Cache-Abdeckung nicht ermittelbar', WordPressCacheSettings::TEXT_DOMAIN)); ?></p>
            </div>
            <?php if ($rate !== null) : ?>
                <div class="sympress-performance-bar" aria-hidden="true">
                    <span class="sympress-performance-bar__hit" style="width: <?php echo esc_attr((string) $rate); ?>%"></span>
                    <span class="sympress-performance-bar__fresh" style="width: <?php echo esc_attr((string) (100 - $rate)); ?>%"></span>
                </div>
            <?php endif; ?>
            <?php if ($available) : ?>
                <dl class="sympress-performance-states" data-sympress-coverage-counts>
                    <?php foreach ([__('Öffentliche Seiten', WordPressCacheSettings::TEXT_DOMAIN) => $total, __('Im Cache', WordPressCacheSettings::TEXT_DOMAIN) => $cached, __('Abgelaufen', WordPressCacheSettings::TEXT_DOMAIN) => $expired, __('Nicht im Cache', WordPressCacheSettings::TEXT_DOMAIN) => $missing, __('Nicht cachebar (Regel)', WordPressCacheSettings::TEXT_DOMAIN) => $excluded, __('Unbekannt', WordPressCacheSettings::TEXT_DOMAIN) => $unknown] as $label => $value) : ?>
                        <div><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html(number_format_i18n($value)); ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php else : ?>
                <p class="sympress-performance-empty"><?php echo esc_html__('Kein lesbares lokales Cache-Verzeichnis oder ein alternatives Purge-Backend. Der Cache-Zustand der Seiten ist unbekannt.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
            <?php endif; ?>
            <div class="sympress-performance-meta">
                <?php if ($limited) : ?>
                    <span class="sympress-performance-note"><?php echo esc_html(sprintf(__('Begrenzte Ermittlung · %1$s von %2$s Seiten geprüft', WordPressCacheSettings::TEXT_DOMAIN), number_format_i18n($checked), number_format_i18n($total))); ?></span>
                <?php endif; ?>
                <span><?php echo esc_html__('Stand', WordPressCacheSettings::TEXT_DOMAIN); ?> <time datetime="<?php echo esc_attr((string) wp_date('c', $measuredAt)); ?>"><?php echo esc_html((string) wp_date('H:i', $measuredAt)); ?></time></span>
            </div>
            <details class="sympress-performance-details">
                <summary><?php echo esc_html__('Messbereich & Zählweise', WordPressCacheSettings::TEXT_DOMAIN); ?></summary>
                <p><?php echo esc_html__('Gezählt wird je URL: Startseite und veröffentlichte Inhalte öffentlicher Beitragstypen. Jede Sprachversion ist eine eigene URL. Archive, Seitennummerierung und Feeds sind nicht enthalten.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <p><?php echo esc_html__('Mehrere Cache-Dateien oder Schlüsselvarianten derselben URL zählen als eine Seite.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <p><?php echo esc_html__('Abgelaufen: Cache-Datei vorhanden, Gültigkeit überschritten. Nginx erneuert sie beim nächsten Abruf. Nicht cachebar: Die URL fällt absichtlich unter eine Bypass-Regel und zählt nicht als fehlende Abdeckung. Unbekannt: Cache-Datei vorhanden, aber nicht auswertbar.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <p><?php echo esc_html(sprintf(__('Beim Neuladen werden die Cache-Dateien auf der Platte geprüft, ohne HTTP-Abrufe, Purge oder Vorwärmung. Bis zu %s zuletzt geänderte Inhalte werden geprüft.', WordPressCacheSettings::TEXT_DOMAIN), number_format_i18n(PageCoverageInspector::LIMIT))); ?></p>
            </details>
        </section>
        <?php
    }
}
