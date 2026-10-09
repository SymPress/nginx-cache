<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Surrogate;

use SymPress\NginxCache\Settings\TagIndexSettings;
use SymPress\NginxCache\Support\MutationLockUnavailable;
use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Time\CacheClock;

final readonly class TagIndexMaintenance
{
    public const string CURSOR = 'sympress_nginx_cache_tag_prune_cursor';
    public const string HOOK = 'sympress_nginx_cache_prune_tags';
    private const int BATCH = 500;

    public function __construct(private TagIndexSettings $limits, private CacheClock $clock, private OptionMutex $mutex)
    {
    }

    public function schedule(int $delay = 60): void
    {
        if (!function_exists('wp_next_scheduled') || wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_single_event($this->clock->timestamp() + max(1, $delay), self::HOOK);
    }

    public function prune(): int
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (!$db instanceof \wpdb || !in_array(get_option(TagIndexRepository::OPTION_VERSION), ['1', '2', '3'], true)) {
            return 0;
        }
        try {
            return $this->mutex->synchronized(TagIndexRepository::LEGACY_OPTION, fn (): int => $this->pruneTransaction($db));
        } catch (MutationLockUnavailable) {
            $this->schedule();
            return 0;
        }
    }

    private function pruneTransaction(\wpdb $db): int
    {
        // One durable commit for the bounded maintenance batch, rather than
        // an implicit fsync for each tag DELETE and cursor/schedule update.
        $this->execute($db, 'START TRANSACTION');
        try {
            $removed = $this->pruneRows($db);
            $this->execute($db, 'COMMIT');
            return $removed;
        } catch (\Throwable $error) {
            $db->query('ROLLBACK');
            // WordPress option caches are not transactional. Discard the
            // maintenance metadata cached before the failed commit.
            foreach ([self::CURSOR, 'cron', 'notoptions', 'alloptions'] as $option) {
                wp_cache_delete($option, 'options');
            }
            throw $error;
        }
    }

    private function pruneRows(\wpdb $db): int
    {
        $table = $db->prefix . TagIndexRepository::TABLE_SUFFIX;
        $removed = $this->execute($db, $db->prepare('DELETE FROM %i WHERE touched < %d LIMIT %d', $table, $this->clock->timestamp() - $this->limits->ttl(), self::BATCH));
        if ($removed >= self::BATCH) {
            $this->schedule();
            return $removed;
        }
        $cursor = get_option(self::CURSOR, '');
        $cursor = is_string($cursor) ? $cursor : '';
        $tags = $db->get_col($db->prepare('SELECT DISTINCT tag FROM %i WHERE tag > %s ORDER BY tag LIMIT 32', $table, $cursor));
        $last = '';
        foreach ($tags as $tag) {
            $excess = $db->get_col($db->prepare('SELECT url_hash FROM %i WHERE tag = %s ORDER BY touched DESC,url_hash DESC LIMIT %d,%d', $table, $tag, $this->limits->urlsPerTag(), self::BATCH - $removed));
            if ($excess !== []) {
                $placeholders = implode(',', array_fill(0, count($excess), '%s'));
                $removed += $this->execute($db, $db->prepare('DELETE FROM %i WHERE tag = %s AND url_hash IN (' . $placeholders . ') LIMIT %d', ...[$table, $tag, ...$excess, self::BATCH - $removed]));
            }
            if ($removed >= self::BATCH) {
                break;
            }
            $last = $tag;
        }
        update_option(self::CURSOR, count($tags) < 32 ? '' : $last, false);
        if ($removed < self::BATCH) {
            // Keep grouped timestamps on the covering index instead
            // of fetching clustered rows containing the URL text.
            $indexHint = get_option(TagIndexRepository::OPTION_VERSION) === '3' ? ' FORCE INDEX (tag_touched)' : '';
            $excessTags = $db->get_col($db->prepare('SELECT tag FROM %i' . $indexHint . ' GROUP BY tag ORDER BY MAX(touched) DESC,tag DESC LIMIT %d,%d', $table, $this->limits->maxTags(), self::BATCH - $removed));
            if ($excessTags !== []) {
                $placeholders = implode(',', array_fill(0, count($excessTags), '%s'));
                $removed += $this->execute($db, $db->prepare('DELETE FROM %i WHERE tag IN (' . $placeholders . ') LIMIT %d', $table, ...[...$excessTags, self::BATCH - $removed]));
            }
        }
        $this->schedule($removed > 0 || count($tags) === 32 ? 60 : 300);
        return $removed;
    }

    private function execute(\wpdb $db, ?string $query): int
    {
        if ($query === null) {
            throw new \RuntimeException('Tag index maintenance failed.');
        }
        $result = $db->query($query);
        if ($result === false) {
            throw new \RuntimeException('Tag index maintenance failed.');
        }
        return (int) $result;
    }
}
