<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Security\UrlPolicy;

final class UrlPolicyTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    public function testItAllowsSameOriginUrls(): void
    {
        $policy = new UrlPolicy();

        self::assertSame(
            'https://example.test/path?x=1',
            $policy->normalizeSameOriginHttpUrl('https://example.test/path?x=1'),
        );
    }

    public function testItRejectsCrossOriginUrls(): void
    {
        $policy = new UrlPolicy();

        self::assertSame('', $policy->normalizeSameOriginHttpUrl('https://attacker.test/path'));
    }

    public function testItRejectsPrivateRemoteEndpointsByDefault(): void
    {
        $policy = new UrlPolicy();

        self::assertSame('', $policy->normalizeRemoteEndpoint('https://127.0.0.1/purge'));
        self::assertSame('', $policy->normalizeRemoteEndpoint('https://[::1]/purge'));
    }

    public function testItTreatsOnlyGloballyRoutableAddressesAsSafeNetworkTargets(): void
    {
        $policy = new UrlPolicy();
        $unsafe = new \ReflectionMethod(UrlPolicy::class, 'isUnsafeIp');

        foreach (['100.100.100.200', '198.18.0.1', '192.0.0.8', '224.0.0.1', '64:ff9b::a9fe:a9fe', '64:ff9b:1::808:808', '2002:7f00:1::', 'fec0::1', 'ff02::1', '10.0.0.1', '::1'] as $ip) {
            self::assertTrue($unsafe->invoke($policy, $ip), $ip);
        }
        foreach (['8.8.8.8', '2606:4700:4700::1111', '64:ff9b::808:808'] as $ip) {
            self::assertFalse($unsafe->invoke($policy, $ip), $ip);
        }
        self::assertTrue($policy->hasUnsafeNetworkTarget('https://100.100.100.200/purge'));
    }

    public function testItRejectsUnsafeUrlSyntax(): void
    {
        $policy = new UrlPolicy();

        self::assertSame('', $policy->normalizeSameOriginHttpUrl("https://example.test/\r\nHost: evil.test"));
        self::assertSame('', $policy->normalizeSameOriginHttpUrl('https://user:pass@example.test/'));
        self::assertSame('', $policy->normalizeRemoteEndpoint('http://example.test/purge'));
    }
}
