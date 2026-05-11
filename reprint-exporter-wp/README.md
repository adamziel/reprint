# Reprint Exporter — WordPress Plugin

When working from this monorepo checkout, run `composer install` in
`reprint-exporter-wp/` to populate the bundled `vendor/` directory used by the
plugin runtime. GitHub release ZIPs already include that vendor tree.

## API Routing

Many shared hosts (SiteGround, GoDaddy, etc.) block direct PHP execution inside `wp-content/plugins/` at the web server level, returning a 403 before the request ever reaches PHP. To work around this, export API requests are routed through WordPress's front controller (`index.php` at the site root), which hosts never block.

### How it works

The plugin file (`index.php`) is `include`'d by WordPress during its plugin loading loop — this happens *before* the `plugins_loaded` hook fires, making it the earliest interception point available to a regular plugin.

When a request arrives at `https://example.com/?reprint-api` (or the legacy `?site-export-api` alias, kept for backwards compatibility), the plugin:

1. Detects `$_GET['reprint-api']` or `$_GET['site-export-api']` during plugin file load
2. Reverts WordPress error display settings (`display_errors`, `html_errors`) that `wp_debug_mode()` may have turned on
3. Clears any output buffering WordPress started
4. Sets up error handlers, HMAC auth, and runs the export endpoint
5. Calls `exit` — WordPress never finishes booting

This gives us a clean execution environment while using WordPress's front controller as the entry point.

### Optional fast path: install as a must-use plugin

The regular plugin still pays for everything wp-settings.php does
before it fires plugin-load: `$wpdb` instantiation, mu-plugin loading,
and any regular plugins that sort alphabetically before
`reprint-exporter-wp`. On a typical wp.com Atomic / large managed-host
site that's 50–200 ms per request — paid on every file_fetch,
file_index, and sql batch in a sync, which adds up over a
multi-thousand-batch migration.

To skip most of that, install the bundled mu-plugin loader. Either copy
or symlink it into `wp-content/mu-plugins/`:

```bash
# Copy (simpler, but won't track plugin upgrades):
cp wp-content/plugins/reprint-exporter-wp/mu-plugin/0-reprint-exporter.php \
   wp-content/mu-plugins/0-reprint-exporter.php

# Or symlink (recommended — picks up plugin updates automatically):
ln -s ../plugins/reprint-exporter-wp/mu-plugin/0-reprint-exporter.php \
      wp-content/mu-plugins/0-reprint-exporter.php
```

The leading `0-` makes it sort before any other mu-plugin in
`wp_get_mu_plugins()`. For API requests it hands off to `lib.php` and
calls `exit` before the rest of mu-plugin and regular-plugin loading
runs. For non-API requests it returns silently and normal WP boot
proceeds untouched.

Safe to install alongside the regular plugin — on API requests the
mu-plugin exits before the regular plugin would have loaded; on
non-API requests the mu-plugin no-ops and the regular plugin continues
to serve its admin UI responsibilities.

If the regular plugin directory is missing or unreadable, the
mu-plugin falls through silently rather than fatal-erroring. Better to
serve a 404 to an unhandled `?reprint-api` request than to take the
whole site offline.

## Using as a library

The export engine can be embedded in another PHP project without the WordPress plugin wrapper. Require `lib.php` instead of `index.php` — it defines constants and functions but does not handle any HTTP requests or check any URLs.

```php
// Your project must define ABSPATH before requiring lib.php.
define('ABSPATH', '/path/to/wordpress/');

require_once '/path/to/reprint-exporter-wp/lib.php';

// Route however you like — lib.php doesn't check URLs.
if ($myRouter->matches('/export')) {
    // Use default HMAC authentication (reads secret.php when present,
    // otherwise falls back to the site option):
    _site_export_handle_api_request();

    // Or supply your own authentication:
    _site_export_handle_api_request([
        'authenticate' => function () {
            if (!my_auth_check()) {
                _site_export_error(403, 'Unauthorized');
            }
        },
    ]);
}
```

`lib.php` defines these constants (using WordPress's `plugin_dir_path`):

- `SITE_EXPORT_VERSION` — plugin version string
- `SITE_EXPORT_PLUGIN_DIR` — absolute path to the plugin directory
- `SITE_EXPORT_SECRET_FILE` — optional path to a PHP file that overrides the stored HMAC shared secret
- `SITE_EXPORT_SECRET_OPTION` — WordPress site option name used for the stored HMAC shared secret
- `SITE_EXPORT_TIMESTAMP_TOLERANCE` — max request age in seconds (default 300)
