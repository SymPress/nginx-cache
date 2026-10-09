<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

final readonly class TagIndexSettings
{
    public function __construct(private OptionSource $options = new OptionSource())
    {
    }

    public function urlsPerTag(): int
    {
        return $this->integer('tag_urls_per_tag', 50, 5000);
    }

    public function maxTags(): int
    {
        return $this->integer('tag_max_tags', 1000, 200000);
    }

    public function ttl(): int
    {
        return $this->integer('tag_ttl_seconds', CachePolicy::values()['inactive_seconds'], 604800);
    }

    private function integer(string $name, int $default, int $maximum): int
    {
        $value = filter_var($this->options->value(CompatibilitySettings::PREFIX . $name, $default), FILTER_VALIDATE_INT);
        return $value === false ? $default : max(1, min($maximum, $value));
    }

    public function register(): void
    {
        foreach (['tag_urls_per_tag' => [50, 5000], 'tag_max_tags' => [1000, 200000], 'tag_ttl_seconds' => [CachePolicy::values()['inactive_seconds'], 604800]] as $name => [$default, $maximum]) {
            register_setting('sympress_nginx_cache', CompatibilitySettings::PREFIX . $name, ['type' => 'integer', 'default' => $default, 'sanitize_callback' => static fn (mixed $value): int => max(1, min($maximum, (int) $value))]);
        }
    }
}
