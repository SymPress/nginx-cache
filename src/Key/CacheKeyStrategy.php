<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Key;

use SymPress\NginxCache\Settings\OptionSource;

final readonly class CacheKeyStrategy
{
    public const string TEMPLATE = '$scheme|$request_method|$host|$request_uri';
    public const string TRACKING_QUERY_PATTERN = '^(?:utm_[A-Za-z0-9_]+|gclid|fbclid|msclkid)=[^&]*(?:&(?:utm_[A-Za-z0-9_]+|gclid|fbclid|msclkid)=[^&]*)*$';

    public function template(): string
    {
        $stored = (new OptionSource())->value('sympress_nginx_cache_key_template', self::TEMPLATE);
        $template = defined('SYMPRESS_NGINX_CACHE_KEY_TEMPLATE') ? (string) SYMPRESS_NGINX_CACHE_KEY_TEMPLATE : (is_string($stored) ? $stored : self::TEMPLATE);

        if (function_exists('apply_filters')) {
            $template = (string) apply_filters('sympress_nginx_cache_key_template', $template);
        }

        return trim($template) !== '' ? trim($template) : self::TEMPLATE;
    }

    /** @return array{scheme: string, method: string, host: string, uri: string}|null */
    public function parseKey(string $key): ?array
    {
        $template = $this->template();
        foreach (['$scheme', '$request_method', '$host', '$request_uri'] as $token) {
            if (substr_count($template, $token) !== 1) {
                return null;
            }
        }
        $pattern = str_replace(
            array_map(static fn (string $token): string => preg_quote($token, '~'), ['$scheme', '$request_method', '$host', '$request_uri']),
            ['(?P<scheme>https?)', '(?P<method>GET|HEAD)', '(?P<host>[a-zA-Z0-9.-]+(?::[0-9]+)?)', '(?P<uri>/[^\\r\\n]*)'],
            preg_quote($template, '~'),
        );
        if (preg_match('~\\A' . $pattern . '\\z~D', $key, $match) !== 1) {
            return null;
        }
        return ['scheme' => $match['scheme'], 'method' => $match['method'], 'host' => strtolower($match['host']), 'uri' => $match['uri']];
    }

    /** @return list<array{scheme: string, forwarded_protocol: string, method: string, host: string, uri: string, key: string}> */
    public function candidates(string $url): array
    {
        $parts = $this->parseUrl($url);

        if (!is_array($parts)) {
            return [];
        }

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : 'https';
        $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';

        if ($host === '') {
            return [];
        }

        $uri = $this->requestUri($parts);
        $candidates = [];

        foreach ($this->keyParts($scheme, $host, $uri) as $candidate) {
            $candidates[] = [
                ...$candidate,
                'key' => $this->formatKey(
                    $candidate['scheme'],
                    $candidate['method'],
                    $candidate['host'],
                    $candidate['uri'],
                ),
            ];
        }

        foreach ($this->legacyKeyParts($scheme, $host, $uri) as $candidate) {
            $candidates[] = [
                ...$candidate,
                'key' => sprintf(
                    '%s|%s|%s|%s|%s',
                    $candidate['forwarded_protocol'],
                    $candidate['scheme'],
                    $candidate['method'],
                    $candidate['host'],
                    $candidate['uri'],
                ),
            ];
        }

        if (function_exists('apply_filters')) {
            $keys = array_map(static fn (array $candidate): string => $candidate['key'], $candidates);
            $filteredKeys = (array) apply_filters('sympress_nginx_cache_keys', $keys, $scheme, $host, $uri);

            if ($filteredKeys !== $keys) {
                $candidates = $this->candidatesFromKeys($filteredKeys);
            }

            $filtered = (array) apply_filters('sympress_nginx_cache_key_candidates', $candidates, $scheme, $host, $uri);
            $candidates = $this->normalizeKeyCandidates($filtered);
        }

        return $candidates;
    }

    public function formatKey(string $scheme, string $method, string $host, string $uri): string
    {
        return strtr($this->template(), ['$scheme' => strtolower($scheme), '$request_method' => strtoupper($method), '$host' => strtolower($host), '$request_uri' => $uri]);
    }

    /** @return list<array{scheme: string, forwarded_protocol: string, method: string, host: string, uri: string}> */
    private function keyParts(string $scheme, string $host, string $uri): array
    {
        $candidates = [];
        $schemes = array_values(array_unique([$scheme, 'https', 'http']));

        foreach (['GET', 'HEAD'] as $method) {
            foreach ($schemes as $serverScheme) {
                $candidates[] = [
                    'scheme'             => $serverScheme,
                    'forwarded_protocol' => '',
                    'method'             => $method,
                    'host'               => $host,
                    'uri'                => $uri,
                ];
            }
        }

        return $candidates;
    }

    /** @return list<array{scheme: string, forwarded_protocol: string, method: string, host: string, uri: string}> */
    private function legacyKeyParts(string $scheme, string $host, string $uri): array
    {
        $candidates = [];
        $schemes = array_values(array_unique([$scheme, 'https', 'http']));
        $forwardedProtocols = ['', 'https', 'http'];

        foreach (['GET', 'HEAD'] as $method) {
            foreach ($schemes as $serverScheme) {
                foreach ($forwardedProtocols as $forwardedProtocol) {
                    $candidates[] = [
                        'scheme'             => $serverScheme,
                        'forwarded_protocol' => $forwardedProtocol,
                        'method'             => $method,
                        'host'               => $host,
                        'uri'                => $uri,
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<mixed> $keys
     * @return list<array{scheme: string, forwarded_protocol: string, method: string, host: string, uri: string, key: string}>
     */
    private function candidatesFromKeys(array $keys): array
    {
        return array_map(
            static fn (string $key): array => [
                'scheme'             => '',
                'forwarded_protocol' => '',
                'method'             => '',
                'host'               => '',
                'uri'                => '',
                'key'                => $key,
            ],
            array_values(
                array_filter(
                    array_map(static fn (mixed $key): string => is_string($key) ? trim($key) : '', $keys),
                    static fn (string $key): bool => $key !== '',
                ),
            ),
        );
    }

    /**
     * @param array<mixed> $candidates
     * @return list<array{scheme: string, forwarded_protocol: string, method: string, host: string, uri: string, key: string}>
     */
    private function normalizeKeyCandidates(array $candidates): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function (mixed $candidate): array {
                            if (!is_array($candidate) || !is_string($candidate['key'] ?? null)) {
                                return [];
                            }

                            return [
                                'scheme'             => is_string($candidate['scheme'] ?? null) ? $candidate['scheme'] : '',
                                'forwarded_protocol' => is_string($candidate['forwarded_protocol'] ?? null) ? $candidate['forwarded_protocol'] : '',
                                'method'             => is_string($candidate['method'] ?? null) ? $candidate['method'] : '',
                                'host'               => is_string($candidate['host'] ?? null) ? $candidate['host'] : '',
                                'uri'                => is_string($candidate['uri'] ?? null) ? $candidate['uri'] : '',
                                'key'                => $candidate['key'],
                            ];
                        },
                        $candidates,
                    ),
                    static fn (array $candidate): bool => isset($candidate['key']) && $candidate['key'] !== '',
                ),
                SORT_REGULAR,
            ),
        );
    }

    /** @param array<string, mixed> $parts */
    private function requestUri(array $parts): string
    {
        $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = is_string($parts['query'] ?? null) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        if ($query !== '' && preg_match('/' . self::TRACKING_QUERY_PATTERN . '/D', substr($query, 1)) === 1) {
            $query = '';
        }

        return $path . $query;
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
