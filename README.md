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
```

The legacy `edge-cache` namespace is also registered for compatibility.

## Features

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
