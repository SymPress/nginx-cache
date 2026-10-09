<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Surrogate;

use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Security\UrlPolicy;
use SymPress\NginxCache\Settings\TagIndexSettings;
use SymPress\NginxCache\Support\MutationLockUnavailable;
use SymPress\NginxCache\Support\OptionMutex;
use SymPress\NginxCache\Time\CacheClock;

final readonly class TagIndexRepository
{
    public const string OPTION_VERSION = 'sympress_nginx_cache_tag_index_version';
    public const string LEGACY_OPTION = 'sympress_nginx_cache_tag_index';
    public const string TABLE_SUFFIX = 'sympress_cache_tags';
    private const int MAX_TAGS = 1000;
    private const int MAX_URLS_PER_TAG = 50;
    private const int MAX_REQUEST_TAGS = 64;

    public function __construct(
        private CacheTagResolver $tags,
        private UrlPolicy $urls,
        private CacheClock $clock,
        private OptionMutex $mutex,
        private TagIndexSettings $limits = new TagIndexSettings(),
        private ?TagIndexMaintenance $maintenance = null,
    ) {
    }

    public function install(): void
    {
        try {
            $this->installSchema();
        } catch (MutationLockUnavailable) {
            // Another installer owns initialization. A later init retries safely.
        }
    }

    private function installSchema(): void
    {
        if (get_option(self::OPTION_VERSION) === '3') {
            return;
        }

        $this->mutex->synchronized(self::LEGACY_OPTION, function (): void {
            $db = $this->database();
            wp_cache_delete(self::OPTION_VERSION, 'options');
            wp_cache_delete('notoptions', 'options');
            if (get_option(self::OPTION_VERSION) === '3') {
                return;
            }
            $this->query($db->prepare(
                'CREATE TABLE IF NOT EXISTS %i (id bigint unsigned NOT NULL AUTO_INCREMENT, tag varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, url_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, url text NOT NULL, touched bigint unsigned NOT NULL, PRIMARY KEY (id), UNIQUE KEY tag_url (tag,url_hash), KEY url_hash (url_hash), KEY touched (touched)) ENGINE=InnoDB',
                $this->table(),
            ));
            if ($db->get_var($db->prepare("SHOW COLUMNS FROM %i LIKE 'id'", $this->table())) === null) {
                // A compact clustered key keeps secondary counts and TTL scans
                // small; the unique pair preserves idempotent tag insertion.
                $this->query($db->prepare('ALTER TABLE %i ADD COLUMN id bigint unsigned NOT NULL AUTO_INCREMENT FIRST, DROP PRIMARY KEY, ADD PRIMARY KEY (id), ADD UNIQUE KEY tag_url (tag,url_hash)', $this->table()));
            }
            if ($db->get_var($db->prepare("SHOW INDEX FROM %i WHERE Key_name = 'tag_touched'", $this->table())) === null) {
                $this->query($db->prepare('ALTER TABLE %i ADD INDEX tag_touched (tag,touched,url_hash)', $this->table()));
            }
            // One-time migration is bounded by the old index's documented limits.
            $legacy = get_option(self::LEGACY_OPTION, []);
            if (is_array($legacy)) {
                foreach (array_slice($legacy, 0, self::MAX_TAGS, true) as $tag => $urls) {
                    if (!is_string($tag) || !is_array($urls)) {
                        continue;
                    }
                    $normalized = $this->tags->normalize([$tag]);
                    if ($normalized === []) {
                        continue;
                    }
                    foreach (array_slice($urls, 0, self::MAX_URLS_PER_TAG, true) as $url => $timestamp) {
                        $url = $this->urls->normalizeSameOriginHttpUrl($url);
                        if ($url === '') {
                            continue;
                        }

                        $this->insert($normalized[0], $url, is_numeric($timestamp) ? (int) $timestamp : $this->clock->timestamp());
                    }
                }
            }
            if (!update_option(self::OPTION_VERSION, '3', false) && get_option(self::OPTION_VERSION) !== '3') {
                throw new \RuntimeException('Unable to record the tag index schema.');
            }
            delete_option(self::LEGACY_OPTION);
            wp_cache_delete(self::LEGACY_OPTION, 'sympress_nginx_cache');
        });
    }

    /** @param list<string> $tags */
    public function remember(string $url, array $tags): void
    {
        $parts = explode('?', $url, 2);
        if (isset($parts[1])) {
            if ($parts[1] !== '' && preg_match('/' . CacheKeyStrategy::TRACKING_QUERY_PATTERN . '/D', $parts[1]) !== 1) {
                // The shipped Nginx policy bypasses every semantic/mixed query.
                // Do not issue a DB read or displace a genuinely cached URL.
                return;
            }
            $url = $parts[0];
        }
        $url = $this->urls->normalizeSameOriginHttpUrl($url);
        if ($url === '') {
            return;
        }
        $tags = array_slice($this->tags->normalize($tags), 0, self::MAX_REQUEST_TAGS);
        sort($tags);
        // Reads are cheap and idempotent: an unchanged anonymous request issues no writes.
        if ($this->freshTags($url, $tags)) {
            return;
        }
        $this->mutex->synchronized(self::LEGACY_OPTION, function () use ($url, $tags): void {
            if ($this->freshTags($url, $tags)) {
                return;
            }
            $db = $this->database();
            $this->query('START TRANSACTION');
            try {
                $this->query($db->prepare('DELETE FROM %i WHERE url_hash = %s', $this->table(), hash('sha256', $url)));
                foreach ($tags as $tag) {
                    $this->insert($tag, $url, $this->clock->timestamp());
                }
                $this->query('COMMIT');
            } catch (\Throwable $error) {
                $db->query('ROLLBACK');
                throw $error;
            }
        });
        ($this->maintenance ?? new TagIndexMaintenance($this->limits, $this->clock, $this->mutex))->schedule();
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    public function urlsForTags(array $tags): array
    {
        if (!$this->available()) {
            return [];
        }
        $urls = [];
        $db = $this->database();
        foreach (array_slice($this->tags->normalize($tags), 0, self::MAX_REQUEST_TAGS) as $tag) {
            foreach ($db->get_col($db->prepare('SELECT url FROM %i WHERE tag = %s ORDER BY touched DESC,url_hash DESC LIMIT %d', $this->table(), $tag, $this->limits->urlsPerTag())) as $url) {
                $normalized = $this->urls->normalizeSameOriginHttpUrl($url);
                if ($normalized === '') {
                    continue;
                }

                $urls[] = $normalized;
            }
        }
        return array_values(array_unique($urls));
    }

    /** @param list<string> $urls */
    public function forgetUrls(array $urls): void
    {
        if (!$this->available()) {
            return;
        }
        $this->mutex->synchronized(self::LEGACY_OPTION, function () use ($urls): void {
            $db = $this->database();
            foreach ($urls as $url) {
                $url = $this->urls->normalizeSameOriginHttpUrl($url);
                if ($url === '') {
                    continue;
                }

                $this->query($db->prepare('DELETE FROM %i WHERE url_hash = %s', $this->table(), hash('sha256', $url)));
            }
        });
    }

    public function clear(): void
    {
        if (!$this->available()) {
            return;
        }
        $this->mutex->synchronized(self::LEGACY_OPTION, fn () => $this->query($this->database()->prepare('DELETE FROM %i', $this->table())));
    }

    public function clearNetwork(): void
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            $this->clear();
            return;
        }
        $offset = 0;
        do {
            $sites = get_sites(['network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);
            foreach ($sites as $id) {
                switch_to_blog((int) $id);
                try {
                    $this->clear();
                } finally {
                    restore_current_blog();
                }
            }
            $offset += 100;
        } while (count($sites) === 100);
    }

    /** @return array{tags: int, urls: int, rows: int, bytes: int} */
    public function stats(): array
    {
        if (!$this->available()) {
            return ['tags' => 0, 'urls' => 0, 'rows' => 0, 'bytes' => 0];
        }
        $db = $this->database();
        // Keyset batches keep each read bounded, even on cold indexes. Avoid
        // one full-table DISTINCT/count query on the dashboard or health page.
        $tags = 0;
        $cursor = '';
        $tagIndex = get_option(self::OPTION_VERSION) === '3' ? 'tag_url' : 'PRIMARY';
        do {
            $batch = $db->get_col($db->prepare('SELECT DISTINCT tag FROM %i FORCE INDEX (%i) WHERE tag > %s ORDER BY tag LIMIT 1000', $this->table(), $tagIndex, $cursor));
            $tags += count($batch);
            $cursor = $batch !== [] ? (string) end($batch) : $cursor;
        } while (count($batch) === 1000);
        $urls = $rows = 0;
        $cursor = '';
        do {
            $batch = $db->get_results($db->prepare('SELECT url_hash,COUNT(*) AS row_count FROM %i FORCE INDEX (url_hash) WHERE url_hash > %s GROUP BY url_hash ORDER BY url_hash LIMIT 1000', $this->table(), $cursor), ARRAY_A);
            if ($batch === null) {
                throw new \RuntimeException('Tag index statistics read failed.');
            }
            $urls += count($batch);
            foreach ($batch as $row) {
                $rows += (int) $row['row_count'];
                $cursor = (string) $row['url_hash'];
            }
        } while (count($batch) === 1000);
        $bytes = $db->get_var($db->prepare('SELECT COALESCE(DATA_LENGTH,0)+COALESCE(INDEX_LENGTH,0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table()));
        return ['tags' => (int) $tags, 'urls' => (int) $urls, 'rows' => (int) $rows, 'bytes' => (int) $bytes];
    }

    /** @param list<string> $tags */
    private function freshTags(string $url, array $tags): bool
    {
        $db = $this->database();
        $rows = $db->get_results($db->prepare('SELECT tag,touched FROM %i WHERE url_hash = %s ORDER BY tag', $this->table(), hash('sha256', $url)), ARRAY_A);
        if ($rows === null) {
            throw new \RuntimeException('Tag index read failed.');
        }
        $existing = [];
        $touched = $this->clock->timestamp();
        foreach ($rows as $row) {
            if (!is_string($row['tag'] ?? null) || !is_numeric($row['touched'] ?? null)) {
                throw new \RuntimeException('Invalid tag index row.');
            }
            $existing[] = $row['tag'];
            $touched = min($touched, (int) $row['touched']);
        }
        if ($existing !== $tags) {
            return false;
        }
        return $touched > $this->clock->timestamp() - max(1, (int) ($this->limits->ttl() / 4));
    }

    private function insert(string $tag, string $url, int $timestamp): void
    {
        $this->query($this->database()->prepare('INSERT IGNORE INTO %i (tag,url_hash,url,touched) VALUES (%s,%s,%s,%d)', $this->table(), $tag, hash('sha256', $url), $url, $timestamp));
    }

    private function available(): bool
    {
        return ($GLOBALS['wpdb'] ?? null) instanceof \wpdb && in_array(get_option(self::OPTION_VERSION), ['1', '2', '3'], true);
    }

    private function database(): \wpdb
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (!$db instanceof \wpdb) {
            throw new \RuntimeException('The tag index requires the WordPress database.');
        }
        return $db;
    }

    private function table(): string
    {
        return $this->database()->prefix . self::TABLE_SUFFIX;
    }

    private function query(?string $query): int
    {
        if ($query === null) {
            throw new \RuntimeException('Tag index query preparation failed.');
        }
        $result = $this->database()->query($query);
        if ($result === false) {
            throw new \RuntimeException('Tag index database operation failed.');
        }
        return (int) $result;
    }
}
