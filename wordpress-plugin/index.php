<?php
/**
 * Plugin Name: Site Export
 * Plugin URI: https://github.com/WordPress/playground-tools
 * Description: Exposes a site export API with HMAC-authenticated endpoints for database and file synchronization.
 * Version: 1.0.0
 * Author: WordPress Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SITE_EXPORT_VERSION', '1.0.0');
define('SITE_EXPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SITE_EXPORT_SECRET_FILE', SITE_EXPORT_PLUGIN_DIR . 'secret.php');

// Intercept export API requests as early as possible.
// WordPress loads plugin files before firing `plugins_loaded`,
// so this runs before almost anything else in the WordPress stack.
if (isset($_GET['site-export-api'])) {
    // Revert WordPress error display settings (wp_debug_mode may
    // have enabled display_errors based on WP_DEBUG_DISPLAY).
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');

    // Clear any output buffering WordPress started.
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Hand off to api.php which sets up its own error handlers,
    // output buffering, HMAC auth, and runs the export endpoint.
    require __DIR__ . '/api.php';
    exit;
}

require_once __DIR__ . '/wordpress/site-export.php';
