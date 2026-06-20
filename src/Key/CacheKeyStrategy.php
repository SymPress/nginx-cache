<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Key;

final readonly class CacheKeyStrategy
{
    public const string TEMPLATE = '$scheme|$request_method|$host|$request_uri';

    public function template(): string
    {
        $template = self::TEMPLATE;

        if (function_exists('apply_filters')) {
            $template = (string) apply_filters('sympress_nginx_cache_key_template', $template);
        }

        return trim($template) !== '' ? trim($template) : self::TEMPLATE;
    }

    /** @return list<array{scheme: string, forwarded_protocol: string, method: string, host: string, uri: string, key: string}> */
    public function candidates(string $url): array
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);

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
        return sprintf('%s|%s|%s|%s', strtolower($scheme), strtoupper($method), strtolower($host), $uri);
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

        return $path . $query;
    }
}
