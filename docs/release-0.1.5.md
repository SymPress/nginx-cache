# Nginx Cache 0.1.5

Ordinary database lock contention no longer marks follow-up storage as failed
or converts selective requests into whole-zone Cloudflare invalidation.
Contended producers retain immutable, uniquely identified inbox tasks. Workers
ingest at most 64 records per batch under the aggregate lock, keep original
identities, and acknowledge the inbox only after durable queue persistence.
The active queue remains limited to 50 tasks. Genuine capacity overflow and
genuine storage failure still require their documented full-recovery policy.
Malformed inbox records are quarantined without stopping valid work.
Successful provider checkpoints and acknowledgments also use durable completion
records during contention. Workers apply them before reserving another attempt,
so confirmation contention cannot exhaust the provider retry budget. Pending
records behind a bounded batch keep the queue scheduled.

Semantic and mixed query strings bypass tag registration before any database
read or write, matching the shipped Nginx policy. Tracking-only URLs continue
to share the query-free cache and tag identity. Operators who explicitly cache
semantic query variants must purge their exact URLs or use a full purge.

## Validation

- PHPCS, PHPStan and PHPUnit: 34 tests / 133 assertions.
- Real WordPress 7.1.2 integration: existing 148 assertions plus 25 new native
  query/contention/provider-payload assertions, with external HTTP blocked and
  provider responses mocked.
- The exact released baseline fails the new regression; genuine database
  storage failure still produces visible full recovery in the corrected code.

Local and hosted MariaDB/MySQL results and the final merge/tag identity are
recorded in the repository review recheck after publication. No real cache path
or remote provider is purged by these tests.
