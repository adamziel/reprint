# Site Export WordPress Plugin

## API Routing

Many shared hosts (SiteGround, GoDaddy, etc.) block direct PHP execution inside `wp-content/plugins/` at the web server level, returning a 403 before the request ever reaches PHP. To work around this, export API requests are routed through WordPress's front controller (`index.php` at the site root), which hosts never block.

### How it works

The plugin file (`index.php`) is `include`'d by WordPress during its plugin loading loop — this happens *before* the `plugins_loaded` hook fires, making it the earliest interception point available to a regular plugin.

By default, when a request arrives at `https://example.com/?site-export-api`, the plugin:

1. Detects `$_GET['site-export-api']` during plugin file load
2. Reverts WordPress error display settings (`display_errors`, `html_errors`) that `wp_debug_mode()` may have turned on
3. Clears any output buffering WordPress started
4. Sets up error handlers, HMAC auth, and runs the export endpoint
5. Calls `exit` — WordPress never finishes booting

This gives us a clean execution environment while using WordPress's front controller as the entry point.

## Configuration Filters

The plugin keeps the current behavior by default, but key integration points are filterable:

- `site_export_api_query_arg`
  Changes the default front-controller query arg. Default: `site-export-api`.
- `site_export_is_api_request`
  Overrides request matching entirely. Use this when the API should live on a custom path instead of a query arg.
- `site_export_api_url`
  Controls the endpoint URL shown in the admin UI.
- `site_export_authorization_callback`
  Replaces the built-in secret-file + HMAC authorization callback.
- `site_export_secret_file`
  Changes the file path used by the built-in secret-based authorization flow.
- `site_export_enable_ui`
  Enables or disables all Site Export admin UI additions. By default, the UI is only enabled when using the built-in secret/HMAC flow.

Route matching and request authorization happen during plugin load, before `plugins_loaded`. If you need to override those pieces for live API requests, register the relevant filters from an MU-plugin or another plugin that loads before Site Export.

Example:

```php
add_filter('site_export_api_query_arg', function () {
    return 'my-export-api';
});

add_filter('site_export_api_url', function () {
    return home_url('/api/site-export');
});

add_filter('site_export_authorization_callback', function () {
    return 'my_site_export_authorize_request';
});

add_filter('site_export_enable_ui', '__return_false');

function my_site_export_authorize_request(array $context) {
    if (!empty($_SERVER['HTTP_X_INTERNAL_TOKEN'])) {
        return null;
    }

    return 'Missing internal authorization token.';
}
```
