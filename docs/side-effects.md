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
