<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Support;

use SymPress\NginxCache\Time\CacheClock;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;

final class WordPressOptionLockStore implements BlockingStoreInterface
{
    private const string PREFIX = 'sympress_nginx_cache_lock_';
    private const int DEFAULT_TTL_SECONDS = 30;
    private const int BLOCKING_ATTEMPTS = 50;
    private const int BLOCKING_WAIT_MICROSECONDS = 50_000;

    /** @var array<string, array{token: string, expires: int}> */
    private array $memory = [];

    public function __construct(
        private readonly CacheClock $clock,
    ) {
    }

    public function save(Key $key): void
    {
        $this->saveWithTtl($key, self::DEFAULT_TTL_SECONDS);
    }

    public function waitAndSave(Key $key): void
    {
        for ($attempt = 0; $attempt < self::BLOCKING_ATTEMPTS; ++$attempt) {
            try {
                $this->save($key);

                return;
            } catch (LockConflictedException) {
                $this->clock->sleepMicroseconds(self::BLOCKING_WAIT_MICROSECONDS);
            }
        }

        throw new LockConflictedException();
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $option = $this->optionName($key);
        $token = $this->token($key);
        $expires = $this->expiresAt($ttl);

        if (!$this->wordpressOptionsAvailable()) {
            $this->memory[$option] = ['token' => $token, 'expires' => $expires];
            $key->reduceLifetime($ttl);

            return;
        }

        $lock = $this->lock($option);

        if (($lock['token'] ?? null) !== $token) {
            throw new LockConflictedException();
        }

        update_option($option, ['token' => $token, 'expires' => $expires], false);
        $key->reduceLifetime($ttl);
    }

    public function delete(Key $key): void
    {
        $option = $this->optionName($key);
        $token = $this->token($key);

        if (!$this->wordpressOptionsAvailable()) {
            if (($this->memory[$option]['token'] ?? null) === $token) {
                unset($this->memory[$option]);
            }

            $key->removeState(self::class);

            return;
        }

        $lock = $this->lock($option);

        if (($lock['token'] ?? null) === $token) {
            delete_option($option);
        }

        $key->removeState(self::class);
    }

    public function exists(Key $key): bool
    {
        $option = $this->optionName($key);
        $token = $this->token($key);

        if (!$this->wordpressOptionsAvailable()) {
            $lock = $this->memory[$option] ?? null;

            if ($this->expired($lock)) {
                unset($this->memory[$option]);

                return false;
            }

            return ($lock['token'] ?? null) === $token;
        }

        $lock = $this->lock($option);

        if ($this->expired($lock)) {
            delete_option($option);

            return false;
        }

        return ($lock['token'] ?? null) === $token;
    }

    private function saveWithTtl(Key $key, float $ttl): void
    {
        $option = $this->optionName($key);
        $token = $this->token($key);
        $value = ['token' => $token, 'expires' => $this->expiresAt($ttl)];

        if (!$this->wordpressOptionsAvailable()) {
            $lock = $this->memory[$option] ?? null;

            if (!$this->expired($lock) && ($lock['token'] ?? null) !== $token) {
                throw new LockConflictedException();
            }

            $this->memory[$option] = $value;

            return;
        }

        if ($this->addOption($option, $value)) {
            return;
        }

        $lock = $this->lock($option);

        if (($lock['token'] ?? null) === $token) {
            return;
        }

        if ($this->expired($lock)) {
            delete_option($option);

            if ($this->addOption($option, $value)) {
                return;
            }
        }

        throw new LockConflictedException();
    }

    /**
     * @param array{token: string, expires: int} $value
     * @phpstan-impure
     */
    private function addOption(string $option, array $value): bool
    {
        return add_option($option, $value, '', false);
    }

    /** @return array{token?: string, expires?: int}|null */
    private function lock(string $option): ?array
    {
        $lock = get_option($option);

        return is_array($lock) ? $lock : null;
    }

    /** @param array{token?: string, expires?: int}|null $lock */
    private function expired(?array $lock): bool
    {
        return $lock === null || (int) ($lock['expires'] ?? 0) <= $this->clock->timestamp();
    }

    private function token(Key $key): string
    {
        if (!$key->hasState(self::class)) {
            $key->setState(self::class, bin2hex(random_bytes(16)));
        }

        return (string) $key->getState(self::class);
    }

    private function optionName(Key $key): string
    {
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_:-]+/', '_', (string) $key));

        return self::PREFIX . (trim($name, '_') ?: 'default');
    }

    private function expiresAt(float $ttl): int
    {
        return $this->clock->timestamp() + max(1, (int) ceil($ttl));
    }

    private function wordpressOptionsAvailable(): bool
    {
        return function_exists('add_option')
            && function_exists('delete_option')
            && function_exists('get_option')
            && function_exists('update_option');
    }
}
