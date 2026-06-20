<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Support;

final readonly class OptionMutex
{
    private const string PREFIX = 'sympress_nginx_cache_lock_';
    private const int TTL_SECONDS = 30;
    private const int ATTEMPTS = 50;
    private const int WAIT_MICROSECONDS = 50000;

    public function synchronized(string $name, callable $callback): mixed
    {
        if (!function_exists('add_option') || !function_exists('delete_option') || !function_exists('get_option')) {
            return $callback();
        }

        $option = self::PREFIX . $this->normalizeName($name);
        $token = $this->acquire($option);

        if ($token === null) {
            return $callback();
        }

        try {
            return $callback();
        } finally {
            $this->release($option, $token);
        }
    }

    private function acquire(string $option): ?string
    {
        $token = bin2hex(random_bytes(16));

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            if (add_option($option, ['token' => $token, 'expires' => time() + self::TTL_SECONDS], '', false)) {
                return $token;
            }

            $lock = get_option($option);

            if (is_array($lock) && (int) ($lock['expires'] ?? 0) < time()) {
                delete_option($option);

                continue;
            }

            usleep(self::WAIT_MICROSECONDS);
        }

        return null;
    }

    private function release(string $option, string $token): void
    {
        $lock = get_option($option);

        if (!is_array($lock) || !(($lock['token'] ?? null) === $token)) {
            return;
        }

        delete_option($option);
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_:-]+/', '_', $name));

        return trim($name, '_') ?: 'default';
    }
}
