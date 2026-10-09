<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Purge;

use SymPress\NginxCache\Support\MutationLockUnavailable;
use SymPress\NginxCache\Support\OptionMutex;

final readonly class NetworkPendingRepository
{
    public const string OPTION = 'sympress_nginx_cache_network_pending';
    private const string INBOX = self::OPTION . '_inbox_';

    public function __construct(private OptionMutex $mutex)
    {
    }

    public function mark(): void
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }
        $id = get_current_blog_id();
        $generation = bin2hex(random_bytes(16));
        try {
            $this->mutex->synchronized(self::OPTION, function () use ($id, $generation): void {
                $pending = $this->read();
                $pending[$id] = $generation;
                $this->persist($pending);
            }, network: true);
        } catch (MutationLockUnavailable) {
            // Producers retain an atomic site marker even while a network worker clears its snapshot.
            $name = self::INBOX . $id;
            if (!update_site_option($name, $generation) && get_site_option($name) !== $generation) {
                throw new \RuntimeException('Unable to mark pending network work.');
            }
        }
    }

    /** @return array<int, string> Site ID => producer generation. */
    public function all(): array
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return [];
        }
        return $this->mutex->synchronized(self::OPTION, function (): array {
            $pending = $this->read();
            $db = $GLOBALS['wpdb'];
            $rows = $db->get_results($db->prepare('SELECT meta_id, meta_key, meta_value FROM %i WHERE site_id = %d AND meta_key LIKE %s ORDER BY meta_id LIMIT 1000', $db->sitemeta, get_current_network_id(), $db->esc_like(self::INBOX) . '%'), ARRAY_A);
            if ($db->last_error !== '') {
                throw new \RuntimeException('Unable to read network pending markers.');
            }
            foreach ($rows ?? [] as $row) {
                $id = (int) substr((string) $row['meta_key'], strlen(self::INBOX));
                if ($id <= 0) {
                    continue;
                }

                $pending[$id] = (string) $row['meta_value'];
            }
            $this->persist($pending);
            foreach ($rows ?? [] as $row) {
                if ($db->query($db->prepare('DELETE FROM %i WHERE meta_id = %d AND meta_value = %s', $db->sitemeta, $row['meta_id'], $row['meta_value'])) === false) {
                    throw new \RuntimeException('Unable to acknowledge network pending markers.');
                }
                wp_cache_delete(get_current_network_id() . ':' . $row['meta_key'], 'site-options');
            }
            return $pending;
        }, network: true);
    }

    /** @param callable(): bool $empty */
    public function clear(int $id, string $generation, callable $empty): void
    {
        $this->mutex->synchronized(self::OPTION, function () use ($id, $generation, $empty): void {
            $pending = $this->read();
            wp_cache_delete(get_current_network_id() . ':' . self::INBOX . $id, 'site-options');
            wp_cache_delete(get_current_network_id() . ':notoptions', 'site-options');
            if (($pending[$id] ?? null) !== $generation || get_site_option(self::INBOX . $id, null) !== null || !$empty()) {
                return;
            }
            unset($pending[$id]);
            $this->persist($pending);
        }, network: true);
    }

    /** @return array<int, string> */
    private function read(): array
    {
        // Another worker/producer can update the network option in the same
        // long-running process lifetime. Re-read it after acquiring the mutex.
        wp_cache_delete(get_current_network_id() . ':' . self::OPTION, 'site-options');
        wp_cache_delete(get_current_network_id() . ':notoptions', 'site-options');
        $value = get_site_option(self::OPTION, []);
        $pending = [];
        foreach (is_array($value) ? $value : [] as $id => $generation) {
            if ((int) $id <= 0 || !is_string($generation)) {
                continue;
            }

            $pending[(int) $id] = $generation;
        }
        return $pending;
    }

    /** @param array<int, string> $pending */
    private function persist(array $pending): void
    {
        if (!update_site_option(self::OPTION, $pending) && get_site_option(self::OPTION) !== $pending) {
            throw new \RuntimeException('Unable to persist pending network work.');
        }
    }
}
