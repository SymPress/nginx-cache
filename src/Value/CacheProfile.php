<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Value;

enum CacheProfile: string
{
    case Safe = 'safe';
    case Commerce = 'commerce';
    case Publishing = 'publishing';
    case Headless = 'headless';
    case HighTraffic = 'high-traffic';

    public static function fromString(string $profile): self
    {
        return self::tryFrom($profile) ?? self::Safe;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $profile): string => $profile->value, self::cases());
    }
}
