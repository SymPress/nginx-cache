<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Time;

use Symfony\Component\Clock\ClockInterface;

final readonly class CacheClock
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function timestamp(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    public function highResolutionTimestamp(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    public function elapsedSince(float $startedAt): float
    {
        return max(0.0, $this->highResolutionTimestamp() - $startedAt);
    }

    public function sleepMicroseconds(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        $this->clock->sleep($microseconds / 1_000_000);
    }
}
