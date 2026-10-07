# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

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
