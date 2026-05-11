<?php
/**
 * @reprint-mu-plugin-loader v1
 *
 * Reprint Exporter — must-use plugin fast-path loader.
 *
 * Place this file at wp-content/mu-plugins/0-reprint-exporter.php (note
 * the leading "0-" so it sorts before any other mu-plugin in
 * wp_get_mu_plugins()'s alphabetical scan).
 *
 * When the incoming request carries ?reprint-api or the legacy
 * ?site-export-api, this loader handles the request directly and calls
 * exit(). Otherwise it returns immediately and normal WordPress boot
 * proceeds.
 *
 * # What this skips, vs. the regular-plugin install
 *
 * The regular plugin at wp-content/plugins/reprint-exporter-wp/index.php
 * intercepts during the same plugin-load step that fires for every
 * other active plugin. By the time it runs, wp-settings.php has already:
 *
 *   - parsed wp-config.php and connected $wpdb to MySQL
 *   - enumerated and required every file in wp-content/mu-plugins/
 *   - enumerated and required every active regular plugin that sorts
 *     alphabetically before "reprint-exporter-wp"
 *
 * On a typical wp.com Atomic / large managed-host site those add up to
 * 50–200 ms per request — paid on every file_fetch / file_index / sql
 * batch in a sync, which adds up over a multi-thousand-batch migration.
 *
 * As a mu-plugin sorting first, this loader skips:
 *
 *   - every mu-plugin that sorts after "0-reprint-exporter"
 *   - every regular plugin
 *   - every wp-settings.php step after wp_load_mu_plugins()
 *     (themes setup, locale, query, rewrite rules, etc.)
 *
 * What's still paid:
 *
 *   - wp-config.php + wp-load.php (constants, ABSPATH)
 *   - $wpdb instantiation in wp-settings.php (the export endpoints need
 *     it for db-pull anyway, so this isn't extra work)
 *   - any mu-plugin file that sorts before "0-reprint-exporter"
 *     (none in normal installs)
 *
 * # Coexistence with the regular plugin
 *
 * Safe to install alongside wp-content/plugins/reprint-exporter-wp/.
 * On API requests this mu-plugin exits before the regular plugin loads.
 * On non-API requests this mu-plugin returns silently and the regular
 * plugin continues to handle its admin-side responsibilities.
 *
 * # Failure mode if the regular plugin is missing
 *
 * The library files live in the regular plugin's directory. If that
 * directory is absent, we return without intercepting and let WordPress
 * surface the "endpoint not found" response itself. Better than
 * fatal-erroring on a misconfigured mu-plugin install — at least the
 * site keeps loading for everyone else.
 */

// Cheap guards first: bail out before doing any further work for the
// overwhelming majority of requests (which aren't reprint API calls).
if (!isset($_GET['reprint-api']) && !isset($_GET['site-export-api'])) {
    return;
}

if (!defined('WP_CONTENT_DIR')) {
    // wp-config.php hasn't run yet. This shouldn't happen in normal WP
    // boot (mu-plugins are loaded by wp-settings.php, which requires
    // wp-config.php first), but if some host hooks us via auto_prepend
    // or similar, fall through silently rather than fatal-error.
    return;
}

$reprint_lib = WP_CONTENT_DIR . '/plugins/reprint-exporter-wp/lib.php';
if (!is_readable($reprint_lib)) {
    // Regular plugin directory missing — let WP handle the unmatched
    // request via its normal 404 / not-found path. Don't fatal-error
    // here: an unhandled URL is recoverable; a fatal mu-plugin is not.
    return;
}

require_once $reprint_lib;
_site_export_handle_api_request();
exit;
