# SymPress Nginx Cache

[![PHP: ^8.5](https://img.shields.io/badge/php-%5E8.5-777bb4.svg)](composer.json) [![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](composer.json)

SymPress Nginx Cache provides WordPress cache purge controls for Nginx
FastCGI, proxy and uWSGI cache setups running on the SymPress kernel. It
combines admin tools, WP-CLI commands, automatic purge hooks, surrogate tag
tracking, queue processing, diagnostics and generated Nginx configuration.

## Package

```bash
composer require sympress/nginx-cache
```

The package is discoverable by `sympress/kernel` through Composer metadata:

```json
{
  "extra": {
    "kernel": {
      "bundle": "SymPress\\NginxCache\\NginxCacheBundle",
      "entry": "nginx-cache/nginx-cache.php"
    }
  }
}
```

## WP-CLI

```bash
wp nginx-cache status
wp nginx-cache purge /
wp nginx-cache purge --queue --prewarm
wp nginx-cache diagnostics
wp nginx-cache config
wp nginx-cache queue details
wp nginx-cache side-effects details
```

The legacy `edge-cache` namespace is also registered for compatibility.
Queues retain failed work, retry at most five times with capped exponential
backoff, and expose exhausted work through `status` and `details`. After fixing
the cause, `wp nginx-cache queue retry` or `wp nginx-cache side-effects retry`
resets its retry budget and schedules processing. See the
[side-effect contract](docs/side-effects.md) for timing and delivery guarantees.

New purge events use 64 bounded merge slots plus at most four overflow markers
for the dry-run/prewarm combinations. When selective work exceeds the URL budget,
it becomes a full invalidation rather than losing URLs. Retry exhaustion does
not scan the inbox on content hooks. Existing legacy inbox rows are ingested in
batches of at most 68 after processing or an explicit retry resumes.

## Features

WordPress admin notices appear below the plugin header, outside the onboarding
banner and settings cards. A missing cache-directory warning means the configured
local cache path is unavailable; installing the plugin does not enable Nginx
FastCGI caching or create that directory.

- Select Redis page-cache or Nginx `GET /purge/<path>` backends instead of local files.
- Configure separate homepage, singular-page and archive rules for edits, deletes
  and comment approval/removal.
- Discover prewarm URLs from same-origin sitemap indexes or URL sets.
- Generate and optionally atomically maintain a Multisite Nginx map.
- Optionally include a rendering timestamp, query count and duration in public HTML.

- Purge Nginx cache files by URL, path, cache layer or full cache directory.
- Queue purge requests and process side effects safely.
- Track surrogate tags for targeted invalidation.
- Purge affected posts, posts pages, feeds, date archives, paginated archives,
  AMP companion URLs, REST/GraphQL resources and WooCommerce product URLs.
- Forward tag purges to Cloudflare through `Cache-Tag` headers and the
  Cloudflare purge API when configured.
- Delegate whole-zone purges to a protected Nginx endpoint instead of removing
  local cache files directly.
- Inspect cache path availability, writability, file counts and byte size.
- Generate Nginx cache snippets for the configured profile.
- Prewarm selected URLs after purge operations.
- Expose admin dashboard actions and REST endpoints for integrations.

## Nginx Helper compatibility

The Cache settings tab contains backend connection settings and the purge-rule
matrix. Existing installations continue using local files and their existing
all-scope rules. Redis and HTTP are alternatives to disk purging, not WordPress
object-cache adapters. Redis requires a site-dedicated, nonempty prefix; passwords
are encrypted and are never exported or rendered. TCP, ACL users and Unix sockets
are supported. Redis keys use either `PREFIX$scheme$request_method$host$request_uri`
(nginx-srcache / Nginx Helper) or SymPress's pipe-separated key format. Coordinate
the key and prefix with your server configuration. A full Redis purge removes
only that prefix. It never runs FLUSHDB/FLUSHALL.

For HTTP URL purges configure the same-origin prefix (default `/purge`) and the
existing remote signing secret. A compatible FastCGI/proxy purge module must
provide that location. Protect it with an IP allowlist or signature verification;
the module does not itself authenticate signature headers. The existing protected
full-purge endpoint is required for full HTTP purges. No redirects are followed.

Enable both prewarm and sitemap discovery to preload after full purges, or run
`wp nginx-cache prewarm`. The default sitemap is `/wp-sitemap.xml`; custom SEO
sitemap indexes can be configured. Discovery reads at most 20 sitemaps (2 MiB
each) and shares the existing configurable limit of up to 200 prewarm URLs.
Foreign-origin entries, external entities and redirects are rejected. Selective
purges continue warming only their affected URLs. The optional HTML stamp is
restricted to anonymous HTML responses; REST, feeds, AJAX, cron and login/admin
responses are unchanged. It records PHP rendering time, not a claim of a cache hit.

Network administrators can preview the Multisite map. For automatic persistence,
set `SYMPRESS_NGINX_CACHE_MULTISITE_MAP_FILE` to an absolute `.conf` filename outside
the public document root. Site creation/update/deletion and admin initialization
refresh that file atomically, leaving unchanged content and previous files on
failure intact. Nginx must include the map in its HTTP context and use
`$sympress_blog_id` in the appropriate static-upload rule. Nginx configuration,
legacy/current upload paths and reloads remain operator responsibilities. Up to
5000 active sites per current network are supported; domain aliases can be added
with `sympress_nginx_cache_multisite_map_entries`. For multiple networks configure
separate destinations per network in wp-config. WordPress itself detects Nginx
rewrite support; no additional `index.php` permalink workaround is installed.
