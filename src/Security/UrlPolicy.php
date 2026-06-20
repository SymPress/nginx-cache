<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Security;

final readonly class UrlPolicy
{
    /** @param list<string> $extraHosts */
    public function normalizeSameOriginHttpUrl(mixed $url, array $extraHosts = []): string
    {
        $normalized = $this->normalizeHttpUrl($url, false);

        if ($normalized === '' || !$this->isAllowedOrigin($normalized, $extraHosts)) {
            return '';
        }

        return $normalized;
    }

    public function normalizeRemoteEndpoint(mixed $url): string
    {
        $normalized = $this->normalizeHttpUrl($url, true);

        if ($normalized === '') {
            return '';
        }

        $host = $this->hostFromUrl($normalized);

        if ($host === '' || !$this->remoteHostAllowed($host)) {
            return '';
        }

        if (!$this->privateRemoteEndpointsAllowed() && $this->hasUnsafeNetworkTarget($normalized)) {
            return '';
        }

        return $normalized;
    }

    public function resolvedRemoteAddress(string $url): ?string
    {
        $url = $this->normalizeRemoteEndpoint($url);

        if ($url === '') {
            return null;
        }

        $host = $this->hostFromUrl($url);

        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !$this->privateRemoteEndpointsAllowed() && $this->isUnsafeIp($host) ? null : $host;
        }

        $addresses = $this->resolveHost($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (!$this->privateRemoteEndpointsAllowed() && $this->isUnsafeIp($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    public function hasUnsafeNetworkTarget(string $url): bool
    {
        $parts = $this->parseUrl($url);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';

        if ($host === '') {
            return true;
        }

        if ($this->isLocalHostname($host)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isUnsafeIp($host);
        }

        $addresses = $this->resolveHost($host);

        if ($addresses === []) {
            return true;
        }

        foreach ($addresses as $address) {
            if ($this->isUnsafeIp($address)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHttpUrl(mixed $url, bool $requireHttps): string
    {
        if (!is_string($url)) {
            return '';
        }

        $url = trim($url);

        if ($url === '' || preg_match('/[\r\n]/', $url) === 1) {
            return '';
        }

        if (function_exists('esc_url_raw')) {
            $url = (string) esc_url_raw($url);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parts = $this->parseUrl($url);

        if (!is_array($parts)) {
            return '';
        }

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';
        $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        if ($requireHttps && $scheme !== 'https') {
            return '';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = is_string($parts['query'] ?? null) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $port = is_int($parts['port'] ?? null) ? ':' . (string) $parts['port'] : '';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $port, $path, $query);
    }

    /** @param list<string> $extraHosts */
    private function isAllowedOrigin(string $url, array $extraHosts): bool
    {
        $target = $this->originParts($url);

        if ($target === null) {
            return false;
        }

        foreach ($this->allowedOrigins($extraHosts) as $origin) {
            if ($target === $origin) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $extraHosts
     * @return list<array{scheme: string, host: string, port: int}>
     */
    private function allowedOrigins(array $extraHosts): array
    {
        $origins = [];
        $urls = [];

        if (function_exists('home_url')) {
            $urls[] = (string) home_url('/');
        }

        if (function_exists('site_url')) {
            $urls[] = (string) site_url('/');
        }

        if (is_string($_SERVER['HTTP_HOST'] ?? null) && $_SERVER['HTTP_HOST'] !== '') {
            $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $urls[] = sprintf('%s://%s/', $scheme, (string) $_SERVER['HTTP_HOST']);
        }

        if (function_exists('apply_filters')) {
            $extraHosts = (array) apply_filters('sympress_nginx_cache_allowed_url_hosts', $extraHosts);
        }

        foreach ($urls as $url) {
            $origin = $this->originParts($url);

            if ($origin === null) {
                continue;
            }

            $origins[] = $origin;
        }

        foreach ($extraHosts as $host) {
            if (!is_string($host) || trim($host) === '') {
                continue;
            }

            foreach (['https', 'http'] as $scheme) {
                $origins[] = [
                    'scheme' => $scheme,
                    'host'   => strtolower(trim($host)),
                    'port'   => $scheme === 'https' ? 443 : 80,
                ];
            }
        }

        return array_values(array_unique($origins, SORT_REGULAR));
    }

    /** @return array{scheme: string, host: string, port: int}|null */
    private function originParts(string $url): ?array
    {
        $parts = $this->parseUrl($url);

        if (!is_array($parts)) {
            return null;
        }

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';
        $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host'   => $host,
            'port'   => is_int($parts['port'] ?? null) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80),
        ];
    }

    private function privateRemoteEndpointsAllowed(): bool
    {
        $allowed = $this->constantBool('SYMPRESS_NGINX_CACHE_ALLOW_PRIVATE_REMOTE_ENDPOINTS', false);

        if (function_exists('apply_filters')) {
            $allowed = (bool) apply_filters('sympress_nginx_cache_allow_private_remote_endpoints', $allowed);
        }

        return $allowed;
    }

    private function constantBool(string $constant, bool $default): bool
    {
        if (!defined($constant)) {
            return $default;
        }

        return filter_var(constant($constant), FILTER_VALIDATE_BOOL);
    }

    private function isLocalHostname(string $host): bool
    {
        return in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local');
    }

    private function remoteHostAllowed(string $host): bool
    {
        $allowedHosts = $this->allowedRemoteHosts();

        if ($allowedHosts === []) {
            return true;
        }

        return in_array(strtolower($host), $allowedHosts, true);
    }

    /** @return list<string> */
    private function allowedRemoteHosts(): array
    {
        $hosts = $this->stringsFromConstant('SYMPRESS_NGINX_CACHE_REMOTE_HOSTS');

        if (function_exists('apply_filters')) {
            $hosts = (array) apply_filters('sympress_nginx_cache_remote_allowed_hosts', $hosts);
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $host): string => is_string($host) ? strtolower(trim($host)) : '',
                        $hosts,
                    ),
                    static fn (string $host): bool => $host !== '',
                ),
            ),
        );
    }

    /** @return list<string> */
    private function stringsFromConstant(string $constant): array
    {
        if (!defined($constant)) {
            return [];
        }

        $value = constant($constant);

        if (is_array($value)) {
            return array_values(
                array_filter(
                    array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
                    static fn (string $item): bool => $item !== '',
                ),
            );
        }

        if (!is_scalar($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map('trim', preg_split('/[\s,]+/', (string) $value) ?: []),
                static fn (string $item): bool => $item !== '',
            ),
        );
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (!is_string($record[$key] ?? null) || !filter_var($record[$key], FILTER_VALIDATE_IP)) {
                    continue;
                }

                $addresses[] = $record[$key];
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isUnsafeIp(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function hostFromUrl(string $url): string
    {
        $parts = $this->parseUrl($url);

        return is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
    }

    /** @return array<string, mixed>|false */
    private function parseUrl(string $url): array|false
    {
        if (function_exists('wp_parse_url')) {
            return wp_parse_url($url);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when WordPress is not loaded.
        return parse_url($url);
    }
}
