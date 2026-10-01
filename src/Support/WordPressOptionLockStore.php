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
            if ($this->expired($this->memory[$option] ?? null) || ($this->memory[$option]['token'] ?? null) !== $token) {
                throw new LockConflictedException();
            }
            $this->memory[$option] = ['token' => $token, 'expires' => $expires];
            $key->reduceLifetime($ttl);

            return;
        }

        $lock = $this->lock($option);

        if ($this->expired($lock) || ($lock['token'] ?? null) !== $token) {
            throw new LockConflictedException();
        }

        if (!$this->compareAndSwap($option, $lock, ['token' => $token, 'expires' => $expires])) {
            throw new LockConflictedException();
        }
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
            $this->compareAndSwap($option, $lock, null);
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
            $this->compareAndSwap($option, $lock, null);

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
            $this->compareAndSwap($option, $lock, null);

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
        $database = $this->database();
        $query = $database->prepare('INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $database->options, $option, maybe_serialize($value), 'off');
        if ($query === null) {
            throw new \RuntimeException('Lock query preparation failed.');
        }
        $affected = $database->query($query);
        if ($affected === false) {
            throw new \RuntimeException('Lock insertion failed.');
        }
        wp_cache_delete($option, 'options');
        wp_cache_delete('notoptions', 'options');
        return $affected === 1;
    }

    /** @return array{token?: string, expires?: int}|null */
    private function lock(string $option): ?array
    {
        $database = $this->database();
        $stored = $database->get_var($database->prepare('SELECT option_value FROM %i WHERE option_name = %s', $database->options, $option));
        $lock = is_string($stored) ? maybe_unserialize($stored) : null;

        return is_array($lock) ? $lock : null;
    }

    /**
     * @param array{token?: string, expires?: int}|null $previous
     * @param array{token: string, expires: int}|null $next
     */
    private function compareAndSwap(string $option, ?array $previous, ?array $next): bool
    {
        $database = $this->database();
        $old = maybe_serialize($previous);
        $query = $next === null
            ? $database->prepare('DELETE FROM %i WHERE option_name = %s AND BINARY option_value = %s', $database->options, $option, $old)
            : $database->prepare('UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s', $database->options, maybe_serialize($next), $option, $old);
        if ($query === null) {
            throw new \RuntimeException('Lock query preparation failed.');
        }
        $affected = $database->query($query);
        wp_cache_delete($option, 'options');
        wp_cache_delete('notoptions', 'options');
        return $affected === 1 || ($affected === 0 && $previous === $next);
    }

    private function database(): \wpdb
    {
        $database = $GLOBALS['wpdb'] ?? null;
        if (!$database instanceof \wpdb) {
            throw new \RuntimeException('WordPress locks require a database connection.');
        }
        return $database;
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
