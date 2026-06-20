<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Inspection\ResponseCacheability;

final class ResponseCacheabilityTest extends TestCase
{
    public function testItDetectsHeadersThatPreventNginxCaching(): void
    {
        $reasons = (new ResponseCacheability())->blockingReasons([
            'cache-control' => ['private, no-cache, max-age=0, must-revalidate'],
            'x-accel-expires' => ['0'],
        ], 200);

        self::assertContains('cache-control:private', $reasons);
        self::assertContains('cache-control:no-cache', $reasons);
        self::assertContains('x-accel-expires:0', $reasons);
    }

    public function testItAcceptsConfiguredCacheableStatuses(): void
    {
        self::assertSame([], (new ResponseCacheability())->blockingReasons([
            'cache-control' => ['public, max-age=600'],
        ], 200));
    }
}
