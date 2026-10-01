<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymPress\NginxCache\Security\SecretCipher;

final class SecretCipherTest extends TestCase
{
    public function testCiphertextIsBoundToItsPurposeAndRejectsTamperingAndPlaintext(): void
    {
        $cipher = new SecretCipher();
        $stored = $cipher->encrypt('test-token', 'cloudflare');
        self::assertStringNotContainsString('test-token', $stored);
        self::assertSame('test-token', $cipher->decrypt($stored, 'cloudflare'));
        self::assertNull($cipher->decrypt($stored, 'remote'));
        self::assertNull($cipher->decrypt('test-token', 'cloudflare'));
        self::assertNull($cipher->decrypt(substr($stored, 0, -8) . 'AAAAAAAA', 'cloudflare'));
    }
}
