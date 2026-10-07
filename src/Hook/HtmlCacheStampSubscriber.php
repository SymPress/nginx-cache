<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Hook;

use SymPress\NginxCache\Settings\CompatibilitySettings;
use SymPress\NginxCache\Time\CacheClock;

final readonly class HtmlCacheStampSubscriber
{
    public function __construct(
        private CompatibilitySettings $settings,
        private CacheClock $clock,
    ) {
    }

    public function render(): void
    {
        if (
            $this->settings->integer('html_stamp') === 0 || PHP_SAPI === 'cli' || is_admin()
            || is_feed() || is_trackback() || is_user_logged_in()
            || in_array($GLOBALS['pagenow'] ?? '', ['wp-login.php', 'wp-register.php'], true)
        ) {
            return;
        }
        foreach (['DOING_AJAX', 'DOING_CRON', 'REST_REQUEST', 'XMLRPC_REQUEST', 'WP_CLI'] as $constant) {
            if (defined($constant) && constant($constant)) {
                return;
            }
        }
        $started = is_numeric($_SERVER['REQUEST_TIME_FLOAT'] ?? null) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $this->clock->highResolutionTimestamp();
        echo $this->comment(headers_list(), true, get_num_queries(), $this->clock->elapsedSince($started));
    }

    /** @param list<string> $headers */
    public function comment(array $headers, bool $publicHtml, int $queries, float $seconds): string
    {
        if (!$publicHtml || $this->settings->integer('html_stamp') === 0) {
            return '';
        }
        $html = false;
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') !== 0) {
                continue;
            }

            $html = preg_match('#^Content-Type:\s*text/html(?:\s*;|\s*$)#i', $header) === 1;
        }
        if (!$html) {
            return '';
        }
        return sprintf("\n<!-- Rendered by SymPress Nginx Cache at %s UTC; %d queries in %.3f seconds. -->\n", gmdate('Y-m-d H:i:s', $this->clock->timestamp()), max(0, $queries), is_finite($seconds) ? max(0.0, $seconds) : 0.0);
    }
}
