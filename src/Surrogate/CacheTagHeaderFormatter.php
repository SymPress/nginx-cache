<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Surrogate;

use SymPress\NginxCache\Settings\WordPressCacheSettings;

final readonly class CacheTagHeaderFormatter
{
    public function __construct(
        private CacheTagResolver $resolver,
        private WordPressCacheSettings $settings,
    ) {
    }

    /** @param array<mixed> $tags */
    public function value(array $tags): string
    {
        return implode(' ', $this->tags($tags));
    }

    /** @param array<mixed> $tags */
    public function cloudflareValue(array $tags): string
    {
        return implode(',', $this->tagsWithin($tags, $this->settings->maxCloudflareHeaderLength(), ','));
    }

    /**
     * @param array<mixed> $tags
     * @return list<string>
     */
    public function tags(array $tags): array
    {
        return $this->tagsWithin($tags, $this->settings->maxSurrogateHeaderLength(), ' ');
    }

    /**
     * @param array<mixed> $tags
     * @return list<string>
     */
    private function tagsWithin(array $tags, int $limit, string $separator): array
    {
        $tags = $this->resolver->normalize($tags);

        if ($this->length($tags, $separator) <= $limit) {
            return $tags;
        }

        return $this->compactTags($tags, $limit, $separator);
    }

    /** @param list<string> $tags */
    private function length(array $tags, string $separator): int
    {
        return strlen(implode($separator, $tags));
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private function compactTags(array $tags, int $limit, string $separator): array
    {
        $groups = [];

        foreach ($tags as $tag) {
            $groups[$this->groupPrefix($tag)][] = $tag;
        }

        uasort(
            $groups,
            static fn (array $left, array $right): int => strlen(implode(' ', $right)) <=> strlen(implode(' ', $left)),
        );

        foreach (array_keys($groups) as $prefix) {
            $groups[$prefix] = [$prefix . 'huge'];
            $compact = array_values(array_unique(array_merge(...array_values($groups))));

            if ($this->length($compact, $separator) <= $limit) {
                return $compact;
            }
        }

        return array_slice(
            $this->resolver->normalize(array_map(static fn (string $prefix): string => $prefix . 'huge', array_keys($groups))),
            0,
            100,
        );
    }

    private function groupPrefix(string $tag): string
    {
        $positions = array_filter(
            [strrpos($tag, ':'), strrpos($tag, '-')],
            'is_int',
        );
        $position = $positions === [] ? false : max($positions);

        if ($position === false) {
            return $tag . ':';
        }

        return substr($tag, 0, $position + 1);
    }
}
