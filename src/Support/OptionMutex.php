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
        // Database advisory locks have owner-aware release and no stale option/delete race.
        $database = $GLOBALS['wpdb'] ?? null;

        if ($database instanceof \wpdb) {
            $scope = $database->get_var('SELECT DATABASE()') . ':' . $database->prefix . ':' . $name;
            $key = 'sympress-cache:' . substr(hash('sha256', $scope), 0, 40);

            if ((string) $database->get_var($database->prepare('SELECT GET_LOCK(%s, 0)', $key)) !== '1') {
                throw new MutationLockUnavailable('Unable to acquire the cache mutation lock.');
            }

            try {
                return $callback();
            } finally {
                $database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)', $key));
            }
        }

        $lock = $this->locks->createLock($this->normalizeName($name), self::TTL_SECONDS);

        try {
            $acquired = $lock->acquire(false);
        } catch (LockConflictedException) {
            $acquired = false;
        }

        if (!$acquired) {
            throw new MutationLockUnavailable('Unable to acquire the cache mutation lock.');
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
