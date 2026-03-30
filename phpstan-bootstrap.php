<?php
/**
 * PHPStan bootstrap – stub constants defined at runtime by WordPress
 * or by the plugin entry point (index.php) which is excluded from analysis.
 */

// WordPress core
define('ABSPATH', '/tmp/wordpress/');

// Defined in wordpress-plugin/index.php
define('SITE_EXPORT_VERSION', '1.0.0');
define('SITE_EXPORT_PLUGIN_DIR', __DIR__ . '/wordpress-plugin/');
define('SITE_EXPORT_SECRET_FILE', SITE_EXPORT_PLUGIN_DIR . 'secret.php');
define('SITE_EXPORT_TIMESTAMP_TOLERANCE', 300);
