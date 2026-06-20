<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Support;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;

final readonly class OptionMutex
{
    private const int TTL_SECONDS = 30;

    public function __construct(
        private LockFactory $locks,
    ) {
    }

    public function synchronized(string $name, callable $callback): mixed
    {
        $lock = $this->locks->createLock($this->normalizeName($name), self::TTL_SECONDS);

        try {
            $acquired = $lock->acquire(true);
        } catch (LockConflictedException) {
            $acquired = false;
        }

        if (!$acquired) {
            return $callback();
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_:-]+/', '_', $name));

        return 'sympress_nginx_cache.' . (trim($name, '_') ?: 'default');
    }
}
