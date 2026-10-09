<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Settings;

final readonly class OptionSource
{
    public function __construct(private NetworkSettings $network = new NetworkSettings())
    {
    }

    public function value(string $option, mixed $default = false): mixed
    {
        if ($this->network->managed($option)) {
            return $this->network->value($option, $default);
        }
        $missing = new \stdClass();
        $site = function_exists('get_option') ? get_option($option, $missing) : $missing;
        return $site !== $missing ? $site : $this->network->value($option, $default);
    }

    public function protectUpdate(mixed $value, string $option, mixed $oldValue): mixed
    {
        return $this->network->managed($option) ? $oldValue : $value;
    }

    public function managed(string $option): bool
    {
        return $this->network->managed($option);
    }
}
