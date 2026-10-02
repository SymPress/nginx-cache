<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Value\QueueRetryState;

final class QueueRetryStateTest extends TestCase
{
    public function testRetriesWaitWithCappedBackoffAndStopAfterFiveAttempts(): void
    {
        $state = new QueueRetryState();
        $now = 1000;
        foreach ([60, 120, 240, 300, 300] as $delay) {
            self::assertTrue($state->ready($now));
            $state = $state->reserve($now);
            self::assertSame($now + $delay, $state->retryAt);
            self::assertFalse($state->ready($now));
            self::assertSame($state->toArray(), QueueRetryState::fromArray($state->toArray())->toArray());
            $now += $delay;
        }
        self::assertTrue($state->exhausted());
        self::assertFalse($state->ready($now + 100000));
    }
}
