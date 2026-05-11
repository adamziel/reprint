<?php
/**
 * Plugin Name: Reprint Exporter
 * Plugin URI: https://github.com/WordPress/playground-tools
 * Description: Reprint Exporter – exposes a site export API with HMAC-authenticated endpoints for database and file synchronization.
 * Version: 0.7.4-dev
 * Author: WordPress Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

require_once __DIR__ . '/lib.php';

// Intercept export API requests as early as possible.
// WordPress loads plugin files before firing `plugins_loaded`,
// so this runs before almost anything else in the WordPress stack.
//
// `?site-export-api` is the legacy query parameter kept for backwards
// compatibility with clients pinned to earlier plugin versions.
// New integrations should use `?reprint-api`.
if (isset($_GET['reprint-api']) || isset($_GET['site-export-api'])) {
    _site_export_handle_api_request();
    exit;
}

// Register the mu-plugin installer hooks. The installer itself is
// best-effort — failures are recorded to a site option and surfaced on
// the admin page; activation/deactivation never fail because of mu-plugin
// install issues.
require_once __DIR__ . '/mu-plugin/install.php';
register_activation_hook(__FILE__, 'reprint_install_mu_plugin');
register_deactivation_hook(__FILE__, 'reprint_uninstall_mu_plugin');

// Register the settings page.
require_once __DIR__ . '/wordpress/site-export.php';
