<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Security;

final readonly class SecretCipher
{
    public const string PREFIX = 'sympress-secret:v1:';

    public function encrypt(string $secret, string $purpose): string
    {
        $key = $this->key();
        if ($key === null || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException('Secret encryption is unavailable. Configure a private encryption key.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding authenticated binary ciphertext for option storage.
        return self::PREFIX . base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, $purpose, $nonce, $key));
    }

    public function decrypt(?string $stored, string $purpose): ?string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX) || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            return null;
        }
        $key = $this->key();
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding authenticated binary ciphertext, never executable code.
        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($key === null || $payload === false || strlen($payload) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }
        $size = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        try {
            $secret = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($payload, $size), $purpose, substr($payload, 0, $size), $key);
        } catch (\Throwable) {
            return null;
        }
        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function key(): ?string
    {
        $material = defined('SYMPRESS_NGINX_CACHE_ENCRYPTION_KEY') ? constant('SYMPRESS_NGINX_CACHE_ENCRYPTION_KEY') : null;
        if ($material === null && defined('AUTH_KEY') && defined('SECURE_AUTH_SALT')) {
            $auth = constant('AUTH_KEY');
            $salt = constant('SECURE_AUTH_SALT');
            if (is_string($auth) && is_string($salt) && strlen($auth) >= 32 && strlen($salt) >= 32 && !str_contains($auth . $salt, 'put your unique phrase here')) {
                $material = $auth . $salt;
            }
        }
        return is_string($material) && strlen($material) >= 32 ? hash('sha256', 'sympress-nginx-cache:v1:' . $material, true) : null;
    }
}
