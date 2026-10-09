<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class CacheMetricsPanel
{
    public function enqueueStyles(): void
    {
        wp_enqueue_style('sympress-nginx-cache-simple', plugins_url('Resources/assets/cache-simple.css', __DIR__ . '/../../nginx-cache.php'), [], (string) filemtime(__DIR__ . '/../../Resources/assets/cache-simple.css'));
        wp_enqueue_style(
            'sympress-nginx-cache-metrics',
            plugins_url('Resources/assets/cache-metrics.css', __DIR__ . '/../../nginx-cache.php'),
            [],
            (string) filemtime(__DIR__ . '/../../Resources/assets/cache-metrics.css'),
        );
    }

    /** @param array<string, mixed> $metrics */
    public function render(array $metrics): void
    {
        $requests = max(0, (int) ($metrics['requests'] ?? 0));
        $hits = min($requests, max(0, (int) ($metrics['hits'] ?? 0)));
        $hasData = $requests > 0 && is_numeric($metrics['hit_rate'] ?? null);
        $hitRate = $hasData ? max(0.0, min(100.0, (float) $metrics['hit_rate'])) : null;
        $smallSample = $hasData && !empty($metrics['small_sample']);
        $sampled = !empty($metrics['sampled']);
        $measuredAt = (int) ($metrics['measured_at'] ?? time());
        $windowMinutes = max(1, (int) ceil((int) ($metrics['window_seconds'] ?? 3600) / 60));
        $states = is_array($metrics['states'] ?? null) ? $metrics['states'] : [];
        $emptyDescription = match ($metrics['log_state'] ?? 'unavailable') {
            'no_requests' => __('In diesem Messfenster wurden keine cachefähigen Anfragen erfasst.', WordPressCacheSettings::TEXT_DOMAIN),
            'missing' => __('Das Nginx-Messprotokoll fehlt. Prüfe die Messkonfiguration unter Tools.', WordPressCacheSettings::TEXT_DOMAIN),
            'unreadable' => __('Das Nginx-Messprotokoll ist nicht lesbar. Prüfe Pfad und Zugriffsrechte unter Tools.', WordPressCacheSettings::TEXT_DOMAIN),
            default => __('Keine Messdaten verfügbar. Prüfe die Messkonfiguration unter Tools.', WordPressCacheSettings::TEXT_DOMAIN),
        };
        ?>
        <section class="sympress-cache-performance" aria-labelledby="sympress-cache-performance-title">
            <div class="sympress-performance-heading">
                <h2 id="sympress-cache-performance-title"><?php echo esc_html__('Cache-Trefferquote', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                <span class="sympress-performance-window"><?php echo esc_html(sprintf(__('Letzte %s Minuten', WordPressCacheSettings::TEXT_DOMAIN), number_format_i18n($windowMinutes))); ?></span>
            </div>
            <div class="sympress-performance-result">
                <strong class="sympress-performance-rate" data-sympress-hit-rate><?php echo esc_html($hitRate !== null ? number_format_i18n($hitRate, 1) . ' %' : '—'); ?></strong>
                <p><?php echo esc_html($hasData ? sprintf(__('%1$s von %2$s Anfragen aus Cache', WordPressCacheSettings::TEXT_DOMAIN), number_format_i18n($hits), number_format_i18n($requests)) : (($metrics['log_state'] ?? '') === 'no_requests' ? __('Keine Anfragen im Messfenster', WordPressCacheSettings::TEXT_DOMAIN) : __('Keine Messdaten verfügbar', WordPressCacheSettings::TEXT_DOMAIN))); ?></p>
            </div>
            <?php if ($hitRate !== null) : ?>
                <div class="sympress-performance-bar" aria-hidden="true">
                    <span class="sympress-performance-bar__hit" style="width: <?php echo esc_attr((string) $hitRate); ?>%"></span>
                    <span class="sympress-performance-bar__fresh" style="width: <?php echo esc_attr((string) (100 - $hitRate)); ?>%"></span>
                </div>
                <div class="sympress-performance-totals">
                    <span><i class="sympress-performance-dot" aria-hidden="true"></i><strong data-sympress-cache-hits><?php echo esc_html(number_format_i18n($hits)); ?></strong> <?php echo esc_html__('aus Cache', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                    <span><i class="sympress-performance-dot sympress-performance-dot--fresh" aria-hidden="true"></i><strong data-sympress-cache-fresh><?php echo esc_html(number_format_i18n($requests - $hits)); ?></strong> <?php echo esc_html__('neu angefragt', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                </div>
            <?php else : ?>
                <p class="sympress-performance-empty"><?php echo esc_html($emptyDescription); ?></p>
            <?php endif; ?>
            <div class="sympress-performance-meta">
                <?php if ($hasData) : ?>
                    <span class="<?php echo esc_attr($smallSample ? 'sympress-performance-note' : 'sympress-performance-count'); ?>"><?php echo esc_html(sprintf($smallSample ? __('Kleine Datenbasis · %s Anfragen', WordPressCacheSettings::TEXT_DOMAIN) : __('%s Anfragen', WordPressCacheSettings::TEXT_DOMAIN), number_format_i18n($requests))); ?></span>
                <?php endif; ?>
                <?php if ($sampled) : ?>
                    <span class="sympress-performance-note"><?php echo esc_html__('Begrenzte Stichprobe', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                <?php endif; ?>
                <span><?php echo esc_html__('Stand', WordPressCacheSettings::TEXT_DOMAIN); ?> <time datetime="<?php echo esc_attr((string) wp_date('c', $measuredAt)); ?>"><?php echo esc_html((string) wp_date('H:i', $measuredAt)); ?></time></span>
            </div>
            <details class="sympress-performance-details" data-sympress-metric-details>
                <summary><?php echo esc_html__('Messdetails & Zählweise', WordPressCacheSettings::TEXT_DOMAIN); ?></summary>
                <?php if ($hasData) : ?>
                    <dl class="sympress-performance-states">
                        <?php foreach (['HIT', 'MISS', 'EXPIRED', 'STALE', 'UPDATING', 'REVALIDATED'] as $state) : ?>
                            <div><dt><?php echo esc_html($state); ?></dt><dd><?php echo esc_html(number_format_i18n(max(0, (int) ($states[$state] ?? 0)))); ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
                <p><?php echo esc_html__('HIT, STALE, UPDATING und REVALIDATED zählen als Cache-Treffer. MISS und EXPIRED benötigen eine neue Antwort.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <p><?php echo esc_html__('BYPASS, Admin- und angemeldete Anfragen sind ausgeschlossen. Prewarm-Abrufe zählen mit.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <?php if ($smallSample) : ?>
                    <p><?php echo esc_html__('Unter 100 Anfragen: Ein einzelner MISS verändert die Quote deutlich.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <?php endif; ?>
                <?php if ($sampled) : ?>
                    <p><?php echo esc_html__('Die Quote basiert auf einem begrenzten Ausschnitt des Messprotokolls. Weitere Anfragen im Messfenster können fehlen.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                <?php endif; ?>
                <p><?php echo esc_html(sprintf(__('Aktualisierung beim Neuladen · Messfenster: letzte %s Minuten.', WordPressCacheSettings::TEXT_DOMAIN), number_format_i18n($windowMinutes))); ?></p>
            </details>
        </section>
        <?php
    }
}
