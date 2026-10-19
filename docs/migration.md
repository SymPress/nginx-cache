# Migrating cache plugins

## Upgrading the SymPress plugin slug

The SymPress plugin now appears as **NS Nginx Cache** with the plugin basename
`ns-nginx-cache/ns-nginx-cache.php`. The Composer package remains
`sympress/nginx-cache`; settings, stored credentials, queues, tag-index tables,
cron hooks, REST routes, translation domain and `wp nginx-cache` commands retain
their existing identifiers.

1. Deactivate the old **SymPress** installation before changing its directory.
   Verify its author and source; the unrelated WordPress.org plugin may also
   appear as Nginx Cache.
2. For Composer installations, update `sympress/nginx-cache`. Remove any explicit
   root-project installer-path override that forces this package into the old
   `nginx-cache` folder; the package's `installer-name` is `ns-nginx-cache`.
   For archive installations, install the new release under
   `wp-content/plugins/ns-nginx-cache/` and retain the deactivated old files
   outside the plugins directory as a backup. Do not use WordPress's Delete
   action for this migration: an enabled delete-on-uninstall setting can remove
   the stored configuration and queues.
3. Activate **NS Nginx Cache** again, using the previous per-site or network
   activation scope. WordPress stores activation by plugin basename, so the old
   activation does not follow the renamed entry file. Update any deployment
   allowlist or explicit kernel entry to `ns-nginx-cache/ns-nginx-cache.php`.
4. Open Dashboard → Updates and choose **Check again** to refresh a previously
   cached update notice. Verify the plugin settings and Site Health version.

The main file includes the GitHub `Update URI`, which tells WordPress.org to
exclude this plugin from its update responses even while it is inactive.
Installations still running an older release without that header need the new
files before this protection applies. No GitHub update client is added.

## Importing settings from another cache plugin

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
