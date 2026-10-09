# Extending Nginx Cache

WordPress filters and actions are the public integration boundary. Register
callbacks before `init` priority 20 to affect automatic purge registration.
They execute in the current site context, including `switch_to_blog()` during
network work. This package requires no private integration package.

## Stable filters

Use the documented argument count when registering callbacks. Return the
complete updated value, preserving defaults you still need. `list<string>`
means an indexed array of strings. Paths are relative to `src/`.

| Hook | Callback signature | Source, context and guarantees |
| --- | --- | --- |
| `sympress_nginx_cache_purge_urls` | `(list<string> urls, string hook, array arguments): list<string>` | `Purge/PurgeUrlCollector.php`, after related content collection. Subsequent `UrlPolicy` validation discards foreign origins; configured purge rules still apply. |
| `sympress_nginx_cache_purge_tags` | `(list<string> tags, string hook, array arguments): list<string>` | Same collector, after object tags. Normalization and broad site/collection-tag exclusions still apply. |
| `sympress_nginx_cache_post_tags` | `(list<string> tags, int postId): list<string>` | `Surrogate/CacheTagResolver.php`, after native post/term tags; values are normalized and deduplicated. |
| `sympress_nginx_cache_term_tags` | `(list<string> tags, int termId, ?object term): list<string>` | Same resolver, after native term tags; the term can be absent. |
| `sympress_nginx_cache_purge_actions` | `(list<string> actions): list<string>` | `Hook/AutomaticPurgeSubscriber.php`, during registration. Blank/non-string actions are removed; auto-purge must be enabled. |
| `sympress_nginx_cache_full_purge_hooks` | `(list<string> hooks, string hook, array arguments): list<string>` | `Purge/PurgeUrlCollector.php`, while deciding whether the current event needs a full purge. Automatic events retain `Site` scope. |
| `sympress_nginx_cache_should_purge` | `(bool purge, array arguments): bool` | `Hook/AutomaticPurgeSubscriber.php`, after public-content/autosave/exclusion checks. False vetoes the event. The hook name is not passed. |
| `sympress_nginx_cache_excluded_post_types` | `(list<string> types): list<string>` | `Settings/WordPressCacheSettings.php`, when reading automatic purge exclusions. Additional types are excluded from automatic events. |
| `sympress_nginx_cache_bypass_cookies` | `(list<string> patterns, string profile): list<string>` | `Config/BypassRuleProvider.php`, before custom settings. Patterns are deduplicated; Nginx config validation applies. Preserve authentication/cart bypasses. |
| `sympress_nginx_cache_bypass_uris` | `(list<string> patterns, string profile): list<string>` | Same provider, before custom rules; values are regex patterns, not URLs. |
| `sympress_nginx_cache_query_allowlist` | `(list<string> patterns, string profile): list<string>` | Same provider; match intended query syntax narrowly. Functional query parameters remain part of the cache key. |
| `sympress_nginx_cache_prewarm_urls` | `(list<string> urls): list<string>` | `Settings/WordPressCacheSettings.php`, while reading configured targets. Same-origin validation and per-site caps remain mandatory. Explicit targeted plans use request URLs. |
| `sympress_nginx_cache_key_template` | `(string template): string` | `Key/CacheKeyStrategy.php`, during generation/formatting/parsing. Include `$scheme`, `$request_method`, `$host`, `$request_uri` exactly once for site scans. Server config must match. |
| `sympress_nginx_cache_key_candidates` | `(list<array> candidates, string scheme, string host, string uri): list<array>` | Same strategy, after native/legacy candidates. Row string fields: `key`, `scheme`, `forwarded_protocol`, `method`, `host`, `uri`. Blank/invalid keys are discarded; local lookup remains under a validated root. |
| `sympress_nginx_cache_path` | `(string path): string` | `Settings/WordPressCacheSettings.php`, after path resolution. The purger still validates root trust; this filter never grants deletion rights. |

Examples for each stable filter:

```php
add_filter('sympress_nginx_cache_purge_urls', static function (array $urls, string $hook, array $args): array {
    return $hook === 'save_post' ? [...$urls, home_url('/news/')] : $urls;
}, 10, 3);
add_filter('sympress_nginx_cache_purge_tags', static fn (array $tags, string $hook, array $args): array => [...$tags, 'custom:news'], 10, 3);
add_filter('sympress_nginx_cache_post_tags', static fn (array $tags, int $id): array => [...$tags, 'custom:post:' . $id], 10, 2);
add_filter('sympress_nginx_cache_term_tags', static fn (array $tags, int $id, ?object $term): array => [...$tags, 'custom:term:' . $id], 10, 3);
add_filter('sympress_nginx_cache_purge_actions', static fn (array $actions): array => [...$actions, 'my_language_changed']);
add_filter('sympress_nginx_cache_full_purge_hooks', static fn (array $hooks, string $hook, array $args): array => [...$hooks, 'my_language_changed'], 10, 3);
add_filter('sympress_nginx_cache_should_purge', static fn (bool $purge, array $args): bool => $purge && !defined('MY_IMPORT_RUNNING'), 10, 2);
add_filter('sympress_nginx_cache_excluded_post_types', static fn (array $types): array => [...$types, 'internal_record']);
add_filter('sympress_nginx_cache_bypass_cookies', static fn (array $patterns, string $profile): array => [...$patterns, 'my_private_session'], 10, 2);
add_filter('sympress_nginx_cache_bypass_uris', static fn (array $patterns, string $profile): array => [...$patterns, '^/private/'], 10, 2);
add_filter('sympress_nginx_cache_query_allowlist', static fn (array $patterns, string $profile): array => [...$patterns, '^lang=(?:de|en)$'], 10, 2);
add_filter('sympress_nginx_cache_prewarm_urls', static fn (array $urls): array => [...$urls, home_url('/important/')]);
add_filter('sympress_nginx_cache_key_template', static fn (string $template): string => '$scheme$request_method$host$request_uri');
add_filter('sympress_nginx_cache_key_candidates', static fn (array $rows, string $scheme, string $host, string $uri): array => $rows, 10, 4);
add_filter('sympress_nginx_cache_path', static fn (string $path): string => '/var/cache/nginx/wordpress');
```

## Stable result actions

| Hook | Callback signature | Source, context and guarantees |
| --- | --- | --- |
| `sympress_nginx_cache_purged` | `(PurgeResult result): void` | `Purge/PurgeEventEmitter.php`, after a successful local/backend attempt and follow-up scheduling. Immutable `scope`, `mode`, `dryRun`, `partial`, counts and URLs describe that attempt. Remote completion is separate. |
| `sympress_nginx_cache_purge_failed` | `(PurgeResult result): void` | Same emitter, after a failed attempt. Errors are present; retained queue work can be retried. |

```php
use SymPress\NginxCache\Value\PurgeResult;
add_action('sympress_nginx_cache_purged', static function (PurgeResult $result): void {
    if ($result->dryRun || $result->partial) { return; }
    // Update your own non-sensitive monitoring data here.
});
add_action('sympress_nginx_cache_purge_failed', static function (PurgeResult $result): void {
    // Report a failure without exposing configuration or credentials.
});
```

Callbacks must respect dry runs: no filesystem, queue, index, HTTP or Redis
mutation. Do not delete cache files in callbacks; use `CacheManager` or the
purge queue. Automatic events use site scope; network scope requires network
authorization. A partial result has a durable continuation and is not a
completed full purge.

## Internal hooks

These are inventoried for discovery. Their signatures may change in a minor
release. Filter returns have the first argument's type; actions return void.
In the table, `Settings` is `Settings/WordPressCacheSettings.php`, `Tags` is
`Surrogate/CacheTagResolver.php`, and `URLs` is `Security/UrlPolicy.php`.

| Hook | Arguments | Source / context |
| --- | --- | --- |
| `sympress_nginx_cache_affected_urls` | `list<string> urls, string hook, array arguments` | `Purge/PurgeUrlCollector.php` / primary changed objects for prewarm; validated same-origin after filtering |
| `sympress_nginx_cache_allow_private_remote_endpoints` | `bool allowed` | URLs / outbound policy |
| `sympress_nginx_cache_allowed_url_hosts` | `list<string> hosts` | URLs / site aliases |
| `sympress_nginx_cache_remote_allowed_hosts` | `list<string> hosts` | URLs / outbound hosts |
| `sympress_nginx_cache_site_hosts` | `list<string> hosts` | `Purge/SiteScopeResolver.php` / ownership aliases |
| `sympress_nginx_cache_archive_page_limit` | `int limit` | Settings / archive discovery |
| `sympress_nginx_cache_feed_url_limit` | `int limit` | Settings / feed discovery |
| `sympress_nginx_cache_feed_variants` | `list<string> variants` | Settings / feed paths |
| `sympress_nginx_cache_profile` | `string profile` | Settings / profile resolution |
| `sympress_nginx_cache_levels` | `string levels` | Settings / directory levels |
| `sympress_nginx_cache_remote_endpoints` | `list<string> endpoints` | Settings / configured endpoints |
| `sympress_nginx_cache_cloudflare_enabled` | `bool enabled` | Settings / Cloudflare selection |
| `sympress_nginx_cache_cloudflare_zone_id` | `?string zoneId` | Settings / zone resolution |
| `sympress_nginx_cache_cloudflare_api_token` | `?string token` | Settings / credential resolution |
| `sympress_nginx_cache_cloudflare_header_limit` | `int limit` | Settings / response header budget |
| `sympress_nginx_cache_surrogate_header_limit` | `int limit` | Settings / response header budget |
| `sympress_nginx_cache_full_purge_endpoint` | `?string endpoint` | Settings / full endpoint resolution |
| `sympress_nginx_cache_full_purge_mode` | `string mode` | Settings / local or endpoint mode |
| `sympress_nginx_cache_prewarm_delay_ms` | `int milliseconds` | Settings / delay bounded by requests/second |
| `sympress_nginx_cache_prewarm_limit` | `int limit` | Settings / per-site target cap, maximum 200 |
| `sympress_nginx_cache_bypass_user_agents` | `list<string> patterns, string profile` | `Config/BypassRuleProvider.php` / Nginx rules |
| `sympress_nginx_cache_current_tags` | `list<string> tags` | Tags / current response |
| `sympress_nginx_cache_comment_tags` | `list<string> tags, int commentId, ?object comment` | Tags / comment tags |
| `sympress_nginx_cache_user_tags` | `list<string> tags, int userId` | Tags / user tags |
| `sympress_nginx_cache_keys` | `list<string> keys, string scheme, string host, string uri` | `Key/CacheKeyStrategy.php` / legacy key adapter |
| `sympress_nginx_cache_multisite_map_entries` | `array entries` | `Config/MultisiteMapGenerator.php` / map preview |
| `sympress_nginx_cache_map_capability` | `list<string> capabilities, string requested, int userId, array args` | `Security/Capabilities.php` / meta-cap mapping |
| `sympress_nginx_cache_sync_layers` | `list<string> layers, PurgeResult result` | `Layer/CacheLayerCoordinator.php` / layer selection |
| `sympress_nginx_cache_remote_payload` | `array payload, PurgeResult result, PurgeRequest request` | `Remote/RemotePurgeDispatcher.php` / signed payload |
| `sympress_nginx_cache_cloudflare_payload` | `array payload, PurgeResult result, PurgeRequest request` | `Remote/CloudflarePurgeDispatcher.php` / payload; site scope still restricts full purges to site tags |
| `sympress_nginx_cache_flush_layers` (action) | `array result` | `Layer/CacheLayerCoordinator.php` / after flush |
| `sympress_nginx_cache_side_effects_processed` (action) | `PurgeResult result, PurgeRequest request, array sideEffects` | `Purge/PurgeSideEffectProcessor.php` / task completion |
| `sympress_nginx_cache_remote_purge_dispatched` (action) | `list<array> responses, array payload` | `Remote/RemotePurgeDispatcher.php` / dispatch completion |
| `sympress_nginx_cache_cloudflare_purge_dispatched` (action) | `list<array> responses, array payload` | `Remote/CloudflarePurgeDispatcher.php` / dispatch completion |

## Integrating a multilingual plugin

Use the plugin's public API to collect translation URLs in your integration,
outside this package. Extend `purge_urls` with translated permalinks, language
homes and archives. `PurgeUrlCollector::urlsForPost(int)` and
`urlsForTerm(int)` reuse native collection without re-entering `purge_urls`.
Guard recursive callbacks with `try/finally`.

Add the same translation-group tag through `post_tags`/`term_tags` and
`purge_tags` so response indexing and invalidation agree. Use
`bypass_cookies` to remove only the language cookie when the URL identifies
the language. Retain bypass when a cookie changes the response for one URL.
Browser negotiation at `/` requires URI bypass. Query languages require a
narrow query allowlist and distinct query-bearing cache keys. Preserve login
and cart protection.

The optional [Polylang provider](../src/Integration/Polylang/PolylangIntegration.php)
uses these same hooks, with no Composer dependency, and is a no-op when
`pll_get_post_translations()` is absent. Its public APIs and language hooks
were checked against [Polylang 3.8.10](https://github.com/polylang/polylang/tree/8e2ab6f42680886c841663e8bbf697f5cfb5608f).
Language taxonomy deletion uses WordPress `delete_term`; language changes
purge site scope. Query mode retains cookie bypass, permits `lang`, and warns
to verify server key consistency. Apply generated server configuration
explicitly after changing language mode.

## Stability policy

Stable hooks follow Semantic Versioning from 1.0.0. Removal or a change to
documented argument order/types requires a major release. First deprecate
filters with `apply_filters_deprecated()` for at least one minor release;
actions use `do_action_deprecated()`. Appending optional arguments while
preserving existing arguments is additive. Internal hooks have no signature
compatibility guarantee. New hooks must be inventoried here: `composer qa`
checks documentation and exercises every stable filter and result action.
