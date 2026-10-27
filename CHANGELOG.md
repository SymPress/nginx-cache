# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

## Unreleased

### Fixed

- Cache responses that send a positive `X-Accel-Expires` for the TTL they request. The generated `fastcgi_no_cache` rule passed the header itself, so any nonzero value prevented storage. A new response map keeps `private`, `no-cache` and `no-store` responses out of the cache, because a positive `X-Accel-Expires` overrides Nginx' own `Cache-Control` checks; `X-Accel-Expires: 0`, `Set-Cookie` and the existing bypass rules still prevent caching. Regenerate the HTTP and FastCGI includes together, since the FastCGI include references the new map, and use Nginx 1.23 or newer (#52).

## 1.0.10 — 2026-10-27

### Changed

- Re-release 1.0.9 under a signed tag. The `v1.0.9` tag was pushed unsigned and cannot be replaced because release tags are immutable; 1.0.10 is its signed successor with the same code, including the 404 caching fix below.

## 1.0.9 — 2026-10-27

### Fixed

- Cache anonymous 404 responses for the minute the generated `fastcgi_cache_valid 404 1m` rule promises. WordPress sends `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private` with every 404, so Nginx never stored one and every miss, including bot scans, reached PHP. Anonymous GET/HEAD 404s now send `Cache-Control: public, max-age=60` instead; logged-in, admin, AJAX, cron and authorized requests keep WordPress' no-store headers. The generated Nginx configuration is unchanged (#47).

## 1.0.8 — 2026-10-26

### Fixed

- Count a prewarmed page as warmed only after its response body arrived completely. Previously an HTTP 200 whose transfer broke or stalled after the headers was checkpointed as warmed, although Nginx had not stored the page. The body is now streamed and discarded within the existing five-second limits, without buffering it in memory. An incomplete transfer is reported as a retryable URL failure, while complete sibling URLs keep their progress (#40).
- Keep already known prewarm targets warm when sitemap discovery fails after a purge. Previously a sitemap error, such as an HTTP 503, stopped the follow-up task before any known page (for example the home page) was requested and before cache layer, remote and Cloudflare work ran. Known targets are now warmed in batches that do not spend the retry budget, the remaining follow-up work runs, and only the missing discovery is retried with backoff. A later successful discovery adds just the missing targets. Discovery errors name the sitemap and a fixed reason (HTTP status, timeout, request failure, size or XML) and the latest failure appears as `sitemap_discovery_failure` in side-effect details and diagnostics until discovery succeeds again (#41).
- Report a keep-warm first cycle that never completes as overdue. Previously `overdue` required a completed cycle, so a first run that stalled, crashed on every attempt or never started kept Site Health at "good". The inactivity window now counts from the last completion, otherwise from the first cycle's start or first attempt, otherwise from its due cron event. Start-up, a not yet due event and a disabled keep-warm stay quiet, and a worker heartbeat does not replace a completed cycle (#42).

## 1.0.7 — 2026-10-25

### Changed

- Lead the dashboard with page coverage: public page URLs, including language versions, are compared with the local cache files and shown as cached, expired, not cached, excluded by a bypass rule or unknown. The check is bounded, labelled when limited, and sends no HTTP requests, purges or prewarm requests. The HTTP hit rate moves to the Tools tab (simple mode: "Technische Statistik") and explains that it counts requests in a sliding window, not pages or visitors (#36).

### Fixed

- Keep still-current requests in the 60-minute hit-rate window after log rotation by also reading `<log>.1` within the existing read budget, counting bytes shared during copytruncate once. An unread recent generation, such as an already compressed archive, marks the window as incomplete (#37).

## 1.0.6 — 2026-10-23

### Changed

- Bundle every preload setting in the **Preload** tab: the prewarm switch, fixed URLs, sitemap discovery and sitemap URL, the previously hidden "prewarm only changed URLs" scope and the keep-warm retention mode. The Cache tab and dashboard no longer duplicate these fields; the dashboard shows the preload status with a link to the tab. Help texts explain that preload and prewarm are the same feature and how the options depend on the prewarm switch. Option names and stored values are unchanged.
- Label the dashboard quick action **Purge and prewarm**, because it purges the whole cache before warming.

## 1.0.5 — 2026-10-22

### Added

- Keep prewarmed pages warm by default: a bounded background refresh requests prewarm targets inside the inactivity window. Inactivity eviction for those targets is available as the `evict_inactive` retention mode. Upgrades with a changed inactivity timeout keep eviction. Failures appear in diagnostics and Site Health.

### Fixed

- Retain safe local purge queue failure details for CLI and diagnostics, including exception class and source location. Preserve the evidence across retries and prevent contending workers from replacing it.
- Keep outdated prewarm targets from blocking a stored prewarm plan. Same-origin redirects are followed for up to three revalidated hops; removed, rejected, externally redirected or looping targets are skipped and reported instead of exhausting the retry budget, while timeouts, rate limits and 5xx responses keep bounded retries. Side-effect details and diagnostics show the affected URL, HTTP status or safe reason and time.

## 1.0.4 — 2026-10-19

### Fixed

- Add a GitHub `Update URI` header so WordPress.org cannot offer updates for an unrelated plugin with a similar name, including while this plugin is inactive.

### Changed

- Use `ns-nginx-cache/ns-nginx-cache.php` as the WordPress plugin basename while preserving the **Nginx Cache** display name. Composer installation metadata, release archive names and folders, plugin settings links, asset URLs and version lookups follow the new entry. Existing installations require the [documented activation and directory migration](docs/migration.md#upgrading-the-sympress-plugin-slug).

## 1.0.3 — 2026-10-16

### Fixed

- Exclude internal and unpublished WordPress content from affected prewarm targets, preventing invalid form, shared-block, template and internal-taxonomy URLs from exhausting the follow-up queue.
- Invalidate the current site when internal content changes, including deleted internal terms, while preserving selective invalidation for public posts and taxonomies.

### Changed

- Run full MariaDB/MySQL scale benchmarks locally through an isolated Docker runner with retained reports and portable checksum verification instead of GitHub Actions.

Existing frozen or exhausted prewarm plans require operator recovery after updating; this release does not rewrite queued work.

## 1.0.2 — 2026-10-16

- Restore scheduled dependency compatibility checks by discovering project-local PHPCS standards and PHPStan extensions when Composer plugins are disabled, while preserving normal installation support.

## 1.0.1 — 2026-10-16

- Export settings from every advanced tab and save imported values together, preserving checkbox fallbacks, role selections, managed fields and target-site secrets.
- Persist manual Prewarm changes when saving from another advanced tab, including unchecked switches.
- Preserve backslashes in saved bypass rules and keep additional purge URLs on separate lines when saving or importing settings.

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
