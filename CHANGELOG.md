# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
where applicable.

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
