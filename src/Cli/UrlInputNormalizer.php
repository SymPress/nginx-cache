<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Cli;

use SymPress\NginxCache\Security\UrlPolicy;

final readonly class UrlInputNormalizer
{
    public function __construct(
        private UrlPolicy $urls,
    ) {
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @return list<string>
     */
    public function urls(array $args, array $assocArgs): array
    {
        $urls = [];

        foreach ($args as $arg) {
            $url = $this->urls->normalizeSameOriginHttpUrl($arg);

            if ($url === '') {
                continue;
            }

            $urls[] = $url;
        }

        foreach (['url', 'urls'] as $key) {
            $value = $assocArgs[$key] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $urls = [...$urls, ...(preg_split('/[\s,]+/', $value) ?: [])];
        }

        return array_slice(
            array_values(
                array_unique(
                    array_filter(
                        array_map($this->urls->normalizeSameOriginHttpUrl(...), $urls),
                        static fn (string $url): bool => $url !== '',
                    ),
                ),
            ),
            0,
            500,
        );
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function hasProvidedUrls(array $args, array $assocArgs): bool
    {
        if ($args !== []) {
            return true;
        }

        foreach (['url', 'urls'] as $key) {
            if (isset($assocArgs[$key]) && is_string($assocArgs[$key]) && trim($assocArgs[$key]) !== '') {
                return true;
            }
        }

        return false;
    }
}
