# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## 1.0.0 — 2026-10-09

- Default every purge to the current site, preserving nested and mapped-site cache entries on shared Multisite roots. Continue budgeted scans durably and recover interrupted continuation storage without widening scope.
- Add separate URL, site, settings and network capabilities, delegated editor roles, nonce-protected frontend/list/bulk actions and explicit network purge confirmation.
- Provide Network Admin settings with constant/network/site precedence, locked fields, paginated site queues, retry actions and explicit settings adoption.
- Add a bounded site/network CLI worker with pending-site generations, concurrent-worker locks, retry visibility and heartbeat diagnostics.
- Preview/import Nginx Helper settings, encrypt credentials, retain existing Nginx Cache options and support opt-in legacy hooks without deactivating other plugins.
- Add six direct Site Health checks and a credential-free debug export.
- Give fresh installations a compact settings view and previewable small/standard/large presets; preserve the advanced view on existing installations.
- Make tag retention configurable, migrate to a compact indexed SQL schema without losing mappings and prune in 500-row maintenance batches with TTL expiry.
- Prioritize affected URLs during prewarm, retain priorities across queue merging and enforce a configurable request rate; large-site prewarm excludes unrelated archives and sitemap discovery.
- Integrate Polylang through guarded public APIs, translated URL/group tags, language-aware bypass rules and diagnostic guidance for query-language mode.
- Document extension hook contracts and add automated coverage for public hooks, schema upgrades, real Nginx cache files, concurrent network workers and opt-in large-site benchmarks on MariaDB/MySQL.

## 0.3.0 — 2026-10-09

- Use NGINX green accents and black action buttons, align card headings and separate section subtitles without changing the surrounding WordPress theme; constrain the purge-rule matrix on narrow screens and expose section-button state with valid ARIA.
- Present cache hit rates with their request denominator and a proportional distribution, four adjacent operational KPIs and collapsed measurement details; preserve per-status counts, measurement time, eligibility and sample notices from one diagnostic snapshot.
- Add validated cache validity, inactivity, disk and key-zone limits to generated Nginx configuration without changing profile defaults.
- Process deferred prewarm in durable five-URL batches, checkpoint each successful URL and retain the failure budget across ordinary continuations.
- Treat prewarm HTTP errors and redirects as failures instead of reporting them as successful cache fills.
- Allow dry-run previews of expired caches with empty hexadecimal Nginx levels; create no cache root, sentinel or lock during a preview and preserve lock contention checks.
- Explain missing/unreadable metrics logs and empty measurement windows, show unavailable filesystem KPIs honestly, and report the installed plugin version and actual purge failure details.
- Match an empty query string with an exact Nginx map entry; the old `~^$` regex left ordinary anonymous page requests in `BYPASS`.
- Export separate HTTP, server and FastCGI include files with `wp nginx-cache config --section=...`, so the cache path and bypass rules can be applied in their required Nginx contexts.
- Keep WordPress admin notices below the Nginx Cache header instead of moving them into the onboarding banner or other settings cards.

## 0.2.0 — 2026-10-07

- Add Redis full-page cache purging, separate from WordPress object cache, with bounded prefix scans and selective Nginx Helper/SymPress key support.
- Add protected, signed GET requests to Nginx `/purge/<path>` locations for selective invalidation. Full HTTP purges require a configured full-purge endpoint.
- Configure homepage, page and archive purge scopes independently for post edits/deletion and new/deleted comments, without reintroducing excluded URLs through cache tags.
- Optionally discover same-origin sitemap indexes for preload, with bounded XML sizes, requests and warmed URLs; reject redirects and external XML declarations.
- Preview Multisite maps and optionally write them atomically to an explicit destination, supporting subdomain and subdirectory networks. Nginx reload remains an operator action.
- Optionally append HTML rendering stamps with timestamp, query count and rendering time; skip private and non-HTML responses.
- Add real Redis integration with anonymous and encrypted-password authentication, and compiled kernel consumer checks in the MariaDB/MySQL WordPress harnesses.
- Require PHP DOM for sitemap parsing. New tools and alternative backends require explicit configuration; existing defaults remain in effect.

## 0.1.7 — 2026-10-06

- Bound new purge inbox production to 64 merge slots and four full-invalidation overflow markers. Overflow retains invalidation coverage without accumulating one option per event.
- Avoid scanning the inbox when the retry budget is exhausted; preserve explicit operator retry and bounded ingestion of legacy rows.
- Cover 2,000 invalidations, concurrent producers and acknowledgement races against a real database.

## 0.1.6 — 2026-10-05

- Pass the canonical URI and empty tracking-only query to PHP as well as the cache key. The first campaign request cannot contaminate a shared cached response; semantic and mixed queries retain their parameters and bypass caching.
- Apply generated FastCGI parameters after the standard `fastcgi_params` include. Existing Nginx configurations must be regenerated and reloaded to activate this change.

## 0.1.5 — 2026-10-05

- Retain contended selective follow-up tasks in a durable inbox without turning
  normal database lock competition into a storage error or a Cloudflare zone purge.
- Keep queue identities stable through inbox ingestion; quarantine malformed
  inbox records, and preserve full recovery for genuine storage failures.
- Skip semantic and mixed query-string tag registrations before any database
  access, matching the shipped Nginx bypass policy and preserving real cache URLs.
- Run native contention, provider-payload and query-flood regressions against
  MariaDB and MySQL in CI.

## 0.1.4 — 2026-10-03

- Keep local purges active during remote-provider outages and coalesce external
  queue overflow into bounded full invalidations. Expose exhausted work and
  recover failed enqueues without leaking credentials.
- Preserve functional query identities in the tag index; quarantine corrupt
  producer input without stopping valid queued work.

## 0.1.3 — 2026-10-02

- Tracking-only requests share a canonical cache key and tag URL. Mutation locks are nonblocking; durable producer inboxes preserve purge requests during contention. Retries remain bounded with backoff and explicit exhaustion.

## 0.1.2 — 2026-10-02

- Keep cookie-invariant SymPress Consent responses cacheable in every profile;
  server-dependent consent integrations can still configure an explicit bypass.
- Bound durable purge and side-effect retries to five attempts with capped
  backoff, retained exhaustion, CLI inspection and explicit operator retry.
- Preserve successful side-effect checkpoints across provider retries.

## Earlier changes

- Initial Nginx cache package documentation.
