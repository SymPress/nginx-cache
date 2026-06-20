<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Hook;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Surrogate\CacheTagResolver;
use SymPress\NginxCache\Surrogate\TagIndexRepository;

final class SurrogateTagSubscriber
{
    /** @var list<string>|null */
    private ?array $tags = null;

    public function __construct(
        private readonly WordPressCacheSettings $settings,
        private readonly CacheTagResolver $resolver,
        private readonly TagIndexRepository $index,
    ) {
    }

    public function sendHeaders(): void
    {
        if (!$this->settings->debugHeadersEnabled() || headers_sent()) {
            return;
        }

        $tags = $this->tags();

        if ($tags === []) {
            return;
        }

        $headerValue = implode(' ', $tags);
        header(sprintf('Surrogate-Key: %s', $headerValue), false);
        header(sprintf('X-SymPress-Cache-Tags: %s', $headerValue), false);
    }

    public function remember(): void
    {
        if (!$this->settings->tagIndexEnabled() || !$this->shouldRemember()) {
            return;
        }

        $tags = $this->tags();
        $url = $this->currentUrl();

        if ($tags === [] || $url === '') {
            return;
        }

        $this->index->remember($url, $tags);
    }

    /** @return list<string> */
    private function tags(): array
    {
        if ($this->tags === null) {
            $this->tags = $this->resolver->currentTags();
        }

        return $this->tags;
    }

    private function shouldRemember(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if (function_exists('is_admin') && is_admin()) {
            return false;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return false;
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return false;
        }

        if (function_exists('is_preview') && is_preview()) {
            return false;
        }

        $status = (int) http_response_code();

        if (!in_array($status, [200, 301], true)) {
            return false;
        }

        return !$this->requestHasAuthorization() && !$this->responseDisablesCaching();
    }

    private function currentUrl(): string
    {
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $uri = function_exists('wp_unslash') ? (string) wp_unslash($uri) : $uri;

        if (function_exists('home_url')) {
            return (string) home_url($uri);
        }

        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
        $host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : '';

        return $host !== '' ? $scheme . $host . $uri : '';
    }

    private function requestHasAuthorization(): bool
    {
        return isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION']) && trim($_SERVER['HTTP_AUTHORIZATION']) !== '';
    }

    private function responseDisablesCaching(): bool
    {
        if (!function_exists('headers_list')) {
            return false;
        }

        foreach (headers_list() as $header) {
            $header = strtolower($header);

            if (str_starts_with($header, 'set-cookie:')) {
                return true;
            }

            if (
                str_starts_with($header, 'cache-control:')
                && (str_contains($header, 'private') || str_contains($header, 'no-store') || str_contains($header, 'no-cache'))
            ) {
                return true;
            }
        }

        return false;
    }
}
