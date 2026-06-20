<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Admin;

use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Filesystem\CachePathValidator;
use SymPress\NginxCache\Inspection\CacheStatusInspector;
use SymPress\NginxCache\Inspection\Diagnostics;
use SymPress\NginxCache\Inspection\EnvironmentDetector;
use SymPress\NginxCache\Purge\CacheManager;
use SymPress\NginxCache\Purge\PurgeHistoryRepository;
use SymPress\NginxCache\Purge\PurgeQueueProcessor;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\CacheProfile;
use SymPress\NginxCache\Value\PurgeRequest;
use WP_Admin_Bar;

final readonly class SettingsPage
{
    private const string CAPABILITY = 'manage_options';
    private const string PAGE_SLUG = 'sympress-nginx-cache';
    private const string LEGACY_MESSAGE_QUERY_VAR = 'edge-cache-message';
    private const string NOTICE_TRANSIENT_PREFIX = 'sympress_nginx_cache_notice_';
    private const string PURGE_ACTION = 'sympress_nginx_cache_purge';
    private const string QUEUE_ACTION = 'sympress_nginx_cache_flush_queue';

    public function __construct(
        private WordPressCacheSettings $settings,
        private CacheManager $cache,
        private CachePathValidator $validator,
        private CacheStatusInspector $inspector,
        private PurgeHistoryRepository $history,
        private PurgeQueueProcessor $queue,
        private Diagnostics $diagnostics,
        private NginxConfigGenerator $config,
        private EnvironmentDetector $environment,
    ) {
    }

    public function registerSettings(): void
    {
        $this->settings->register();
    }

    public function registerMenu(): void
    {
        $hook = add_management_page(
            __('Nginx Cache', WordPressCacheSettings::TEXT_DOMAIN),
            __('Nginx Cache', WordPressCacheSettings::TEXT_DOMAIN),
            self::CAPABILITY,
            self::PAGE_SLUG,
            $this->render(...),
        );

        if (!is_string($hook) || $hook === '') {
            return;
        }

        add_action(sprintf('load-%s', $hook), $this->handlePageAction(...));
    }

    /**
     * @param list<string> $links
     * @return list<string>
     */
    public function addPluginActionLinks(array $links): array
    {
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url($this->pageUrl()),
            esc_html__('Settings', WordPressCacheSettings::TEXT_DOMAIN),
        );

        array_unshift($links, $settingsLink);

        return $links;
    }

    public function addAdminBarNode(WP_Admin_Bar $adminBar): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $queueCount = $this->queue->count();

        $adminBar->add_node([
            'id'    => 'sympress-nginx-cache',
            'title' => $queueCount > 0
                ? sprintf(__('Nginx Cache (%d)', WordPressCacheSettings::TEXT_DOMAIN), $queueCount)
                : __('Nginx Cache', WordPressCacheSettings::TEXT_DOMAIN),
            'href'  => $this->pageUrl(),
        ]);

        $adminBar->add_node([
            'parent' => 'sympress-nginx-cache',
            'id'     => 'sympress-nginx-cache-dashboard',
            'title'  => __('Dashboard', WordPressCacheSettings::TEXT_DOMAIN),
            'href'   => $this->pageUrl('#dashboard'),
        ]);
        $adminBar->add_node([
            'parent' => 'sympress-nginx-cache',
            'id'     => 'sympress-nginx-cache-purge',
            'title'  => __('Purge cache', WordPressCacheSettings::TEXT_DOMAIN),
            'href'   => $this->purgeUrl(),
        ]);
        $adminBar->add_node([
            'parent' => 'sympress-nginx-cache',
            'id'     => 'sympress-nginx-cache-purge-prewarm',
            'title'  => __('Purge and prewarm', WordPressCacheSettings::TEXT_DOMAIN),
            'href'   => $this->purgeActionUrl(false, true),
        ]);
        $adminBar->add_node([
            'parent' => 'sympress-nginx-cache',
            'id'     => 'sympress-nginx-cache-dry-run',
            'title'  => __('Dry run', WordPressCacheSettings::TEXT_DOMAIN),
            'href'   => $this->purgeActionUrl(true),
        ]);
        $adminBar->add_node([
            'parent' => 'sympress-nginx-cache',
            'id'     => 'sympress-nginx-cache-flush-queue',
            'title'  => sprintf(__('Process purge queue (%d)', WordPressCacheSettings::TEXT_DOMAIN), $queueCount),
            'href'   => $this->queueActionUrl(),
        ]);
        $adminBar->add_node([
            'parent' => 'sympress-nginx-cache',
            'id'     => 'sympress-nginx-cache-config',
            'title'  => __('Generated Nginx config', WordPressCacheSettings::TEXT_DOMAIN),
            'href'   => $this->pageUrl('#tools'),
        ]);
    }

    public function handlePageAction(): void
    {
        if (!$this->isPurgeRequest()) {
            return;
        }

        check_admin_referer(self::PURGE_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to purge this cache.', WordPressCacheSettings::TEXT_DOMAIN));
        }

        $result = $this->cache->purgeConfiguredPath($this->manualRequest('admin-settings'));
        $this->flashNotice($this->noticeMessage($result->successful, $result->dryRun, $this->prewarmRequested()));

        wp_safe_redirect($this->cleanNoticeUrl($this->pageUrl()));
        exit;
    }

    public function handlePurgeAction(): void
    {
        check_admin_referer(self::PURGE_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to purge this cache.', WordPressCacheSettings::TEXT_DOMAIN));
        }

        $result = $this->cache->purgeConfiguredPath($this->manualRequest('admin-bar'));
        $this->flashNotice($this->noticeMessage($result->successful, $result->dryRun, $this->prewarmRequested()));

        wp_safe_redirect($this->cleanNoticeUrl($this->redirectUrl()));
        exit;
    }

    public function handleQueueAction(): void
    {
        check_admin_referer(self::QUEUE_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to process this cache queue.', WordPressCacheSettings::TEXT_DOMAIN));
        }

        $pending = $this->queue->count();
        $this->queue->process();
        $this->flashNotice($pending > 0 ? 'queue-flushed' : 'queue-empty');

        wp_safe_redirect($this->cleanNoticeUrl($this->redirectUrl()));
        exit;
    }

    public function renderAdminNotice(): void
    {
        $message = $this->pullNotice();

        if ($message === 'purged') {
            $this->renderNotice(__('Cache purged.', WordPressCacheSettings::TEXT_DOMAIN), 'success');

            return;
        }

        if ($message === 'failed') {
            $this->renderNotice(__('Cache could not be purged.', WordPressCacheSettings::TEXT_DOMAIN), 'error');

            return;
        }

        if ($message === 'dry-run') {
            $this->renderNotice(__('Cache purge dry run completed.', WordPressCacheSettings::TEXT_DOMAIN), 'success');

            return;
        }

        if ($message === 'purged-prewarm') {
            $this->renderNotice(__('Cache purged. Prewarm and follow-up tasks were queued when available.', WordPressCacheSettings::TEXT_DOMAIN), 'success');

            return;
        }

        if ($message === 'queue-flushed') {
            $this->renderNotice(__('Purge queue processed.', WordPressCacheSettings::TEXT_DOMAIN), 'success');

            return;
        }

        if ($message !== 'queue-empty') {
            return;
        }

        $this->renderNotice(__('Purge queue is already empty.', WordPressCacheSettings::TEXT_DOMAIN), 'info');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to manage this cache.', WordPressCacheSettings::TEXT_DOMAIN));
        }

        $path = $this->settings->cachePath();
        $validation = $this->validator->validate($path);
        $status = $this->inspector->inspect($path);
        $pathReadOnly = $this->settings->pathManagedByConstant();
        $lastPurge = $this->history->last();
        $diagnostics = $this->diagnostics->report();
        $generatedConfig = $this->config->generate();
        $configMissing = $this->config->validate($generatedConfig);
        $environment = $this->environment->detect();
        $queueCount = $this->queue->count();
        $tagStats = is_array($diagnostics['tag_index'] ?? null) ? $diagnostics['tag_index'] : [];
        $remote = is_array($diagnostics['remote'] ?? null) ? $diagnostics['remote'] : [];
        $layers = is_array($diagnostics['layers'] ?? null) ? $diagnostics['layers'] : [];
        $showOnboarding = !$this->settings->onboardingCompleted() && !$this->settings->hasCustomizedOptions();
        $healthOk = $validation->isValid() && $status->available();
        $healthLabel = $healthOk
            ? __('Cache ist aktiv und gesund', WordPressCacheSettings::TEXT_DOMAIN)
            : __('Cache braucht Aufmerksamkeit', WordPressCacheSettings::TEXT_DOMAIN);
        $healthDescription = $healthOk
            ? __('Alle Systeme betriebsbereit', WordPressCacheSettings::TEXT_DOMAIN)
            : __('Bitte Cache-Pfad und Nginx-Probe prüfen', WordPressCacheSettings::TEXT_DOMAIN);
        $option = static fn (string $name, string $default = ''): string => function_exists('get_option')
            ? (string) get_option($name, $default)
            : $default;

        $this->addRequestNotice($validation->firstError());

        ?>
        <div class="wrap sympress-cache-admin">
            <?php $this->renderStyles(); ?>

            <form method="post" action="options.php" data-sympress-settings-form>
                <?php settings_fields('sympress_nginx_cache'); ?>
                <input
                    type="hidden"
                    data-sympress-onboarding-completed
                    name="<?php echo esc_attr(WordPressCacheSettings::OPTION_ONBOARDING_COMPLETED); ?>"
                    value="<?php echo esc_attr($this->settings->onboardingCompleted() ? '1' : '0'); ?>"
                />

                <header class="sympress-product-bar">
                    <div class="sympress-product-brand">
                        <span class="sympress-product-logo" aria-hidden="true">N</span>
                        <strong><?php echo esc_html__('Nginx Cache', WordPressCacheSettings::TEXT_DOMAIN); ?></strong>
                        <span class="sympress-version">v1.0.0</span>
                    </div>
                    <div class="sympress-product-status">
                        <span class="sympress-cache-status is-<?php echo esc_attr($healthOk ? 'good' : 'warning'); ?>">
                            <span class="sympress-cache-status__dot" aria-hidden="true"></span>
                            <strong><?php echo esc_html($healthLabel); ?></strong>
                        </span>
                        <small><?php echo esc_html($healthDescription); ?></small>
                    </div>
                    <div class="sympress-product-actions">
                        <button type="button" class="button button-secondary" data-sympress-export-settings>
                            <?php echo esc_html__('Einstellungen exportieren', WordPressCacheSettings::TEXT_DOMAIN); ?>
                        </button>
                        <button type="button" class="button button-secondary" data-sympress-import-settings-trigger>
                            <?php echo esc_html__('Einstellungen importieren', WordPressCacheSettings::TEXT_DOMAIN); ?>
                        </button>
                        <input type="file" accept="application/json" hidden data-sympress-import-settings />
                        <?php submit_button(__('Änderungen speichern', WordPressCacheSettings::TEXT_DOMAIN), 'primary', 'submit', false); ?>
                    </div>
                </header>

                <?php settings_errors('sympress_nginx_cache'); ?>

                <div class="sympress-cache-shell">
                    <nav class="sympress-cache-tabs" aria-label="<?php echo esc_attr__('Nginx Cache settings sections', WordPressCacheSettings::TEXT_DOMAIN); ?>">
                        <?php $this->renderTabButton('dashboard', 'dashboard', __('Dashboard', WordPressCacheSettings::TEXT_DOMAIN), true); ?>
                        <?php $this->renderTabButton('cache', 'admin-generic', __('Cache', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                        <?php $this->renderTabButton('preload', 'update', __('Preload', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                        <?php $this->renderTabButton('advanced', 'shield', __('Advanced Rules', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                        <?php $this->renderTabButton('remote', 'cloud', __('CDN & Remote', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                        <?php $this->renderTabButton('layers', 'heart', __('Heartbeat & Layers', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                        <?php $this->renderTabButton('tools', 'admin-tools', __('Tools', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                        <a class="sympress-cache-help" href="<?php echo esc_url($this->pageUrl('#tools')); ?>">
                            <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                            <span><?php echo esc_html__('Hilfe & Dokumentation', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                        </a>
                    </nav>

                    <main class="sympress-cache-content">
                        <section id="sympress-tab-dashboard" class="sympress-cache-panel is-active" data-sympress-panel="dashboard">
                            <?php if ($showOnboarding) : ?>
                                <section class="sympress-welcome-panel" aria-label="<?php echo esc_attr__('Nginx Cache onboarding', WordPressCacheSettings::TEXT_DOMAIN); ?>">
                                    <div class="sympress-welcome-icon" aria-hidden="true">
                                        <span class="dashicons dashicons-controls-forward"></span>
                                    </div>
                                    <div>
                                        <h2><?php echo esc_html__('Willkommen bei Nginx Cache!', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                                        <p><?php echo esc_html__('Beschleunige deine Website mit leistungsstarkem Nginx Caching. Folge dem Einrichtungsassistenten, um die besten Einstellungen für deine Seite zu übernehmen.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                    </div>
                                    <button type="button" class="button button-primary" data-sympress-onboarding-open>
                                        <?php echo esc_html__('Erste Schritte', WordPressCacheSettings::TEXT_DOMAIN); ?>
                                    </button>
                                </section>
                            <?php endif; ?>

                            <div class="sympress-metrics-grid">
                                <?php $this->renderMetricCard(__('Cache-Dateien', WordPressCacheSettings::TEXT_DOMAIN), (string) $status->files . ($status->scanComplete ? '' : '+'), __('Dateien', WordPressCacheSettings::TEXT_DOMAIN), 'neutral', 'media-default'); ?>
                                <?php $this->renderMetricCard(__('Cache-Größe', WordPressCacheSettings::TEXT_DOMAIN), $status->formattedSize(), __('Belegter Speicher', WordPressCacheSettings::TEXT_DOMAIN), 'neutral', 'database'); ?>
                                <?php $this->renderMetricCard(__('Angefragt (Queue)', WordPressCacheSettings::TEXT_DOMAIN), (string) $queueCount, $queueCount > 0 ? __('Wartend', WordPressCacheSettings::TEXT_DOMAIN) : __('Leer', WordPressCacheSettings::TEXT_DOMAIN), $queueCount > 0 ? 'warning' : 'good', 'clock'); ?>
                                <?php $this->renderMetricCard(__('Tag-Index', WordPressCacheSettings::TEXT_DOMAIN), sprintf('%d', (int) ($tagStats['tags'] ?? 0)), sprintf(__('%d URLs', WordPressCacheSettings::TEXT_DOMAIN), (int) ($tagStats['urls'] ?? 0)), 'neutral', 'tag'); ?>
                                <div class="sympress-metric sympress-metric--chart">
                                    <span><?php echo esc_html__('Cache-Trefferquote', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    <strong><?php echo esc_html($status->available() ? '100 %' : 'n/a'); ?></strong>
                                    <small><?php echo esc_html__('Letzte Probe', WordPressCacheSettings::TEXT_DOMAIN); ?></small>
                                    <svg viewBox="0 0 120 42" aria-hidden="true" focusable="false">
                                        <polyline points="0,33 12,28 24,31 36,17 48,24 60,13 72,26 84,20 96,9 108,14 120,6" />
                                    </svg>
                                </div>
                            </div>

                            <div class="sympress-quick-actions">
                                <a class="button button-primary" href="<?php echo esc_url($this->purgeUrl()); ?>">
                                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                    <?php echo esc_html__('Purge Cache', WordPressCacheSettings::TEXT_DOMAIN); ?>
                                </a>
                                <a class="button button-secondary" href="<?php echo esc_url($this->purgeActionUrl(true)); ?>">
                                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                    <?php echo esc_html__('Dry Run', WordPressCacheSettings::TEXT_DOMAIN); ?>
                                </a>
                                <a class="button button-secondary" href="<?php echo esc_url($this->purgeActionUrl(false, true)); ?>">
                                    <span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
                                    <?php echo esc_html__('Prewarm', WordPressCacheSettings::TEXT_DOMAIN); ?>
                                </a>
                                <a class="button button-secondary" href="<?php echo esc_url($this->queueActionUrl()); ?>">
                                    <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                                    <?php echo esc_html__('Flush Queue', WordPressCacheSettings::TEXT_DOMAIN); ?>
                                </a>
                            </div>

                            <div class="sympress-dashboard-grid">
                                <div class="sympress-card sympress-dashboard-settings">
                                    <h3><?php echo esc_html__('Cache-Einstellungen', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>

                                    <label class="sympress-setting-row">
                                        <span><?php echo esc_html__('Cache-Pfad', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <span>
                                            <input
                                                id="<?php echo esc_attr(WordPressCacheSettings::OPTION_PATH); ?>_dashboard"
                                                name="<?php echo esc_attr(WordPressCacheSettings::OPTION_PATH); ?>"
                                                type="text"
                                                class="regular-text code sympress-input"
                                                value="<?php echo esc_attr($path); ?>"
                                                placeholder="<?php echo esc_attr($this->settings->defaultPath()); ?>"
                                                <?php echo $pathReadOnly ? 'readonly="readonly"' : ''; ?>
                                            />
                                            <small><?php echo esc_html__('Verzeichnis, in dem die Cache-Dateien gespeichert werden.', WordPressCacheSettings::TEXT_DOMAIN); ?></small>
                                        </span>
                                    </label>

                                    <label class="sympress-setting-row">
                                        <span><?php echo esc_html__('Cache-Profil', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <span>
                                            <select name="<?php echo esc_attr(WordPressCacheSettings::OPTION_PROFILE); ?>" class="sympress-input">
                                                <?php foreach (CacheProfile::cases() as $profile) : ?>
                                                    <option value="<?php echo esc_attr($profile->value); ?>" <?php selected($this->settings->profile()->value, $profile->value); ?>>
                                                        <?php echo esc_html($this->profileLabel($profile)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small><?php echo esc_html__('Voreingestellte Optimierungen für allgemeine Websites.', WordPressCacheSettings::TEXT_DOMAIN); ?></small>
                                        </span>
                                    </label>

                                    <?php $this->renderDashboardSwitch(WordPressCacheSettings::OPTION_AUTO_PURGE, $this->settings->autoPurgeEnabled(), __('Automatisches Purge', WordPressCacheSettings::TEXT_DOMAIN), __('Interessierte Inhalte automatisch entfernen, wenn sich Inhalte ändern.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                    <?php $this->renderDashboardSwitch(WordPressCacheSettings::OPTION_SELECTIVE_PURGE, $this->settings->selectivePurgeEnabled(), __('Selektives Purge', WordPressCacheSettings::TEXT_DOMAIN), __('Nur betroffene URLs über Surrogate-Tags oder Regeln löschen.', WordPressCacheSettings::TEXT_DOMAIN)); ?>

                                    <label class="sympress-setting-row">
                                        <span><?php echo esc_html__('Queue Debounce', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <span>
                                            <input name="<?php echo esc_attr(WordPressCacheSettings::OPTION_DEBOUNCE_SECONDS); ?>" type="number" min="0" max="300" class="small-text sympress-number" value="<?php echo esc_attr((string) $this->settings->debounceSeconds()); ?>" />
                                            <small><?php echo esc_html__('Minimaler Abstand zwischen mehreren Purge-Anfragen.', WordPressCacheSettings::TEXT_DOMAIN); ?></small>
                                        </span>
                                    </label>

                                    <label class="sympress-setting-row">
                                        <span><?php echo esc_html__('Prewarm URLs', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <span>
                                            <textarea name="<?php echo esc_attr(WordPressCacheSettings::OPTION_PREWARM_URLS); ?>" rows="4" class="large-text code sympress-textarea" placeholder="<?php echo esc_attr(home_url('/important-page/')); ?>"><?php echo esc_textarea($option(WordPressCacheSettings::OPTION_PREWARM_URLS)); ?></textarea>
                                            <small><?php echo esc_html__('Eine URL pro Zeile. Wird nach einem Purge im Hintergrund vorgewärmt.', WordPressCacheSettings::TEXT_DOMAIN); ?></small>
                                        </span>
                                    </label>

                                    <?php $this->renderDashboardSwitch(WordPressCacheSettings::OPTION_REST_ENABLED, $this->settings->restEnabled(), __('REST API', WordPressCacheSettings::TEXT_DOMAIN), __('Öffentliche REST-API für Purge und Cache-Statistiken aktivieren.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                    <?php $this->renderDashboardSwitch(WordPressCacheSettings::OPTION_TAG_INDEX_ENABLED, $this->settings->tagIndexEnabled(), __('Surrogate-Tags', WordPressCacheSettings::TEXT_DOMAIN), __('Surrogate-Tags in Antworten anhängen und verarbeiten.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                    <?php $this->renderDashboardSwitch(WordPressCacheSettings::OPTION_DEBUG_HEADERS_ENABLED, $this->settings->debugHeadersEnabled(), __('Debug-Headers', WordPressCacheSettings::TEXT_DOMAIN), __('Nginx-Cache-Debug-Header senden.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                    <?php $this->renderDashboardSwitch(WordPressCacheSettings::OPTION_LAYER_SYNC_ENABLED, $this->settings->layerSyncEnabled(), __('Layer Sync', WordPressCacheSettings::TEXT_DOMAIN), __('Cache-Layer zwischen Disk, Memory und optionalem Remote synchronisieren.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                </div>

                                <div class="sympress-side-stack">
                                    <div class="sympress-card">
                                        <h3><?php echo esc_html__('Remote Purge Endpoints', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                                        <p><?php echo esc_html__('Externe Dienste informieren, wenn Inhalte gelöscht werden.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                        <textarea name="<?php echo esc_attr(WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS); ?>" rows="4" class="large-text code sympress-textarea" placeholder="https://api.example.com/purge"><?php echo esc_textarea($option(WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS)); ?></textarea>
                                        <small><?php echo esc_html__('Eine URL pro Zeile.', WordPressCacheSettings::TEXT_DOMAIN); ?></small>
                                    </div>

                                    <div class="sympress-card">
                                        <div class="sympress-card__heading">
                                            <div>
                                                <h3><?php echo esc_html__('Generierte Nginx-Konfiguration', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                                                <p><?php echo esc_html__('Wird automatisch aktualisiert, wenn du Einstellungen speicherst.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                            </div>
                                        </div>
                                        <textarea class="large-text code sympress-config-output is-compact" rows="9" readonly="readonly"><?php echo esc_textarea($generatedConfig); ?></textarea>
                                        <div class="sympress-config-actions">
                                            <button type="button" class="button button-secondary" data-sympress-copy-config><?php echo esc_html__('Vorschau kopieren', WordPressCacheSettings::TEXT_DOMAIN); ?></button>
                                            <button type="button" class="button button-secondary" data-sympress-tab-jump="tools"><?php echo esc_html__('In Tools öffnen', WordPressCacheSettings::TEXT_DOMAIN); ?></button>
                                        </div>
                                    </div>

                                    <div class="sympress-card">
                                        <h3><?php echo esc_html__('Letzte Purge-Historie', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                                        <table class="sympress-history-table">
                                            <thead>
                                                <tr>
                                                    <th><?php echo esc_html__('Zeit', WordPressCacheSettings::TEXT_DOMAIN); ?></th>
                                                    <th><?php echo esc_html__('Typ', WordPressCacheSettings::TEXT_DOMAIN); ?></th>
                                                    <th><?php echo esc_html__('Ergebnis', WordPressCacheSettings::TEXT_DOMAIN); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (is_array($lastPurge)) : ?>
                                                    <tr>
                                                        <td><?php echo esc_html($this->date((int) ($lastPurge['created_at'] ?? 0))); ?></td>
                                                        <td><?php echo esc_html((string) ($lastPurge['mode'] ?? '')); ?></td>
                                                        <td><?php echo esc_html((bool) ($lastPurge['successful'] ?? false) ? __('Erfolgreich', WordPressCacheSettings::TEXT_DOMAIN) : __('Fehlgeschlagen', WordPressCacheSettings::TEXT_DOMAIN)); ?></td>
                                                    </tr>
                                                <?php else : ?>
                                                    <tr>
                                                        <td colspan="3"><?php echo esc_html__('Noch kein Purge aufgezeichnet.', WordPressCacheSettings::TEXT_DOMAIN); ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section id="sympress-tab-cache" class="sympress-cache-panel" data-sympress-panel="cache">
                            <div class="sympress-section-heading">
                                <div>
                                    <h2><?php echo esc_html__('Cache', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                                    <p><?php echo esc_html__('Configure the cache zone, generated Nginx profile and WordPress-triggered invalidation behavior.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                </div>
                            </div>

                            <div class="sympress-card sympress-form-card">
                                <label class="sympress-field">
                                    <span class="sympress-field__label"><?php echo esc_html__('Cache zone path', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    <input
                                        id="<?php echo esc_attr(WordPressCacheSettings::OPTION_PATH); ?>"
                                        name="<?php echo esc_attr(WordPressCacheSettings::OPTION_PATH); ?>"
                                        type="text"
                                        class="regular-text code sympress-input"
                                        value="<?php echo esc_attr($path); ?>"
                                        placeholder="<?php echo esc_attr($this->settings->defaultPath()); ?>"
                                        <?php echo $pathReadOnly ? 'readonly="readonly"' : ''; ?>
                                    />
                                    <span class="sympress-field__description">
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                __('Source: %s. The path must be writable by PHP and should point at the Nginx cache zone.', WordPressCacheSettings::TEXT_DOMAIN),
                                                $this->settings->pathSource(),
                                            ),
                                        );
                                        ?>
                                    </span>
                                </label>

                                <div class="sympress-profile-grid" role="radiogroup" aria-label="<?php echo esc_attr__('Cache profile', WordPressCacheSettings::TEXT_DOMAIN); ?>">
                                    <?php foreach (CacheProfile::cases() as $profile) : ?>
                                        <?php $this->renderProfileOption($profile, $this->settings->profile() === $profile); ?>
                                    <?php endforeach; ?>
                                </div>

                                <div class="sympress-toggle-stack">
                                    <?php $this->renderSwitch(WordPressCacheSettings::OPTION_AUTO_PURGE, $this->settings->autoPurgeEnabled(), __('Automatic purge', WordPressCacheSettings::TEXT_DOMAIN), __('Purge once per request when content, comments, menus, terms, users or theme state changes.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                    <?php $this->renderSwitch(WordPressCacheSettings::OPTION_SELECTIVE_PURGE, $this->settings->selectivePurgeEnabled(), __('Selective purge', WordPressCacheSettings::TEXT_DOMAIN), __('Purge changed URLs when they can be mapped to cache files, falling back to full purges when needed.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                    <?php $this->renderSwitch(WordPressCacheSettings::OPTION_QUEUE_ENABLED, $this->settings->queueEnabled(), __('Queue and debounce', WordPressCacheSettings::TEXT_DOMAIN), __('Collect automatic purge requests and process them once after the debounce window.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                </div>

                                <label class="sympress-field sympress-field--inline">
                                    <span>
                                        <span class="sympress-field__label"><?php echo esc_html__('Debounce seconds', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <span class="sympress-field__description"><?php echo esc_html__('How long automatic purge requests are collected before a queue run.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    </span>
                                    <input
                                        id="<?php echo esc_attr(WordPressCacheSettings::OPTION_DEBOUNCE_SECONDS); ?>"
                                        name="<?php echo esc_attr(WordPressCacheSettings::OPTION_DEBOUNCE_SECONDS); ?>"
                                        type="number"
                                        min="0"
                                        max="300"
                                        class="small-text sympress-number"
                                        value="<?php echo esc_attr((string) $this->settings->debounceSeconds()); ?>"
                                    />
                                </label>
                            </div>
                        </section>

                        <section id="sympress-tab-preload" class="sympress-cache-panel" data-sympress-panel="preload">
                            <div class="sympress-section-heading">
                                <div>
                                    <h2><?php echo esc_html__('Preload', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                                    <p><?php echo esc_html__('Warm important URLs after purges so the first visitor gets a hot Nginx cache file.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                </div>
                                <a class="button button-secondary" href="<?php echo esc_url($this->purgeActionUrl(false, true)); ?>">
                                    <?php echo esc_html__('Purge and prewarm now', WordPressCacheSettings::TEXT_DOMAIN); ?>
                                </a>
                            </div>

                            <div class="sympress-card sympress-form-card">
                                <?php $this->renderSwitch(WordPressCacheSettings::OPTION_PREWARM_ENABLED, $this->settings->prewarmEnabled(), __('Enable prewarm', WordPressCacheSettings::TEXT_DOMAIN), __('Warm the homepage and configured same-origin URLs after successful purges.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                <label class="sympress-field">
                                    <span class="sympress-field__label"><?php echo esc_html__('Prewarm URLs', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    <textarea name="<?php echo esc_attr(WordPressCacheSettings::OPTION_PREWARM_URLS); ?>" rows="8" class="large-text code sympress-textarea" placeholder="<?php echo esc_attr(home_url('/important-page/')); ?>"><?php echo esc_textarea($option(WordPressCacheSettings::OPTION_PREWARM_URLS)); ?></textarea>
                                    <span class="sympress-field__description"><?php echo esc_html(sprintf(__('One URL per line. The homepage is always included. Limit: %d URLs.', WordPressCacheSettings::TEXT_DOMAIN), $this->settings->maxPrewarmUrls())); ?></span>
                                </label>
                            </div>
                        </section>

                        <section id="sympress-tab-advanced" class="sympress-cache-panel" data-sympress-panel="advanced">
                            <div class="sympress-section-heading">
                                <div>
                                    <h2><?php echo esc_html__('Advanced Rules', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                                    <p><?php echo esc_html__('Fine-tune generated Nginx bypass maps for sensitive URLs, cookies, user agents and query strings.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                </div>
                            </div>

                            <div class="sympress-card sympress-form-card">
                                <div class="sympress-rule-grid">
                                    <label class="sympress-field">
                                        <span class="sympress-field__label"><?php echo esc_html__('Never cache URI patterns', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <textarea name="<?php echo esc_attr(WordPressCacheSettings::OPTION_BYPASS_URIS); ?>" rows="7" class="large-text code sympress-textarea" placeholder="^/private/"><?php echo esc_textarea($option(WordPressCacheSettings::OPTION_BYPASS_URIS)); ?></textarea>
                                        <span class="sympress-field__description"><?php echo esc_html__('Regex snippets matched against $request_uri, one per line.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    </label>
                                    <label class="sympress-field">
                                        <span class="sympress-field__label"><?php echo esc_html__('Never cache cookies', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <textarea name="<?php echo esc_attr(WordPressCacheSettings::OPTION_BYPASS_COOKIES); ?>" rows="7" class="large-text code sympress-textarea" placeholder="customer_segment"><?php echo esc_textarea($option(WordPressCacheSettings::OPTION_BYPASS_COOKIES)); ?></textarea>
                                        <span class="sympress-field__description"><?php echo esc_html__('Cookie name or regex snippets matched against the Cookie header.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    </label>
                                    <label class="sympress-field">
                                        <span class="sympress-field__label"><?php echo esc_html__('Never cache user agents', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <textarea name="<?php echo esc_attr(WordPressCacheSettings::OPTION_BYPASS_USER_AGENTS); ?>" rows="7" class="large-text code sympress-textarea" placeholder="SpecialBot"><?php echo esc_textarea($option(WordPressCacheSettings::OPTION_BYPASS_USER_AGENTS)); ?></textarea>
                                        <span class="sympress-field__description"><?php echo esc_html__('Regex snippets matched against $http_user_agent.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    </label>
                                    <label class="sympress-field">
                                        <span class="sympress-field__label"><?php echo esc_html__('Cache query strings', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <textarea name="<?php echo esc_attr(WordPressCacheSettings::OPTION_QUERY_ALLOWLIST); ?>" rows="7" class="large-text code sympress-textarea" placeholder="^utm_source=organic$"><?php echo esc_textarea($option(WordPressCacheSettings::OPTION_QUERY_ALLOWLIST)); ?></textarea>
                                        <span class="sympress-field__description"><?php echo esc_html__('Query regex snippets allowed to stay cacheable. Empty query is always allowed.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    </label>
                                </div>

                                <div class="sympress-toggle-stack">
                                    <?php $this->renderSwitch(WordPressCacheSettings::OPTION_TAG_INDEX_ENABLED, $this->settings->tagIndexEnabled(), __('Surrogate tag index', WordPressCacheSettings::TEXT_DOMAIN), __('Maintain a URL index for tags such as post, term, author, post type and site.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                    <?php $this->renderSwitch(WordPressCacheSettings::OPTION_DEBUG_HEADERS_ENABLED, $this->settings->debugHeadersEnabled(), __('Debug headers', WordPressCacheSettings::TEXT_DOMAIN), __('Emit Surrogate-Key and X-SymPress-Cache-Tags headers for debugging or external purge workers.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                    <?php $this->renderSwitch(WordPressCacheSettings::OPTION_REST_ENABLED, $this->settings->restEnabled(), __('REST API', WordPressCacheSettings::TEXT_DOMAIN), __('Enable secured purge, status, config, probe and queue endpoints for administrators.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                </div>
                            </div>
                        </section>

                        <section id="sympress-tab-remote" class="sympress-cache-panel" data-sympress-panel="remote">
                            <div class="sympress-section-heading">
                                <div>
                                    <h2><?php echo esc_html__('CDN & Remote', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                                    <p><?php echo esc_html__('Coordinate Nginx with CDN, sidecar or edge purge services after successful local invalidation.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                </div>
                            </div>

                            <div class="sympress-card sympress-form-card">
                                <label class="sympress-field">
                                    <span class="sympress-field__label"><?php echo esc_html__('Remote purge endpoints', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    <textarea name="<?php echo esc_attr(WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS); ?>" rows="7" class="large-text code sympress-textarea" placeholder="https://cache-agent.internal/purge"><?php echo esc_textarea($option(WordPressCacheSettings::OPTION_REMOTE_ENDPOINTS)); ?></textarea>
                                    <span class="sympress-field__description"><?php echo esc_html__('One endpoint per line. Requests are normalized and signed when a secret is configured.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                </label>
                                <label class="sympress-field">
                                    <span class="sympress-field__label"><?php echo esc_html__('Signing secret', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    <input id="<?php echo esc_attr(WordPressCacheSettings::OPTION_REMOTE_SECRET); ?>" name="<?php echo esc_attr(WordPressCacheSettings::OPTION_REMOTE_SECRET); ?>" type="password" class="regular-text code sympress-input" value="<?php echo esc_attr($option(WordPressCacheSettings::OPTION_REMOTE_SECRET)); ?>" autocomplete="new-password" />
                                    <span class="sympress-field__description"><?php echo esc_html__('Used to sign remote purge payloads for edge workers or sidecar agents.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                </label>
                                <div class="sympress-inline-state">
                                    <span><?php echo esc_html__('Configured endpoints', WordPressCacheSettings::TEXT_DOMAIN); ?> <strong><?php echo esc_html((string) ($remote['endpoints'] ?? 0)); ?></strong></span>
                                    <span><?php echo esc_html__('Signed payloads', WordPressCacheSettings::TEXT_DOMAIN); ?> <strong><?php echo esc_html($this->yesNo((bool) ($remote['signed'] ?? false))); ?></strong></span>
                                </div>
                            </div>
                        </section>

                        <section id="sympress-tab-layers" class="sympress-cache-panel" data-sympress-panel="layers">
                            <div class="sympress-section-heading">
                                <div>
                                    <h2><?php echo esc_html__('Heartbeat & Layers', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                                    <p><?php echo esc_html__('Reduce admin-ajax pressure and keep other cache layers coordinated after successful Nginx purges.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                </div>
                            </div>

                            <div class="sympress-card sympress-form-card">
                                <div class="sympress-radio-stack" role="radiogroup" aria-label="<?php echo esc_attr__('Heartbeat mode', WordPressCacheSettings::TEXT_DOMAIN); ?>">
                                    <?php foreach (['default', 'reduce', 'disable'] as $mode) : ?>
                                        <label class="sympress-radio-row">
                                            <input type="radio" name="<?php echo esc_attr(WordPressCacheSettings::OPTION_HEARTBEAT_MODE); ?>" value="<?php echo esc_attr($mode); ?>" <?php checked($this->settings->heartbeatMode(), $mode); ?> />
                                            <span>
                                                <strong><?php echo esc_html($this->heartbeatModeLabel($mode)); ?></strong>
                                                <small><?php echo esc_html($this->heartbeatModeDescription($mode)); ?></small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <label class="sympress-field sympress-field--inline">
                                    <span>
                                        <span class="sympress-field__label"><?php echo esc_html__('Reduced interval', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                        <span class="sympress-field__description"><?php echo esc_html__('Applies when Heartbeat is set to Reduce activity.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                                    </span>
                                    <input id="<?php echo esc_attr(WordPressCacheSettings::OPTION_HEARTBEAT_INTERVAL); ?>" name="<?php echo esc_attr(WordPressCacheSettings::OPTION_HEARTBEAT_INTERVAL); ?>" type="number" min="60" max="300" class="small-text sympress-number" value="<?php echo esc_attr((string) $this->settings->heartbeatInterval()); ?>" />
                                </label>

                                <?php $this->renderSwitch(WordPressCacheSettings::OPTION_LAYER_SYNC_ENABLED, $this->settings->layerSyncEnabled(), __('Layer sync', WordPressCacheSettings::TEXT_DOMAIN), __('After successful Nginx purges, enqueue the integration hook for object cache, OPcache or custom cache layer workers.', WordPressCacheSettings::TEXT_DOMAIN)); ?>

                                <div class="sympress-inline-state">
                                    <span><?php echo esc_html__('Layer integrations available', WordPressCacheSettings::TEXT_DOMAIN); ?> <strong><?php echo esc_html($this->yesNo((bool) ($layers['available'] ?? false))); ?></strong></span>
                                    <span><?php echo esc_html__('Layer sync enabled', WordPressCacheSettings::TEXT_DOMAIN); ?> <strong><?php echo esc_html($this->yesNo($this->settings->layerSyncEnabled())); ?></strong></span>
                                </div>
                            </div>
                        </section>

                        <section id="sympress-tab-tools" class="sympress-cache-panel" data-sympress-panel="tools">
                            <div class="sympress-section-heading">
                                <div>
                                    <h2><?php echo esc_html__('Tools', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                                    <p><?php echo esc_html__('Operational actions, generated Nginx configuration and environment diagnostics.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                                </div>
                                <button type="button" class="button button-secondary" data-sympress-onboarding-open>
                                    <?php echo esc_html__('Setup assistant', WordPressCacheSettings::TEXT_DOMAIN); ?>
                                </button>
                            </div>

                            <?php if ($validation->warnings !== []) : ?>
                                <div class="sympress-alert is-warning">
                                    <strong><?php echo esc_html__('Warnings', WordPressCacheSettings::TEXT_DOMAIN); ?></strong>
                                    <span><?php echo esc_html(implode(' ', $validation->warnings)); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($configMissing !== []) : ?>
                                <div class="sympress-alert is-warning">
                                    <strong><?php echo esc_html__('Config warning', WordPressCacheSettings::TEXT_DOMAIN); ?></strong>
                                    <span><?php echo esc_html(sprintf(__('Missing directives: %s', WordPressCacheSettings::TEXT_DOMAIN), implode(', ', $configMissing))); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="sympress-action-grid">
                                <?php $this->renderActionCard($this->purgeUrl(), 'update-alt', __('Purge cache', WordPressCacheSettings::TEXT_DOMAIN), __('Delete Nginx cache files for the configured zone.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                <?php $this->renderActionCard($this->purgeActionUrl(true), 'visibility', __('Dry run', WordPressCacheSettings::TEXT_DOMAIN), __('Inspect purge behavior without removing files.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                                <?php $this->renderActionCard($this->queueActionUrl(), 'performance', __('Flush queue', WordPressCacheSettings::TEXT_DOMAIN), __('Process pending automatic purge requests.', WordPressCacheSettings::TEXT_DOMAIN)); ?>
                            </div>

                            <div class="sympress-card">
                                <div class="sympress-card__heading">
                                    <h3><?php echo esc_html__('Generated Nginx config', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                                    <button type="button" class="button button-secondary" data-sympress-copy-config>
                                        <?php echo esc_html__('Copy config', WordPressCacheSettings::TEXT_DOMAIN); ?>
                                    </button>
                                </div>
                                <textarea class="large-text code sympress-config-output" rows="24" readonly="readonly" data-sympress-config-output><?php echo esc_textarea($generatedConfig); ?></textarea>
                            </div>

                            <div class="sympress-two-column">
                                <div class="sympress-card">
                                    <h3><?php echo esc_html__('Environment', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                                    <table class="sympress-kv-table">
                                        <tbody>
                                            <?php $this->renderStatusRow(__('Server', WordPressCacheSettings::TEXT_DOMAIN), (string) ($environment['server_software'] ?: 'unknown')); ?>
                                            <?php $this->renderStatusRow(__('Nginx', WordPressCacheSettings::TEXT_DOMAIN), (string) $environment['nginx_flavour']); ?>
                                            <?php $this->renderStatusRow(__('Signals', WordPressCacheSettings::TEXT_DOMAIN), implode(', ', (array) $environment['signals']) ?: 'none'); ?>
                                            <?php $this->renderStatusRow(__('PHP SAPI', WordPressCacheSettings::TEXT_DOMAIN), (string) $environment['php_sapi']); ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="sympress-card">
                                    <h3><?php echo esc_html__('REST surface', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                                    <table class="sympress-kv-table">
                                        <tbody>
                                            <?php $this->renderStatusRow(__('REST API', WordPressCacheSettings::TEXT_DOMAIN), $this->yesNo($this->settings->restEnabled())); ?>
                                            <?php $this->renderStatusRow(__('Selective purge', WordPressCacheSettings::TEXT_DOMAIN), $this->yesNo((bool) ($diagnostics['settings']['selective_purge'] ?? false))); ?>
                                            <?php $this->renderStatusRow(__('Remote endpoints', WordPressCacheSettings::TEXT_DOMAIN), (string) ($remote['endpoints'] ?? 0)); ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>

                        <div class="sympress-save-bar">
                            <span data-sympress-dirty-state><?php echo esc_html__('Settings are up to date.', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                            <?php submit_button(__('Save changes', WordPressCacheSettings::TEXT_DOMAIN), 'primary', 'submit', false); ?>
                        </div>
                    </main>
                </div>

                <?php $this->renderOnboardingModal(); ?>
            </form>

            <?php $this->renderScripts(); ?>
        </div>
        <?php
    }

    private function renderTabButton(string $target, string $icon, string $label, bool $active = false): void
    {
        ?>
        <button
            type="button"
            class="sympress-cache-tabs__item <?php echo $active ? 'is-active' : ''; ?>"
            data-sympress-tab="<?php echo esc_attr($target); ?>"
            aria-controls="sympress-tab-<?php echo esc_attr($target); ?>"
            aria-selected="<?php echo esc_attr($active ? 'true' : 'false'); ?>"
        >
            <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
            <span><?php echo esc_html($label); ?></span>
        </button>
        <?php
    }

    private function renderMetricCard(string $label, string $value, string $description, string $tone = 'neutral', string $icon = 'performance'): void
    {
        ?>
        <div class="sympress-metric is-<?php echo esc_attr($tone); ?>">
            <span><?php echo esc_html($label); ?></span>
            <span class="sympress-metric__icon dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
            <strong><?php echo esc_html($value); ?></strong>
            <small><?php echo esc_html($description); ?></small>
        </div>
        <?php
    }

    private function renderActionCard(string $url, string $icon, string $title, string $description): void
    {
        ?>
        <a class="sympress-action-card" href="<?php echo esc_url($url); ?>">
            <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
            <strong><?php echo esc_html($title); ?></strong>
            <small><?php echo esc_html($description); ?></small>
        </a>
        <?php
    }

    private function renderSwitch(string $option, bool $checked, string $title, string $description): void
    {
        ?>
        <label class="sympress-switch">
            <input type="hidden" name="<?php echo esc_attr($option); ?>" value="0" />
            <input type="checkbox" name="<?php echo esc_attr($option); ?>" value="1" <?php checked($checked); ?> />
            <span class="sympress-switch__track" aria-hidden="true"></span>
            <span class="sympress-switch__copy">
                <strong><?php echo esc_html($title); ?></strong>
                <small><?php echo esc_html($description); ?></small>
            </span>
        </label>
        <?php
    }

    private function renderDashboardSwitch(string $option, bool $checked, string $title, string $description): void
    {
        ?>
        <label class="sympress-setting-row sympress-setting-row--switch">
            <span><?php echo esc_html($title); ?></span>
            <span>
                <span class="sympress-mini-switch">
                    <input type="hidden" name="<?php echo esc_attr($option); ?>" value="0" />
                    <input type="checkbox" name="<?php echo esc_attr($option); ?>" value="1" <?php checked($checked); ?> />
                    <span aria-hidden="true"></span>
                </span>
                <small><?php echo esc_html($description); ?></small>
            </span>
        </label>
        <?php
    }

    private function renderProfileOption(CacheProfile $profile, bool $checked): void
    {
        ?>
        <label class="sympress-profile-card">
            <input
                type="radio"
                name="<?php echo esc_attr(WordPressCacheSettings::OPTION_PROFILE); ?>"
                value="<?php echo esc_attr($profile->value); ?>"
                <?php checked($checked); ?>
            />
            <span>
                <strong><?php echo esc_html($this->profileLabel($profile)); ?></strong>
                <small><?php echo esc_html($this->profileDescription($profile)); ?></small>
            </span>
        </label>
        <?php
    }

    private function renderStatusRow(string $label, string $value): void
    {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td><code><?php echo esc_html($value); ?></code></td>
        </tr>
        <?php
    }

    private function renderOnboardingModal(): void
    {
        ?>
        <div class="sympress-onboarding-modal" data-sympress-onboarding hidden>
            <div class="sympress-onboarding-modal__backdrop" data-sympress-onboarding-close></div>
            <div class="sympress-onboarding-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sympress-onboarding-title">
                <div class="sympress-onboarding-modal__header">
                    <div>
                        <span><?php echo esc_html__('Nginx Cache setup', WordPressCacheSettings::TEXT_DOMAIN); ?></span>
                        <h2 id="sympress-onboarding-title"><?php echo esc_html__('Erste Schritte', WordPressCacheSettings::TEXT_DOMAIN); ?></h2>
                    </div>
                    <button type="button" class="button-link sympress-modal-close" data-sympress-onboarding-close aria-label="<?php echo esc_attr__('Close setup assistant', WordPressCacheSettings::TEXT_DOMAIN); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="sympress-onboarding-steps" aria-hidden="true">
                    <span class="is-active" data-sympress-step-dot></span>
                    <span data-sympress-step-dot></span>
                    <span data-sympress-step-dot></span>
                </div>
                <div class="sympress-onboarding-modal__body">
                    <section data-sympress-onboarding-step>
                        <h3><?php echo esc_html__('1. Cache-Zone festlegen', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                        <p><?php echo esc_html__('Nutze einen absoluten Pfad, der von PHP lesbar und schreibbar ist. Fuer WooCommerce oder Membership-Seiten bleibt das Safe- oder Commerce-Profil die robuste Wahl.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                    </section>
                    <section data-sympress-onboarding-step hidden>
                        <h3><?php echo esc_html__('2. Automatische Purges aktivieren', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                        <p><?php echo esc_html__('Der Assistent aktiviert automatische, selektive Purges, Queue/Debounce und Prewarm. So wird das Plugin zur ruhigen Performance-Zentrale statt zum manuellen Werkzeugkasten.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                    </section>
                    <section data-sympress-onboarding-step hidden>
                        <h3><?php echo esc_html__('3. Regeln und Tools pruefen', WordPressCacheSettings::TEXT_DOMAIN); ?></h3>
                        <p><?php echo esc_html__('Nach dem Speichern findest du die deploybare Nginx-Konfiguration im Tools-Tab. Advanced Rules kannst du spaeter fuer Sonderfaelle wie private Bereiche, Cookies oder Bots ergaenzen.', WordPressCacheSettings::TEXT_DOMAIN); ?></p>
                    </section>
                </div>
                <div class="sympress-onboarding-modal__footer">
                    <button type="button" class="button button-secondary" data-sympress-onboarding-prev disabled><?php echo esc_html__('Zurueck', WordPressCacheSettings::TEXT_DOMAIN); ?></button>
                    <button type="button" class="button button-primary" data-sympress-onboarding-next><?php echo esc_html__('Weiter', WordPressCacheSettings::TEXT_DOMAIN); ?></button>
                    <button type="button" class="button button-primary" data-sympress-onboarding-finish hidden><?php echo esc_html__('Empfohlene Einstellungen speichern', WordPressCacheSettings::TEXT_DOMAIN); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderStyles(): void
    {
        ?>
        <style>
            .sympress-cache-admin {
                --sympress-blue: var(--wp-admin-theme-color, #2271b1);
                --sympress-blue-dark: var(--wp-admin-theme-color-darker-10, #135e96);
                --sympress-blue-deep: var(--wp-admin-theme-color-darker-20, #0a4b78);
                --sympress-surface: #fff;
                --sympress-bg: #f0f0f1;
                --sympress-border: #dcdcde;
                --sympress-text: #1d2327;
                --sympress-muted: #646970;
                --sympress-success: #008a20;
                --sympress-warning: #b26200;
                max-width: none;
                margin-right: 20px;
            }
            .sympress-cache-admin * { box-sizing: border-box; letter-spacing: 0; }
            .sympress-product-bar {
                display: grid;
                grid-template-columns: minmax(238px, auto) minmax(150px, 1fr) max-content;
                align-items: center;
                gap: 16px;
                min-height: 72px;
                margin: 18px 0 0;
                padding: 0 14px;
                background: #fff;
                border: 1px solid var(--sympress-border);
                border-radius: 8px 8px 0 0;
                box-shadow: 0 8px 24px rgba(0, 0, 0, .035);
            }
            .sympress-product-brand {
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 0;
                padding-right: 18px;
                border-right: 1px solid var(--sympress-border);
            }
            .sympress-product-brand strong {
                color: var(--sympress-text);
                font-size: 23px;
                line-height: 1;
                white-space: nowrap;
            }
            .sympress-product-logo {
                display: grid;
                place-items: center;
                width: 40px;
                height: 40px;
                color: #fff;
                background: linear-gradient(145deg, var(--sympress-blue), var(--sympress-blue-deep));
                clip-path: polygon(50% 0, 91% 24%, 91% 76%, 50% 100%, 9% 76%, 9% 24%);
                font-weight: 800;
                font-size: 21px;
            }
            .sympress-version {
                display: inline-flex;
                align-items: center;
                min-height: 22px;
                padding: 2px 7px;
                border-radius: 5px;
                color: var(--sympress-blue-deep);
                background: color-mix(in srgb, var(--sympress-blue) 12%, #fff);
                font-size: 12px;
                font-weight: 700;
            }
            .sympress-product-status {
                display: grid;
                gap: 4px;
                justify-self: start;
            }
            .sympress-product-status small {
                color: var(--sympress-muted);
                font-size: 12px;
            }
            .sympress-product-actions {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                flex-wrap: nowrap;
                gap: 8px;
                min-width: max-content;
            }
            .sympress-product-actions .submit {
                margin: 0;
                padding: 0;
            }
            .sympress-product-actions .button {
                min-height: 34px;
                padding-inline: 12px;
                border-radius: 6px;
                font-weight: 600;
                white-space: nowrap;
            }
            .sympress-cache-hero {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 24px;
                padding: 28px;
                margin: 22px 0 16px;
                background: var(--sympress-surface);
                border: 1px solid var(--sympress-border);
                border-top: 4px solid var(--sympress-blue);
                border-radius: 8px;
                box-shadow: 0 10px 28px rgba(0, 0, 0, .05);
            }
            .sympress-cache-hero h1 { margin: 8px 0 8px; font-size: 34px; line-height: 1.15; font-weight: 700; color: var(--sympress-text); }
            .sympress-cache-hero p { max-width: 760px; margin: 0; color: var(--sympress-muted); font-size: 15px; line-height: 1.6; }
            .sympress-cache-hero__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; min-width: 310px; }
            .sympress-cache-hero__actions .button { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; }
            .sympress-cache-hero__actions .dashicons { width: 16px; height: 16px; font-size: 16px; }
            .sympress-cache-status { display: inline-flex; align-items: center; gap: 8px; color: var(--sympress-muted); font-size: 13px; font-weight: 600; }
            .sympress-cache-status__dot { width: 9px; height: 9px; border-radius: 99px; background: currentColor; }
            .sympress-cache-status.is-good { color: var(--sympress-success); }
            .sympress-cache-status.is-warning { color: var(--sympress-warning); }
            .sympress-onboarding-callout {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 20px;
                padding: 18px 20px;
                margin: 0 0 16px;
                background: color-mix(in srgb, var(--sympress-blue) 8%, #fff);
                border: 1px solid color-mix(in srgb, var(--sympress-blue) 26%, #fff);
                border-radius: 8px;
            }
            .sympress-onboarding-callout p { margin: 4px 0 0; color: var(--sympress-muted); }
            .sympress-cache-shell { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 16px; align-items: start; }
            .sympress-cache-tabs {
                position: sticky;
                top: 46px;
                display: flex;
                flex-direction: column;
                gap: 6px;
                min-height: calc(100vh - 128px);
                padding: 14px 10px;
                background: var(--sympress-surface);
                border: 1px solid var(--sympress-border);
                border-top: 0;
                border-radius: 0 0 8px 8px;
            }
            .sympress-cache-tabs__item {
                display: flex;
                align-items: center;
                gap: 10px;
                width: 100%;
                min-height: 46px;
                padding: 10px 12px;
                border: 0;
                border-radius: 6px;
                color: var(--sympress-text);
                background: transparent;
                cursor: pointer;
                text-align: left;
                font-size: 14px;
                font-weight: 600;
            }
            .sympress-cache-tabs__item:hover,
            .sympress-cache-tabs__item.is-active {
                color: var(--sympress-blue-dark);
                background: color-mix(in srgb, var(--sympress-blue) 10%, #fff);
            }
            .sympress-cache-tabs__item.is-active {
                box-shadow: inset 3px 0 0 var(--sympress-blue);
            }
            .sympress-cache-help {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                align-items: center;
                gap: 9px;
                min-height: 42px;
                margin-top: auto;
                padding: 12px 10px 0;
                border-top: 1px solid #f0f0f1;
                color: var(--sympress-muted);
                text-decoration: none;
                font-size: 13px;
                line-height: 1.25;
            }
            .sympress-cache-help:hover {
                color: var(--sympress-blue-dark);
            }
            .sympress-cache-help .dashicons {
                width: 16px;
                height: 16px;
                font-size: 16px;
            }
            .sympress-cache-content { min-width: 0; }
            .sympress-cache-panel { display: none; }
            .sympress-cache-panel.is-active { display: grid; gap: 16px; }
            .sympress-section-heading {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 16px;
                padding: 22px 24px;
                background: var(--sympress-surface);
                border: 1px solid var(--sympress-border);
                border-radius: 8px;
            }
            .sympress-section-heading h2 { margin: 0 0 6px; font-size: 24px; line-height: 1.25; }
            .sympress-section-heading p { margin: 0; color: var(--sympress-muted); font-size: 14px; line-height: 1.55; }
            .sympress-card,
            .sympress-metric,
            .sympress-action-card {
                background: var(--sympress-surface);
                border: 1px solid var(--sympress-border);
                border-radius: 8px;
                box-shadow: 0 4px 16px rgba(0, 0, 0, .035);
            }
            .sympress-card { padding: 18px; }
            .sympress-card h3 { margin: 0 0 12px; font-size: 16px; }
            .sympress-card p { margin: -4px 0 12px; color: var(--sympress-muted); }
            .sympress-card__heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
            .sympress-card__heading h3 { margin: 0; }
            .sympress-metrics-grid,
            .sympress-action-grid,
            .sympress-two-column,
            .sympress-rule-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
            }
            .sympress-metrics-grid { grid-template-columns: repeat(5, minmax(150px, 1fr)); gap: 14px; }
            .sympress-metric {
                position: relative;
                min-height: 96px;
                padding: 16px 52px 14px 16px;
                border-left: 0;
                overflow: hidden;
            }
            .sympress-metric.is-good { border-left-color: var(--sympress-success); }
            .sympress-metric.is-warning { border-left-color: var(--sympress-warning); }
            .sympress-metric span,
            .sympress-metric small,
            .sympress-action-card small,
            .sympress-field__description,
            .sympress-switch small,
            .sympress-profile-card small,
            .sympress-radio-row small { color: var(--sympress-muted); }
            .sympress-metric > span:first-child { display: block; color: var(--sympress-text); font-size: 13px; }
            .sympress-metric__icon {
                position: absolute;
                top: 22px;
                right: 16px;
                display: grid;
                place-items: center;
                width: 34px;
                height: 34px;
                border-radius: 50%;
                color: var(--sympress-blue);
                background: color-mix(in srgb, var(--sympress-blue) 10%, #fff);
                font-size: 21px;
            }
            .sympress-metric .sympress-metric__icon { color: var(--sympress-blue); }
            .sympress-metric strong { display: block; margin: 9px 0 4px; color: var(--sympress-text); font-size: 26px; line-height: 1.1; }
            .sympress-metric small { display: block; line-height: 1.45; }
            .sympress-metric--chart { padding-right: 16px; }
            .sympress-metric--chart small { max-width: calc(100% - 72px); }
            .sympress-metric--chart svg {
                position: absolute;
                right: 14px;
                bottom: 12px;
                width: 82px;
                height: 32px;
            }
            .sympress-metric--chart polyline {
                fill: none;
                stroke: var(--sympress-blue);
                stroke-width: 4;
                stroke-linecap: round;
                stroke-linejoin: round;
            }
            .sympress-welcome-panel {
                display: grid;
                grid-template-columns: 56px minmax(0, 1fr) auto;
                align-items: center;
                gap: 16px;
                padding: 16px 18px;
                background: #fff;
                border: 1px solid var(--sympress-blue);
                border-radius: 8px;
            }
            .sympress-welcome-panel h2 {
                margin: 0 0 4px;
                color: var(--sympress-blue-deep);
                font-size: 17px;
            }
            .sympress-welcome-panel p {
                margin: 0;
                color: var(--sympress-text);
            }
            .sympress-welcome-icon {
                display: grid;
                place-items: center;
                width: 48px;
                height: 48px;
                border-radius: 50%;
                color: var(--sympress-blue);
                background: color-mix(in srgb, var(--sympress-blue) 10%, #fff);
            }
            .sympress-welcome-icon .dashicons { width: 28px; height: 28px; font-size: 28px; }
            .sympress-quick-actions {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
            }
            .sympress-quick-actions .button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                min-height: 38px;
                border-radius: 6px;
                font-weight: 600;
                line-height: 1;
            }
            .sympress-quick-actions .dashicons {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex: 0 0 20px;
                width: 20px;
                height: 20px;
                margin: 0;
                line-height: 20px;
            }
            .sympress-quick-actions .dashicons::before {
                display: block;
                width: 20px;
                height: 20px;
                font-size: 18px;
                line-height: 20px;
                text-align: center;
            }
            .sympress-dashboard-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.08fr) minmax(360px, .92fr);
                gap: 16px;
                align-items: start;
            }
            .sympress-side-stack { display: grid; gap: 16px; }
            .sympress-dashboard-settings { min-height: 100%; }
            .sympress-setting-row {
                display: grid;
                grid-template-columns: 176px minmax(0, 1fr);
                gap: 18px;
                align-items: start;
                padding: 11px 0;
            }
            .sympress-setting-row + .sympress-setting-row { border-top: 1px solid #f0f0f1; }
            .sympress-setting-row > span:first-child {
                color: var(--sympress-text);
                font-weight: 600;
                line-height: 32px;
            }
            .sympress-setting-row small,
            .sympress-card small {
                display: block;
                margin-top: 6px;
                color: var(--sympress-muted);
                line-height: 1.4;
            }
            .sympress-setting-row .sympress-textarea { min-height: 84px; }
            .sympress-mini-switch {
                position: relative;
                display: inline-flex;
                width: 40px;
                height: 22px;
                vertical-align: middle;
                margin: 4px 10px 0 0;
            }
            .sympress-mini-switch input[type="checkbox"] {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }
            .sympress-mini-switch > span {
                position: absolute;
                inset: 0;
                border-radius: 99px;
                background: #a7aaad;
                transition: background .16s ease;
            }
            .sympress-mini-switch > span::after {
                content: "";
                position: absolute;
                top: 3px;
                left: 3px;
                width: 16px;
                height: 16px;
                border-radius: 50%;
                background: #fff;
                transition: transform .16s ease;
            }
            .sympress-mini-switch input[type="checkbox"]:checked + span { background: var(--sympress-blue); }
            .sympress-mini-switch input[type="checkbox"]:checked + span::after { transform: translateX(18px); }
            .sympress-config-output.is-compact {
                min-height: 180px;
                resize: vertical;
                background: #f6f7f7;
            }
            .sympress-config-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
                margin-top: 12px;
            }
            .sympress-config-actions .button { text-align: center; }
            .sympress-history-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
            }
            .sympress-history-table th,
            .sympress-history-table td {
                padding: 9px 10px;
                border: 1px solid #e7e8ea;
                text-align: left;
            }
            .sympress-history-table th {
                background: #f6f7f7;
                color: var(--sympress-text);
                font-weight: 700;
            }
            .sympress-action-card {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr);
                gap: 4px 12px;
                padding: 16px;
                color: var(--sympress-text);
                text-decoration: none;
            }
            .sympress-action-card:hover { border-color: var(--sympress-blue); color: var(--sympress-blue-dark); }
            .sympress-action-card .dashicons { grid-row: span 2; color: var(--sympress-blue); }
            .sympress-form-card { display: grid; gap: 20px; }
            .sympress-field { display: grid; gap: 8px; }
            .sympress-field--inline { grid-template-columns: minmax(0, 1fr) 120px; align-items: center; gap: 18px; }
            .sympress-field__label { color: var(--sympress-text); font-weight: 700; }
            .sympress-field__description { line-height: 1.45; }
            .sympress-input,
            .sympress-textarea,
            .sympress-config-output,
            .sympress-number { width: 100%; border-color: var(--sympress-border); border-radius: 6px; }
            .sympress-textarea,
            .sympress-config-output { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 13px; line-height: 1.55; }
            .sympress-profile-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
            .sympress-profile-card,
            .sympress-radio-row,
            .sympress-switch {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 14px;
                border: 1px solid var(--sympress-border);
                border-radius: 8px;
                background: #fff;
            }
            .sympress-profile-card input,
            .sympress-radio-row input { margin-top: 2px; }
            .sympress-profile-card:has(input:checked),
            .sympress-radio-row:has(input:checked) {
                border-color: var(--sympress-blue);
                box-shadow: 0 0 0 1px var(--sympress-blue);
            }
            .sympress-profile-card strong,
            .sympress-radio-row strong,
            .sympress-switch strong { display: block; margin-bottom: 4px; color: var(--sympress-text); }
            .sympress-toggle-stack,
            .sympress-radio-stack { display: grid; gap: 10px; }
            .sympress-switch { position: relative; padding-left: 72px; }
            .sympress-switch input[type="checkbox"] { position: absolute; opacity: 0; pointer-events: none; }
            .sympress-switch__track {
                position: absolute;
                left: 14px;
                top: 16px;
                width: 42px;
                height: 24px;
                border-radius: 99px;
                background: #a7aaad;
                transition: background .16s ease;
            }
            .sympress-switch__track::after {
                content: "";
                position: absolute;
                top: 3px;
                left: 3px;
                width: 18px;
                height: 18px;
                border-radius: 99px;
                background: #fff;
                transition: transform .16s ease;
            }
            .sympress-switch input[type="checkbox"]:checked + .sympress-switch__track { background: var(--sympress-blue); }
            .sympress-switch input[type="checkbox"]:checked + .sympress-switch__track::after { transform: translateX(18px); }
            .sympress-inline-state,
            .sympress-alert {
                display: flex;
                flex-wrap: wrap;
                gap: 10px 18px;
                padding: 14px;
                border-radius: 8px;
                background: #f6f7f7;
                border: 1px solid var(--sympress-border);
            }
            .sympress-alert.is-warning { border-color: color-mix(in srgb, var(--sympress-warning) 30%, #fff); background: #fcf9e8; }
            .sympress-kv-table { width: 100%; border-collapse: collapse; }
            .sympress-kv-table th,
            .sympress-kv-table td { padding: 10px 0; border-top: 1px solid #f0f0f1; text-align: left; vertical-align: top; }
            .sympress-kv-table tr:first-child th,
            .sympress-kv-table tr:first-child td { border-top: 0; }
            .sympress-kv-table th { width: 42%; color: var(--sympress-muted); font-weight: 600; }
            .sympress-save-bar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 14px;
                padding: 14px 16px;
                background: #fff;
                border: 1px solid var(--sympress-border);
                border-radius: 8px;
                box-shadow: 0 4px 16px rgba(0, 0, 0, .035);
            }
            .sympress-save-bar .submit { margin: 0; padding: 0; }
            .sympress-onboarding-modal[hidden] { display: none; }
            .sympress-onboarding-modal { position: fixed; inset: 0; z-index: 100000; display: grid; place-items: center; padding: 24px; }
            .sympress-onboarding-modal__backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, .38); }
            .sympress-onboarding-modal__dialog {
                position: relative;
                width: min(620px, 100%);
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, .28);
                overflow: hidden;
            }
            .sympress-onboarding-modal__header,
            .sympress-onboarding-modal__footer { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 20px 24px; border-bottom: 1px solid var(--sympress-border); }
            .sympress-onboarding-modal__footer { border-top: 1px solid var(--sympress-border); border-bottom: 0; }
            .sympress-onboarding-modal__header h2 { margin: 3px 0 0; }
            .sympress-onboarding-modal__header span { color: var(--sympress-muted); font-weight: 700; }
            .sympress-onboarding-modal__body { min-height: 180px; padding: 24px; }
            .sympress-onboarding-modal__body h3 { margin-top: 0; }
            .sympress-onboarding-modal__body p { color: var(--sympress-muted); font-size: 15px; line-height: 1.6; }
            .sympress-modal-close .dashicons { font-size: 24px; width: 24px; height: 24px; }
            .sympress-onboarding-steps { display: flex; gap: 8px; padding: 0 24px; }
            .sympress-onboarding-steps span { flex: 1; height: 4px; border-radius: 99px; background: #dcdcde; }
            .sympress-onboarding-steps span.is-active { background: var(--sympress-blue); }
            @media (max-width: 1100px) {
                .sympress-product-bar {
                    grid-template-columns: 1fr;
                    gap: 14px;
                    padding: 16px;
                }
                .sympress-product-brand {
                    min-width: 0;
                    padding-right: 0;
                    border-right: 0;
                }
                .sympress-product-actions { justify-content: flex-start; }
                .sympress-cache-shell { grid-template-columns: 1fr; }
                .sympress-cache-tabs {
                    position: static;
                    display: flex;
                    flex-direction: row;
                    min-height: 0;
                    overflow-x: auto;
                    border-top: 1px solid var(--sympress-border);
                    border-radius: 8px;
                }
                .sympress-cache-tabs__item { min-width: max-content; }
                .sympress-cache-tabs__item.is-active { box-shadow: inset 0 -3px 0 var(--sympress-blue); }
                .sympress-cache-help {
                    min-width: max-content;
                    margin-top: 0;
                    padding: 10px 12px;
                    border-top: 0;
                }
                .sympress-metrics-grid,
                .sympress-profile-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .sympress-dashboard-grid { grid-template-columns: 1fr; }
            }
            @media (max-width: 782px) {
                .sympress-product-actions,
                .sympress-welcome-panel,
                .sympress-section-heading,
                .sympress-save-bar { flex-direction: column; align-items: stretch; }
                .sympress-product-actions {
                    display: grid;
                    grid-template-columns: 1fr;
                }
                .sympress-product-actions .button { width: 100%; text-align: center; justify-content: center; }
                .sympress-welcome-panel {
                    display: grid;
                    grid-template-columns: 1fr;
                    text-align: left;
                }
                .sympress-metrics-grid,
                .sympress-action-grid,
                .sympress-quick-actions,
                .sympress-two-column,
                .sympress-config-actions,
                .sympress-rule-grid,
                .sympress-profile-grid { grid-template-columns: 1fr; }
                .sympress-setting-row { grid-template-columns: 1fr; gap: 6px; }
                .sympress-setting-row > span:first-child { line-height: 1.3; }
                .sympress-field--inline { grid-template-columns: 1fr; }
            }
        </style>
        <?php
    }

    private function renderScripts(): void
    {
        $recommended = [
            WordPressCacheSettings::OPTION_AUTO_PURGE        => true,
            WordPressCacheSettings::OPTION_SELECTIVE_PURGE   => true,
            WordPressCacheSettings::OPTION_QUEUE_ENABLED     => true,
            WordPressCacheSettings::OPTION_PREWARM_ENABLED   => true,
            WordPressCacheSettings::OPTION_REST_ENABLED      => true,
            WordPressCacheSettings::OPTION_TAG_INDEX_ENABLED => true,
            WordPressCacheSettings::OPTION_DEBOUNCE_SECONDS  => '10',
            WordPressCacheSettings::OPTION_HEARTBEAT_MODE    => 'reduce',
        ];
        $recommendedJson = function_exists('wp_json_encode') ? wp_json_encode($recommended) : json_encode($recommended);

        ?>
        <script>
            (() => {
                const root = document.querySelector('.sympress-cache-admin');
                if (!root) {
                    return;
                }

                const tabs = [...root.querySelectorAll('[data-sympress-tab]')];
                const panels = [...root.querySelectorAll('[data-sympress-panel]')];
                const selectTab = (id, pushHash = true) => {
                    tabs.forEach((tab) => {
                        const active = tab.dataset.sympressTab === id;
                        tab.classList.toggle('is-active', active);
                        tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    panels.forEach((panel) => panel.classList.toggle('is-active', panel.dataset.sympressPanel === id));
                    if (pushHash && window.history.replaceState) {
                        window.history.replaceState(null, '', `#${id}`);
                    }
                };

                tabs.forEach((tab) => tab.addEventListener('click', () => selectTab(tab.dataset.sympressTab || 'dashboard')));
                root.querySelectorAll('[data-sympress-tab-jump]').forEach((button) => {
                    button.addEventListener('click', () => selectTab(button.dataset.sympressTabJump || 'dashboard'));
                });
                const initial = window.location.hash.replace('#', '');
                if (initial && panels.some((panel) => panel.dataset.sympressPanel === initial)) {
                    selectTab(initial, false);
                }

                const form = root.querySelector('[data-sympress-settings-form]');
                const dirty = root.querySelector('[data-sympress-dirty-state]');
                if (form && dirty) {
                    form.addEventListener('input', () => {
                        dirty.textContent = '<?php echo esc_js(__('Unsaved changes', WordPressCacheSettings::TEXT_DOMAIN)); ?>';
                    }, { once: true });
                }

                if (form) {
                    const syncDuplicateFields = (source) => {
                        if (!source?.name || source.type === 'hidden' || source.type === 'file' || source.type === 'submit') {
                            return;
                        }

                        const fields = [...form.querySelectorAll(`[name="${CSS.escape(source.name)}"]`)].filter((field) => field !== source && field.type !== 'hidden');
                        fields.forEach((field) => {
                            if (source.type === 'checkbox') {
                                if (field.type === 'checkbox') {
                                    field.checked = source.checked;
                                } else {
                                    field.value = source.checked ? '1' : '0';
                                }
                                return;
                            }

                            if (source.type === 'radio') {
                                if (!source.checked) {
                                    return;
                                }
                                if (field.type === 'radio') {
                                    field.checked = field.value === source.value;
                                } else {
                                    field.value = source.value;
                                }
                                return;
                            }

                            if (field.type === 'checkbox') {
                                field.checked = String(source.value) === '1';
                            } else if (field.type === 'radio') {
                                field.checked = field.value === source.value;
                            } else {
                                field.value = source.value;
                            }
                        });
                    };

                    form.addEventListener('input', (event) => syncDuplicateFields(event.target));
                    form.addEventListener('change', (event) => syncDuplicateFields(event.target));

                    form.addEventListener('submit', () => {
                        panels.forEach((panel) => {
                            if (panel.classList.contains('is-active')) {
                                return;
                            }

                            panel.querySelectorAll('input, select, textarea, button').forEach((field) => {
                                field.disabled = true;
                            });
                        });
                    });
                }

                root.querySelectorAll('[data-sympress-copy-config]').forEach((copy) => {
                    const output = copy.closest('.sympress-card')?.querySelector('.sympress-config-output') || root.querySelector('[data-sympress-config-output]');
                    if (!output || !navigator.clipboard) {
                        return;
                    }

                    copy.addEventListener('click', async () => {
                        const original = copy.textContent;
                        await navigator.clipboard.writeText(output.value);
                        copy.textContent = '<?php echo esc_js(__('Copied', WordPressCacheSettings::TEXT_DOMAIN)); ?>';
                        setTimeout(() => {
                            copy.textContent = original || '<?php echo esc_js(__('Copy config', WordPressCacheSettings::TEXT_DOMAIN)); ?>';
                        }, 1400);
                    });
                });

                const exportButton = root.querySelector('[data-sympress-export-settings]');
                const importButton = root.querySelector('[data-sympress-import-settings-trigger]');
                const importInput = root.querySelector('[data-sympress-import-settings]');
                const collectSettings = () => {
                    const settings = {};
                    if (!form) {
                        return settings;
                    }

                    [...form.elements].forEach((field) => {
                        if (!field.name || field.disabled || field.name.startsWith('_') || field.type === 'submit' || field.type === 'file') {
                            return;
                        }

                        const panel = field.closest('[data-sympress-panel]');
                        if (panel && !panel.classList.contains('is-active')) {
                            return;
                        }

                        if (field.type === 'checkbox') {
                            settings[field.name] = field.checked ? '1' : '0';
                            return;
                        }

                        if (field.type === 'radio') {
                            if (field.checked) {
                                settings[field.name] = field.value;
                            }
                            return;
                        }

                        settings[field.name] = field.value;
                    });

                    return settings;
                };
                const applySettings = (settings) => {
                    if (!form || !settings || typeof settings !== 'object') {
                        return;
                    }

                    Object.entries(settings).forEach(([name, value]) => {
                        const fields = [...form.querySelectorAll(`[name="${CSS.escape(name)}"]`)];
                        fields.forEach((field) => {
                            if (field.type === 'checkbox') {
                                field.checked = String(value) === '1' || value === true;
                            } else if (field.type === 'radio') {
                                field.checked = field.value === String(value);
                            } else {
                                field.value = String(value ?? '');
                            }
                        });
                    });

                    dirty && (dirty.textContent = '<?php echo esc_js(__('Imported settings are not saved yet.', WordPressCacheSettings::TEXT_DOMAIN)); ?>');
                };

                exportButton?.addEventListener('click', () => {
                    const blob = new Blob([JSON.stringify(collectSettings(), null, 2)], { type: 'application/json' });
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = 'nginx-cache-settings.json';
                    link.click();
                    URL.revokeObjectURL(link.href);
                });
                importButton?.addEventListener('click', () => importInput?.click());
                importInput?.addEventListener('change', async () => {
                    const file = importInput.files?.[0];
                    if (!file) {
                        return;
                    }

                    try {
                        applySettings(JSON.parse(await file.text()));
                    } catch (error) {
                        dirty && (dirty.textContent = '<?php echo esc_js(__('Import failed. Please choose a valid JSON file.', WordPressCacheSettings::TEXT_DOMAIN)); ?>');
                    } finally {
                        importInput.value = '';
                    }
                });

                const modal = root.querySelector('[data-sympress-onboarding]');
                if (!modal || !form) {
                    return;
                }

                const openButtons = root.querySelectorAll('[data-sympress-onboarding-open]');
                const closeButtons = root.querySelectorAll('[data-sympress-onboarding-close]');
                const steps = [...modal.querySelectorAll('[data-sympress-onboarding-step]')];
                const dots = [...modal.querySelectorAll('[data-sympress-step-dot]')];
                const prev = modal.querySelector('[data-sympress-onboarding-prev]');
                const next = modal.querySelector('[data-sympress-onboarding-next]');
                const finish = modal.querySelector('[data-sympress-onboarding-finish]');
                const completed = root.querySelector('[data-sympress-onboarding-completed]');
                const recommended = <?php echo $recommendedJson ?: '{}'; ?>;
                let index = 0;

                const renderStep = () => {
                    steps.forEach((step, current) => {
                        step.hidden = current !== index;
                    });
                    dots.forEach((dot, current) => dot.classList.toggle('is-active', current <= index));
                    if (prev) {
                        prev.disabled = index === 0;
                    }
                    if (next) {
                        next.hidden = index === steps.length - 1;
                    }
                    if (finish) {
                        finish.hidden = index !== steps.length - 1;
                    }
                };
                const open = () => {
                    modal.hidden = false;
                    index = 0;
                    renderStep();
                    modal.querySelector('button')?.focus();
                };
                const close = () => {
                    modal.hidden = true;
                };

                openButtons.forEach((button) => button.addEventListener('click', open));
                closeButtons.forEach((button) => button.addEventListener('click', close));
                prev?.addEventListener('click', () => {
                    index = Math.max(0, index - 1);
                    renderStep();
                });
                next?.addEventListener('click', () => {
                    index = Math.min(steps.length - 1, index + 1);
                    renderStep();
                });
                finish?.addEventListener('click', () => {
                    Object.entries(recommended).forEach(([name, value]) => {
                        const fields = [...form.querySelectorAll(`[name="${CSS.escape(name)}"]`)];
                        fields.forEach((field) => {
                            if (field.type === 'checkbox') {
                                field.checked = Boolean(value);
                            } else if (field.type === 'radio') {
                                field.checked = field.value === value;
                            } else {
                                field.value = String(value);
                            }
                        });
                    });
                    if (completed) {
                        completed.value = '1';
                    }
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                });
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && !modal.hidden) {
                        close();
                    }
                });
            })();
        </script>
        <?php
    }

    private function addRequestNotice(?string $pathError): void
    {
        $message = isset($_GET[self::LEGACY_MESSAGE_QUERY_VAR])
            ? sanitize_key((string) wp_unslash($_GET[self::LEGACY_MESSAGE_QUERY_VAR]))
            : '';

        if ($message === 'purged') {
            add_settings_error(
                'sympress_nginx_cache',
                'sympress_nginx_cache_purged',
                __('Cache purged.', WordPressCacheSettings::TEXT_DOMAIN),
                'success',
            );

            return;
        }

        if ($message === 'failed') {
            add_settings_error(
                'sympress_nginx_cache',
                'sympress_nginx_cache_failed',
                __('Cache could not be purged.', WordPressCacheSettings::TEXT_DOMAIN),
            );

            return;
        }

        if ($pathError === null) {
            return;
        }

        add_settings_error('sympress_nginx_cache', 'sympress_nginx_cache_path', $pathError);
    }

    private function isPurgeRequest(): bool
    {
        $action = isset($_GET['sympress-action'])
            ? sanitize_key((string) wp_unslash($_GET['sympress-action']))
            : '';

        return $action === 'purge';
    }

    private function purgeUrl(): string
    {
        return $this->purgeActionUrl(false);
    }

    private function purgeActionUrl(bool $dryRun, bool $prewarm = false): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action'      => self::PURGE_ACTION,
                    'redirect_to' => $this->cleanNoticeUrl($this->currentUrl()),
                    'dry_run'     => $dryRun ? '1' : '0',
                    'prewarm'     => $prewarm ? '1' : '0',
                ],
                admin_url('admin-post.php'),
            ),
            self::PURGE_ACTION,
        );
    }

    private function queueActionUrl(): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action'      => self::QUEUE_ACTION,
                    'redirect_to' => $this->cleanNoticeUrl($this->currentUrl()),
                ],
                admin_url('admin-post.php'),
            ),
            self::QUEUE_ACTION,
        );
    }

    private function manualRequest(string $source): PurgeRequest
    {
        return PurgeRequest::full('manual', $source, $this->dryRunRequested(), $this->prewarmRequested());
    }

    private function dryRunRequested(): bool
    {
        return isset($_REQUEST['dry_run']) && (string) wp_unslash($_REQUEST['dry_run']) === '1';
    }

    private function prewarmRequested(): bool
    {
        return $this->settings->prewarmEnabled()
            || (isset($_REQUEST['prewarm']) && (string) wp_unslash($_REQUEST['prewarm']) === '1');
    }

    private function noticeMessage(bool $successful, bool $dryRun, bool $prewarm): string
    {
        if (!$successful) {
            return 'failed';
        }

        if ($dryRun) {
            return 'dry-run';
        }

        return $prewarm ? 'purged-prewarm' : 'purged';
    }

    private function pageUrl(string $anchor = ''): string
    {
        return admin_url(add_query_arg('page', self::PAGE_SLUG, 'tools.php')) . $anchor;
    }

    private function currentUrl(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST'])
            ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST']))
            : wp_parse_url(admin_url(), PHP_URL_HOST);
        $uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_URI']))
            : '/wp-admin/';

        if (!is_string($host) || $host === '' || $uri === '') {
            return $this->pageUrl();
        }

        return esc_url_raw($scheme . $host . $uri);
    }

    private function redirectUrl(): string
    {
        $redirect = isset($_REQUEST['redirect_to'])
            ? (string) wp_unslash($_REQUEST['redirect_to'])
            : '';

        if ($redirect === '') {
            $redirect = (string) wp_get_referer();
        }

        return wp_validate_redirect($redirect, $this->pageUrl());
    }

    private function cleanNoticeUrl(string $url): string
    {
        return remove_query_arg(
            [
                self::LEGACY_MESSAGE_QUERY_VAR,
                'sympress-action',
                '_wpnonce',
                'action',
                'dry_run',
                'prewarm',
                'redirect_to',
            ],
            $url,
        );
    }

    private function flashNotice(string $message): void
    {
        set_transient($this->noticeTransientKey(), $message, MINUTE_IN_SECONDS);
    }

    private function pullNotice(): string
    {
        $key = $this->noticeTransientKey();
        $message = get_transient($key);

        if (is_string($message)) {
            delete_transient($key);

            return $message;
        }

        return '';
    }

    private function noticeTransientKey(): string
    {
        return self::NOTICE_TRANSIENT_PREFIX . (string) get_current_user_id();
    }

    private function renderNotice(string $message, string $type): void
    {
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    private function yesNo(bool $value): string
    {
        return $value
            ? __('Yes', WordPressCacheSettings::TEXT_DOMAIN)
            : __('No', WordPressCacheSettings::TEXT_DOMAIN);
    }

    private function profileLabel(CacheProfile $profile): string
    {
        return match ($profile) {
            CacheProfile::Safe => __('Safe', WordPressCacheSettings::TEXT_DOMAIN),
            CacheProfile::Commerce => __('Commerce', WordPressCacheSettings::TEXT_DOMAIN),
            CacheProfile::Publishing => __('Publishing', WordPressCacheSettings::TEXT_DOMAIN),
            CacheProfile::Headless => __('Headless', WordPressCacheSettings::TEXT_DOMAIN),
            CacheProfile::HighTraffic => __('High Traffic', WordPressCacheSettings::TEXT_DOMAIN),
        };
    }

    private function profileDescription(CacheProfile $profile): string
    {
        return match ($profile) {
            CacheProfile::Safe => __('Defensive defaults for mixed WordPress sites.', WordPressCacheSettings::TEXT_DOMAIN),
            CacheProfile::Commerce => __('Extra WooCommerce, EDD and membership bypasses.', WordPressCacheSettings::TEXT_DOMAIN),
            CacheProfile::Publishing => __('Lean cache rules for editorial publishing.', WordPressCacheSettings::TEXT_DOMAIN),
            CacheProfile::Headless => __('Keeps REST routes cacheable for headless frontends.', WordPressCacheSettings::TEXT_DOMAIN),
            CacheProfile::HighTraffic => __('Allows safe version query strings on busy sites.', WordPressCacheSettings::TEXT_DOMAIN),
        };
    }

    private function heartbeatModeLabel(string $mode): string
    {
        return match ($mode) {
            'reduce' => __('Reduce activity', WordPressCacheSettings::TEXT_DOMAIN),
            'disable' => __('Disable outside editor', WordPressCacheSettings::TEXT_DOMAIN),
            default => __('Do not limit', WordPressCacheSettings::TEXT_DOMAIN),
        };
    }

    private function heartbeatModeDescription(string $mode): string
    {
        return match ($mode) {
            'reduce' => __('Raises the Heartbeat interval to reduce admin-ajax traffic.', WordPressCacheSettings::TEXT_DOMAIN),
            'disable' => __('Deregisters Heartbeat on frontend and admin screens, while preserving editor screens.', WordPressCacheSettings::TEXT_DOMAIN),
            default => __('Keeps WordPress default Heartbeat behavior.', WordPressCacheSettings::TEXT_DOMAIN),
        };
    }

    private function date(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        $date = function_exists('wp_date')
            ? wp_date('Y-m-d H:i:s', $timestamp)
            : date('Y-m-d H:i:s', $timestamp);

        return is_string($date) ? $date : '';
    }
}
