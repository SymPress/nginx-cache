# SymPress Nginx Cache

WordPress/Nginx cache orchestration with destructive local, remote, and cache
layer side effects. Read `docs/side-effects.md` before changing purge flow.

## Key paths

- `src/Purge/CacheManager.php`: authoritative purge orchestration.
- `src/Purge/CachePurger.php`: validated, locked filesystem deletion.
- `src/Purge/PurgeQueueProcessor.php`: deferred purge requests.
- `src/Purge/PurgeSideEffectProcessor.php`: deferred prewarm/layer/remote work.
- `src/Security/UrlPolicy.php` and `src/Filesystem/CachePathValidator.php`: trust boundaries.
- `Resources/config/services.yaml`: hook, REST, and CLI entry points.

## Verification

- Focused: `vendor/bin/phpunit --configuration phpunit.xml.dist --filter <TestName>`
- Full: `composer qa`

Use `--dry-run` for manual probes. Never run a real purge against a user path or
remote endpoint unless that side effect is explicitly in scope.

## Invariants

- Validate the cache path and same-origin/remote URLs before any side effect.
- Keep filesystem deletion behind `CachePurger` and its exclusive lock.
- Dry runs must not delete, mutate the tag index, queue side effects, or call HTTP.
- Queue merging must never drop URLs silently; overflow becomes a full purge.
- Remote purge requests require a signing secret and remain
  timeout-bounded and redirect-free.
- Admin/REST destructive routes require capability and nonce checks where applicable.

## Cross-repository impact

`sympress/kernel`, `sympress/event-dispatcher`, and `sympress/wp-cli-console`
provide runtime registration. Changes to hooks, events, or CLI names require a
consumer check and an update to `docs/side-effects.md`.

## Definition of done

The safe orchestration test and the smallest relevant policy test pass,
`composer qa` passes, the side-effect map is current, and no live purge or
remote call was used as test evidence.
