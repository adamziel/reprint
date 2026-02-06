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

require_once __DIR__ . '/wordpress/site-export.php';
