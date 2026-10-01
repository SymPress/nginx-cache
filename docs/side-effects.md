# Purge side-effect map

`CacheManager::purgeConfiguredPath()` is the single transition from a purge
request to local deletion and follow-up work.

| Trigger | Route | Immediate effect | Deferred effect |
| --- | --- | --- | --- |
| Content/user/term hooks | `AutomaticPurgeSubscriber` → merger → queue or `CacheManager` | None when queued; otherwise validated local/endpoint purge | Successful non-dry runs may queue prewarm, layer sync, remote purge, or Cloudflare |
| Admin purge action | `SettingsPage` → `CacheManager` | Capability/nonce-gated local or endpoint purge | Same side-effect queue |
| REST `/purge` | `CacheRestController` → queue or `CacheManager` | `manage_options`-gated purge; URLs must be same-origin | Same side-effect queue |
| CLI `nginx-cache:purge` | `PurgeCommand` → queue or `CacheManager` | Explicit local, endpoint, or dry-run purge | Same side-effect queue |
| Purge queue hook | `PurgeQueueProcessor` → `CacheManager` | Drains merged purge requests | Successful requests may create side-effect tasks |
| Side-effect hook/CLI | `PurgeSideEffectProcessor` | No cache-file deletion | Prewarm, cache-layer sync, signed remote dispatch, Cloudflare dispatch |

## Safety gates

- `CachePathValidator` rejects unsafe/unmanaged paths; `CachePurger` locks the
  validated directory and preserves its sentinel and lock files.
- `UrlPolicy` restricts URL purges to same-origin URLs and rejects unsafe remote
  destinations. Remote requests have no redirects and finite timeouts.
- Full endpoint and remote payloads are signed when their configured contract
  requires a secret.
- A dry run resolves the purge but skips deletion, tag-index mutation and the
  side-effect queue. History/events may still record the probe.
- Side effects run only after a successful non-dry purge and are inspectable via
  `nginx-cache:side-effects` before flushing.

## Persistent index and retry contract

`TagIndexRepository::install()` runs at `init` priority 5. It creates the site's
`{$wpdb->prefix}sympress_cache_tags` InnoDB table and migrates the legacy
`sympress_nginx_cache_tag_index` option once. The legacy option is deleted only
after the schema version is saved. Failed installation retains the source for
retry. WordPress 6.2 or later identifier placeholders are required. The database
account needs CREATE for installation, ordinary SELECT/INSERT/DELETE permissions
for operation, and DROP only for explicitly enabled uninstall.

The index stores individual `(tag, sha256(url))` rows. An unchanged retained URL
mapping performs reads only: no option rewrite, timestamp touch, transaction or
write lock. Changes are serialized per site with a database advisory lock and
committed as one transaction. Registration accepts at most 64 tags, retains at
most 1,000 tags and 50 URLs per tag, and removes excess rows in bounded batches.
Retention uses the last mapping change, not the last anonymous page view. A URL
whose mappings have been evicted can register again. No process-local object
cache is authoritative for this table. Changing a post uses its own post tags;
shared site, author and collection tags cannot pull unrelated posts into that
purge. Author and taxonomy archive URLs are collected explicitly.

Both queues retain each task until success. Database mutation locks are scoped
by database, table prefix and queue name. Execution uses a separate process lock;
producers can enqueue while HTTP or filesystem work runs. Purge requests carry a
new generation on each enqueue, including identical URLs, so an invalidation
arriving during execution survives acknowledgement. Option caches, including
cached absent options, are invalidated before reading. Failed/exceptional
purges and prewarm/provider/layer work remain queued and schedule another cron
attempt. Production must run WordPress cron externally with monitoring of both
queue sizes. Side-effect capacity is 50: overflow throws and preserves prior
work; the originating purge request remains retryable. Operators must resolve
persistent errors. Retries are at least once: receivers and adapters must accept
repeated invalidation calls. The existing purge URL overflow policy remains an
explicit full purge above 500 URLs.

`WordPressOptionLockStore` uses atomic INSERT IGNORE and conditional UPDATE/DELETE
against the observed serialized token. It reads ownership directly from the
database. WordPress `add_option()` is not an atomic lock acquisition primitive:
its duplicate-key update can overwrite another contender after concurrent cached
absence. An expired owner cannot refresh or delete a replacement owner's lock.
`OptionMutex` uses connection-owned GET_LOCK/RELEASE_LOCK without lease expiry;
acquisition failure throws before any mutation. Database connection termination
releases advisory locks. See the [MariaDB GET_LOCK contract](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock)
and [WordPress identifier placeholders](https://developer.wordpress.org/reference/classes/wpdb/prepare/).

Automatic registration handles future actions with their arguments; it never
replays `did_action()` without the original event. IDs are interpreted only by
the specific post, comment, user, term or WooCommerce hook. Draft-only transitions
and unpublished products do not invalidate public cache. Product transient
clears with no product ID are no-ops. Missing URLs never implicitly become a full
purge. Theme, navigation, permalink and upgrader events retain their explicit
full-purge policy. A failed immediate purge falls back to the durable queue.

## Secrets and uninstall

Cloudflare API tokens and remote signing secrets use authenticated XChaCha20
Poly1305 encryption bound to the individual option name. `ext-sodium` is required.
The private `SYMPRESS_NGINX_CACHE_ENCRYPTION_KEY` constant (at least 32 bytes) takes
precedence; otherwise valid private WordPress AUTH_KEY and SECURE_AUTH_SALT supply
key material. Keep the key outside the database and public document root. Back up
the key securely alongside encrypted database backups. Losing or rotating it
makes old ciphertext unreadable: restore the old key or save freshly issued
secrets. Ciphertext, plaintext fallback and keys are never rendered in password
fields. Empty submission preserves existing storage; explicit clear requires the
settings nonce and manage_options capability. Constants configuring provider
credentials take precedence over stored settings and must be cleared at their
configuration source.

Registering settings migrates legacy plaintext secrets when encryption is
available. Failure preserves recoverable data but getters refuse plaintext,
malformed or unauthenticated ciphertext. Provider calls fail closed and queue
work waits for working credentials. Remote and full-purge endpoint requests
require signing credentials. Cloudflare HTTP 200 with `success: false` is a
failure. Exception messages returned to history are generic and contain no
provider exception text. Rotate credentials previously stored in plaintext.

The generated bypass includes `sympress_consent` in every profile. For a custom
consent cookie, add that name via the existing bypass-cookie setting/filter and
regenerate the server configuration. Origin validation uses configured home/site
origins only. History records REMOTE_ADDR; a trusted reverse proxy must configure
that address at the server layer, rather than trusting incoming forwarding
headers in PHP.

Uninstall retains data by default. The explicit delete-on-uninstall setting
removes this plugin's settings, queues, history, locks and tag table, and clears
its cron hooks. On multisite each site's own opt-in is checked. Filesystem cache
files, uploads, other plugin options and remote caches are never removed by
uninstall. Disable/delete preserves data unless this setting was enabled.

## Mandatory integration verification

Run `composer qa`, then `composer tests:integration` with
`NGINX_TEST_WORDPRESS_DIR` pointing to unpacked WordPress core and
`NGINX_TEST_DB_NAME=sympress_review_nginx_<unique>` pointing to a **fresh** disposable
schema. Set `NGINX_TEST_DB_HOST`, `NGINX_TEST_DB_USER` and
`NGINX_TEST_DB_PASSWORD` for an isolated MariaDB service. The harness refuses
existing schemas, creates WordPress tables, runs concurrent workers and drops
only its newly created schema in finally. Missing requirements fail, never skip.
All external WordPress HTTP is blocked; provider behavior uses MockHttpClient.
QA's `WordPress MariaDB integration` job runs this on a separate MariaDB service
and a hash-verified WordPress 7.1.2 archive. Require this check before merging.
