# Migrating cache plugins

Preview Nginx Helper settings before importing:

```sh
wp nginx-cache migrate nginx-helper --dry-run
wp nginx-cache migrate nginx-helper
# Multisite network values, without changing existing site options:
wp nginx-cache migrate nginx-helper --network --dry-run
wp nginx-cache network adopt-settings --from-site=2 --dry-run
```

The importer supports the [Nginx Helper 2.4.1 settings](https://github.com/rtCamp/nginx-helper/blob/2.4.1/admin/class-nginx-helper-admin.php).
It maps FastCGI file deletion, HTTP `/purge`, Redis connection settings,
the concatenated Nginx Helper cache key, edit/delete/comment purge rules,
feeds, AMP, additional purge URLs, preload, HTML stamps and delegated roles.
Passwords use authenticated encryption in options; previews and reports show
only whether a credential is configured. A private encryption key or valid
WordPress authentication salts are required. Network-managed settings are
reported and skipped during a site import.

Existing `nginx_cache_path` and `nginx_auto_purge` options from Nginx Cache are
already supported; `wp nginx-cache migrate nginx-cache` confirms this.
`RT_WP_NGINX_HELPER_CACHE_PATH` and its Redis constants remain readable aliases
when the corresponding `SYMPRESS_NGINX_CACHE_*` constant is absent.

Review the generated key and cache root against your running Nginx configuration.
HTTP purging requires a protected, signed endpoint; migration cannot create its
server configuration or signing secret. Deactivate the previous plugin after
verification to prevent duplicate purges. The importer never deactivates plugins
or modifies Nginx. WooCommerce hooks are built in. Multisite map generation is
available in Network Admin and requires an explicitly configured output file.

Additional purge URLs may be absolute same-origin URLs or paths, separated by
newlines or commas. All pass through `UrlPolicy`; foreign origins are rejected.

Opt in to Nginx Helper hook compatibility with
`SYMPRESS_NGINX_CACHE_NGINX_HELPER_HOOKS=true`. The bridge handles
`rt_nginx_helper_purge_url`, `rt_nginx_helper_exclude_post_types`,
`rt_nginx_helper_purge_all` and `rt_nginx_helper_after_purge_all`.
The purge action targets the current site; the completion action fires only
after a successful, completed full purge. The existing `nginx_cache_*` hook
compatibility remains enabled for upgrades.
