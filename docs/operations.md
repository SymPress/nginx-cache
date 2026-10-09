# Operating Nginx Cache 1.0

## Site and network purges

The default scope is `site` for admin actions, hooks, CLI, REST, legacy requests
and queue overflow. Shared Multisite file roots are scanned using the configured
Nginx key template, original/mapped host and longest registered site path.
Other sites, unknown keys and symlinks are retained. Selective requests also
reject URLs belonging to a sibling or nested site before any backend runs.

Scans default to 20,000 files / 10 seconds per chunk. Set
`SYMPRESS_NGINX_CACHE_SCAN_FILE_BUDGET` and
`SYMPRESS_NGINX_CACHE_SCAN_TIME_BUDGET` to adjust these limits. Partial results
carry `partial`, `cursor` and `unmatched`; the existing side-effect queue resumes
them under the cache lock before invoking providers. A dry run reports a bounded
preview and creates no file, queue task or continuation. After a queue storage
failure, `wp nginx-cache side-effects retry` resumes a full scan in the same scope.

`SYMPRESS_NGINX_CACHE_SITE_ISOLATED_PATH=true` permits whole-directory deletion
only where each site genuinely has its own root. A network-wide purge targets
the configured shared root, Redis prefix or provider zone. Separate roots/zones
need separate site requests:

```sh
wp nginx-cache purge --full                   # current site
wp nginx-cache purge --network --full         # explicit shared network scope
wp nginx-cache purge --scope=site --dry-run
```

Network Admin requires the network domain to be typed before a whole-network
purge. REST requires the network capability for `scope=network`. Global object
cache and OPcache flushes require network scope in Multisite. Cloudflare site
purges use `site:<blog_id>` tags; network scope permits `purge_everything`.
The Cloudflare account must support tag purging.

HTTP full-purge endpoints must opt into
`SYMPRESS_NGINX_CACHE_ENDPOINT_SUPPORTS_SITE_SCOPE=true` on Multisite. They must
validate the timestamp and HMAC, then enforce the signed ownership boundaries:

```
site: timestamp.full-purge.site.host.path.base64_boundaries
network: timestamp.full-purge.network
```

`X-SymPress-Purge-Scope`, `X-SymPress-Purge-Host`, `X-SymPress-Purge-Path` and
`X-SymPress-Site-Boundaries` transmit this metadata. The signature is
`sha256=HMAC-SHA256(message, remote_secret)`. The boundary JSON contains
`roots` and `paths`, including nested sites; metadata over 6,144 bytes fails
closed. Single-site endpoints retain their `timestamp.full-purge` protocol.

## Permissions and settings ownership

| Capability | Default mapping |
| --- | --- |
| `sympress_nginx_cache_purge_url` | `manage_options`, or an explicitly delegated role |
| `sympress_nginx_cache_purge_site` | `manage_options` |
| `sympress_nginx_cache_manage` | `manage_options` |
| `sympress_nginx_cache_purge_network` | `manage_network_options` in Multisite |

Capabilities are mapped at runtime; no roles are permanently modified.
Delegated editors get public object/current-page/bulk URL actions only. Bulk
actions over 100 objects enqueue their complete target set. Nonces are required
for browser mutations; application-password REST authentication is supported.

Constants override adopted network policy, which overrides site options when
the policy is `network`; otherwise site values override adopted defaults.
Credentials, cache paths, backends, key templates, Redis settings and delegated
roles are network-managed after adoption. Existing site values are retained.
Locked fields are disabled and tampered writes are ignored. Network settings
are serialized under a database mutex; secrets are encrypted and never rendered.

```sh
wp nginx-cache network adopt-settings --from-site=2 --dry-run
wp nginx-cache network adopt-settings --from-site=2
```

## One worker per server

```cron
* * * * * wp --path=/srv/wordpress nginx-cache work --network --max-runtime=50
```

For a single site, omit `--network`. The equivalent console command is
`wp console nginx-cache:work`. Options include `--max-tasks=500`, `--sleep=0`
(milliseconds), `--once`, `--format=json` and `--fail-on-exhausted`.
The runtime limit is checked between tasks; a currently executing task may
finish after it. The network worker visits only pending sites, using durable
generation markers and inbox recovery instead of scanning all sites.
Two workers can share the queues without executing a task concurrently.

With `DISABLE_WP_CRON`, dashboards, CLI diagnostics and Site Health warn when
the worker heartbeat is missing or older than five minutes. Inspect exhausted
tasks and fix their cause before using `queue retry` or `side-effects retry`.
Tag maintenance also has its own WordPress cron hook, so keep due cron events
running for otherwise idle sites when WP-Cron is disabled. The queue worker
performs maintenance on sites it visits.

## Compact setup and presets

Fresh installations start in simple mode. Existing installations retain the
advanced screen. Both modes have an explicit authenticated switch.
Simple mode shows KPIs, actions, path, automatic purge, prewarm and a collapsible
Nginx configuration preview. Presets show a diff and require explicit application:

| Preset | Queue/debounce | Tag limits | Prewarm |
| --- | --- | --- | --- |
| Small | direct / 0 s | disabled | home + sitemap |
| Standard | enabled / 5 s | 50 URLs/tag, 1,000 tags | home + sitemap |
| Large | enabled / 15 s | 500 URLs/tag, 50,000 tags | affected URLs only |

All presets retain Nginx validity, inactivity, disk and key-zone limits.
Constant/network-owned settings remain unchanged. Applying a preset changes
plugin options; it does not reload Nginx or install a system cron.

## Tag retention and prewarm

| Constant / option suffix | Default | Maximum |
| --- | --- | --- |
| `SYMPRESS_NGINX_CACHE_TAG_URLS_PER_TAG` / `tag_urls_per_tag` | 50 | 5,000 |
| `SYMPRESS_NGINX_CACHE_TAG_MAX_TAGS` / `tag_max_tags` | 1,000 | 200,000 |
| `SYMPRESS_NGINX_CACHE_TAG_TTL_SECONDS` / `tag_ttl_seconds` | configured Nginx inactivity | 604,800 s |

Options use the `sympress_nginx_cache_` prefix. Advanced settings expose these
limits. Pruning removes at most 500 rows per maintenance tick, independently
of inserts. New/old tables retain URL mappings during the schema migration;
the compact numeric primary key and covering indexes reduce count/expiry cost.
Diagnostics and Site Health expose row count and allocated table/index bytes.
Allocated storage may exceed live row size after pruning.

Automatic prewarm orders changed URLs first, then home pages, archives and
sitemap URLs. Merged requests preserve all affected URLs. The large preset
excludes unrelated archive/home targets and sitemap discovery after a content
event. `SYMPRESS_NGINX_CACHE_PREWARM_RPS` defaults to 5, bounded to 1–100.
Requests remain sequential, with existing same-origin, no-redirect, five-second
timeout and durable success checkpoints. The rate limit is per worker process;
multiple workers/sites can increase aggregate traffic.

## Polylang and extension API

The optional adapter uses guarded public Polylang APIs with no dependency on
private plugin classes. Saving a translated post/term includes public translated
permalinks, homes, posts pages and archives; grouped tags join translations.
Language mutations trigger a full site invalidation. Recursive collection is
guarded and translation lists are bounded to 20 entries per API call.

Directory/domain language modes remove `pll_language` from bypass cookies;
browser detection bypasses only the root. Query-language mode retains the
language cookie bypass and permits `lang` while keeping language keys distinct.
Diagnostics recommend directory/domain mode for efficient shared caching.
URLs still pass same-origin validation; explicit host extensions are required
for a different origin. Authentication and commerce bypasses remain enabled.

See [extension contracts](extending.md) for supported hooks and examples,
[migration](migration.md) for Nginx Helper import, and
[side effects](side-effects.md) for delivery and retry semantics.
