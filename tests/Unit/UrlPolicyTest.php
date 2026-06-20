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

    public function testItRejectsUnsafeUrlSyntax(): void
    {
        $policy = new UrlPolicy();

        self::assertSame('', $policy->normalizeSameOriginHttpUrl("https://example.test/\r\nHost: evil.test"));
        self::assertSame('', $policy->normalizeSameOriginHttpUrl('https://user:pass@example.test/'));
        self::assertSame('', $policy->normalizeRemoteEndpoint('http://example.test/purge'));
    }
}
