<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Surrogate;

use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Security\UrlPolicy;
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
        if (get_option(self::OPTION_VERSION) === '1') {
            return;
        }

        $this->mutex->synchronized(self::LEGACY_OPTION, function (): void {
            $db = $this->database();
            wp_cache_delete(self::OPTION_VERSION, 'options');
            wp_cache_delete('notoptions', 'options');
            if (get_option(self::OPTION_VERSION) === '1') {
                return;
            }
            $this->query($db->prepare(
                'CREATE TABLE IF NOT EXISTS %i (tag varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, url_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, url text NOT NULL, touched bigint unsigned NOT NULL, PRIMARY KEY (tag,url_hash), KEY url_hash (url_hash), KEY touched (touched)) ENGINE=InnoDB',
                $this->table(),
            ));
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
            if (!update_option(self::OPTION_VERSION, '1', false) && get_option(self::OPTION_VERSION) !== '1') {
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
        if ($this->tagsForUrl($url) === $tags) {
            return;
        }
        $this->mutex->synchronized(self::LEGACY_OPTION, function () use ($url, $tags): void {
            if ($this->tagsForUrl($url) === $tags) {
                return;
            }
            $db = $this->database();
            $this->query('START TRANSACTION');
            try {
                $this->query($db->prepare('DELETE FROM %i WHERE url_hash = %s', $this->table(), hash('sha256', $url)));
                foreach ($tags as $tag) {
                    $this->insert($tag, $url, $this->clock->timestamp());
                    $excess = $db->get_col($db->prepare('SELECT url_hash FROM %i WHERE tag = %s ORDER BY touched DESC, url_hash DESC LIMIT 50,64', $this->table(), $tag));
                    foreach ($excess as $hash) {
                        $this->query($db->prepare('DELETE FROM %i WHERE tag = %s AND url_hash = %s', $this->table(), $tag, $hash));
                    }
                }
                $excessTags = $db->get_col($db->prepare('SELECT tag FROM %i GROUP BY tag ORDER BY MAX(touched) DESC, tag DESC LIMIT 1000,64', $this->table()));
                foreach ($excessTags as $tag) {
                    $this->query($db->prepare('DELETE FROM %i WHERE tag = %s', $this->table(), $tag));
                }
                $this->query('COMMIT');
            } catch (\Throwable $error) {
                $db->query('ROLLBACK');
                throw $error;
            }
        });
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
            foreach ($db->get_col($db->prepare('SELECT url FROM %i WHERE tag = %s LIMIT 50', $this->table(), $tag)) as $url) {
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

    /** @return array{tags: int, urls: int} */
    public function stats(): array
    {
        if (!$this->available()) {
            return ['tags' => 0, 'urls' => 0];
        }
        $db = $this->database();
        $row = $db->get_row($db->prepare('SELECT COUNT(DISTINCT tag) AS tags, COUNT(DISTINCT url_hash) AS urls FROM %i', $this->table()), ARRAY_A);
        return ['tags' => (int) ($row['tags'] ?? 0), 'urls' => (int) ($row['urls'] ?? 0)];
    }

    /** @return list<string> */
    private function tagsForUrl(string $url): array
    {
        $db = $this->database();
        $tags = $db->get_col($db->prepare('SELECT tag FROM %i WHERE url_hash = %s ORDER BY tag', $this->table(), hash('sha256', $url)));
        return array_values(array_map('strval', $tags));
    }

    private function insert(string $tag, string $url, int $timestamp): void
    {
        $this->query($this->database()->prepare('INSERT IGNORE INTO %i (tag,url_hash,url,touched) VALUES (%s,%s,%s,%d)', $this->table(), $tag, hash('sha256', $url), $url, $timestamp));
    }

    private function available(): bool
    {
        return ($GLOBALS['wpdb'] ?? null) instanceof \wpdb && get_option(self::OPTION_VERSION) === '1';
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
