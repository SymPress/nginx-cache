<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

final readonly class CachePresets
{
    public function __construct(private NetworkConfiguration $configuration, private OptionSource $options)
    {
    }

    /** @return array<string, int|string> */
    public function values(string $preset): array
    {
        if (!in_array($preset, ['small', 'standard', 'large'], true)) {
            throw new \InvalidArgumentException('Unknown cache preset.');
        }
        $large = $preset === 'large';
        return [
            CompatibilitySettings::PREFIX . 'purge_backend'         => 'local_files',
            WordPressCacheSettings::OPTION_FULL_PURGE_MODE          => 'local_files',
            WordPressCacheSettings::OPTION_SELECTIVE_PURGE          => 1,
            WordPressCacheSettings::OPTION_QUEUE_ENABLED            => $preset === 'small' ? 0 : 1,
            WordPressCacheSettings::OPTION_DEBOUNCE_SECONDS         => match ($preset) {
            'small' => 0, 'standard' => 5, 'large' => 15
            },
            WordPressCacheSettings::OPTION_PREWARM_ENABLED          => 1,
            WordPressCacheSettings::OPTION_PREWARM_URLS             => home_url('/'),
            CompatibilitySettings::PREFIX . 'prewarm_sitemap'       => $large ? 0 : 1,
            CompatibilitySettings::PREFIX . 'prewarm_affected_only' => $large ? 1 : 0,
            WordPressCacheSettings::OPTION_TAG_INDEX_ENABLED        => $preset === 'small' ? 0 : 1,
            CompatibilitySettings::PREFIX . 'tag_urls_per_tag'      => $large ? 500 : 50,
            CompatibilitySettings::PREFIX . 'tag_max_tags'          => $large ? 50000 : 1000,
        ];
    }

    /** @return list<array{option: string, current: mixed, value: mixed, managed: bool}> */
    public function preview(string $preset): array
    {
        $defaults = ConfigurationCatalog::defaults();
        $report = [];
        foreach ($this->values($preset) as $option => $value) {
            $report[] = ['option' => $option, 'current' => $this->options->value($option, $defaults[$option]), 'value' => $value, 'managed' => $this->options->locked($option)];
        }
        return $report;
    }

    public function apply(string $preset): void
    {
        $this->configuration->register();
        $values = [];
        foreach ($this->values($preset) as $option => $value) {
            if ($this->options->locked($option)) {
                continue;
            }

            $values[$option] = $this->configuration->sanitize($option, $value);
        }
        foreach ($values as $option => $value) {
            update_option($option, $value, false);
        }
    }
}
