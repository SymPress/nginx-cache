<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Hook;

/**
 * WordPress sends no-store headers with every 404, so Nginx never stores it and the
 * generated `fastcgi_cache_valid 404 1m` rule stays inert; anonymous 404s without cookies get that minute.
 */
final class NotFoundCacheSubscriber
{
    public const string CACHE_CONTROL = 'public, max-age=60';

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function headers(array $headers): array
    {
        return $this->anonymousNotFound() ? $this->cacheable($headers) : $headers;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function cacheable(array $headers): array
    {
        unset($headers['Expires']);
        $headers['Cache-Control'] = self::CACHE_CONTROL;

        return $headers;
    }

    private function anonymousNotFound(): bool
    {
        if (!function_exists('is_404') || !is_404()) {
            return false;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only literal GET/HEAD methods pass the following allowlist; transforming input could broaden caching.
        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_user_logged_in()) {
            return false;
        }

        // A cookie may personalise the 404 (cart, membership, post password); a CDN must not share it.
        foreach (['HTTP_AUTHORIZATION', 'HTTP_COOKIE'] as $header) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Any nonempty header prevents caching; sanitizing could turn a private request into a cacheable one.
            if (isset($_SERVER[$header]) && is_string($_SERVER[$header]) && trim($_SERVER[$header]) !== '') {
                return false;
            }
        }

        return true;
    }
}
