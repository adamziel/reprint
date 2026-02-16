<?php
/**
 * Bootstrap loader for the sqlite-database-integration plugin.
 *
 * Locates the plugin, includes its class files, polyfills the WordPress
 * hooks it needs (do_action, apply_filters), creates a WP_SQLite_Driver
 * instance, and wraps it in a SqliteDriverPDO adapter that MySQLDumpProducer
 * can use as if it were a regular PDO connection.
 *
 * This file is only loaded when DB_ENGINE === 'sqlite'.
 */

/**
 * Creates a SqliteDriverPDO wrapping a WP_SQLite_Driver for the given
 * database file path.
 *
 * @param string $sqlite_path Full path to the .sqlite database file
 *                            (e.g. /var/www/wp-content/database/.ht.sqlite).
 * @return SqliteDriverPDO
 * @throws RuntimeException If the plugin can't be found or the file doesn't exist.
 */
function create_sqlite_connection(string $sqlite_path): SqliteDriverPDO
{
    if (!file_exists($sqlite_path)) {
        throw new RuntimeException(
            "SQLite database file not found: {$sqlite_path}"
        );
    }

    $plugin_root = find_sqlite_plugin_root();
    if ($plugin_root === null) {
        throw new RuntimeException(
            "sqlite-database-integration plugin not found. " .
            "Searched wp-content/plugins/, wp-content/mu-plugins/, and wp-includes/sqlite-ast/."
        );
    }

    load_sqlite_plugin_classes($plugin_root);
    polyfill_wordpress_hooks();

    // Create the raw SQLite PDO connection.
    $raw_pdo = new PDO("sqlite:{$sqlite_path}");
    $raw_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // WP_SQLite_Connection wraps the raw PDO.
    $connection = new WP_SQLite_Connection([
        'path' => $sqlite_path,
        'pdo'  => $raw_pdo,
    ]);

    // WP_SQLite_Driver needs a database name — use 'wordpress' as a
    // conventional default. The actual database name doesn't matter for
    // export purposes since SQLite doesn't have named databases the way
    // MySQL does, but the driver uses it for INFORMATION_SCHEMA queries.
    $db_name = defined('DB_NAME') ? DB_NAME : 'wordpress';

    // Provide a minimal $wpdb stub if WordPress isn't loaded, because
    // WP_SQLite_Configurator calls $GLOBALS['wpdb']->set_prefix().
    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new class() {
            public function set_prefix(string $prefix): void {}
        };
    }

    $driver = new WP_SQLite_Driver($connection, $db_name);

    return new SqliteDriverPDO($driver, $raw_pdo);
}

/**
 * Locates the sqlite-database-integration plugin root directory.
 *
 * Searches in order:
 *   1. {wp_root}/wp-content/plugins/sqlite-database-integration/
 *   2. {wp_root}/wp-content/mu-plugins/sqlite-database-integration/
 *   3. {wp_root}/wp-includes/sqlite-ast/ (future core merge)
 *
 * The plugin root is identified by the presence of version.php (plugins)
 * or class-wp-sqlite-driver.php (core merge).
 */
function find_sqlite_plugin_root(): ?string
{
    // Determine the WordPress root — walk up from this plugin's directory
    // (generic/ → wordpress-plugin/ → wp-content/plugins/site-export/ → wp-content/ → wp-root)
    // or use the directory constant if set.
    $wp_root = null;
    if (defined('ABSPATH')) {
        $wp_root = rtrim(ABSPATH, '/');
    } else {
        // Walk up from __DIR__ looking for wp-config.php
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (file_exists($dir . '/wp-config.php')) {
                $wp_root = $dir;
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    if ($wp_root === null) {
        return null;
    }

    // Check plugin locations in order of likelihood.
    $candidates = [
        $wp_root . '/wp-content/plugins/sqlite-database-integration',
        $wp_root . '/wp-content/mu-plugins/sqlite-database-integration',
    ];

    foreach ($candidates as $candidate) {
        if (is_dir($candidate) && file_exists($candidate . '/version.php')) {
            return $candidate;
        }
    }

    // Future core merge location — the classes live directly in wp-includes/.
    // Check for the driver class file as the sentinel.
    $core_path = $wp_root . '/wp-includes/sqlite-ast';
    if (is_dir($core_path) && file_exists($core_path . '/class-wp-sqlite-driver.php')) {
        return $wp_root; // Return wp_root; load_sqlite_plugin_classes handles the layout.
    }

    return null;
}

/**
 * Includes all the class files that WP_SQLite_Driver depends on.
 *
 * The include order follows the plugin's own test bootstrap — parser
 * infrastructure first, then MySQL lexer/parser, then SQLite translation,
 * then the connection/driver layer.
 */
function load_sqlite_plugin_classes(string $plugin_root): void
{
    // Determine whether this is the plugin layout or the core merge layout.
    // Plugin layout: all files under $plugin_root/wp-includes/...
    // Core layout: files directly under $wp_root/wp-includes/...
    $includes_base = $plugin_root . '/wp-includes';
    if (!is_dir($includes_base . '/parser') && !is_dir($includes_base . '/sqlite-ast')) {
        // Might be the wp_root itself (core merge) — try wp-includes directly.
        $includes_base = $plugin_root . '/wp-includes';
    }

    // Load version constant if available.
    $version_file = $plugin_root . '/version.php';
    if (file_exists($version_file)) {
        require_once $version_file;
    }

    // Load PHP polyfills (str_starts_with, str_contains, etc. for PHP < 8.0).
    $polyfills_file = $plugin_root . '/php-polyfills.php';
    if (file_exists($polyfills_file)) {
        require_once $polyfills_file;
    }

    // 1. Parser infrastructure
    $parser_files = [
        $includes_base . '/parser/class-wp-parser-grammar.php',
        $includes_base . '/parser/class-wp-parser.php',
        $includes_base . '/parser/class-wp-parser-node.php',
        $includes_base . '/parser/class-wp-parser-token.php',
    ];

    // 2. MySQL parsing
    $mysql_files = [
        $includes_base . '/mysql/class-wp-mysql-token.php',
        $includes_base . '/mysql/class-wp-mysql-lexer.php',
        $includes_base . '/mysql/class-wp-mysql-parser.php',
    ];

    // 3. SQLite translation
    $sqlite_files = [
        $includes_base . '/sqlite/class-wp-sqlite-query-rewriter.php',
        $includes_base . '/sqlite/class-wp-sqlite-lexer.php',
        $includes_base . '/sqlite/class-wp-sqlite-token.php',
        $includes_base . '/sqlite/class-wp-sqlite-pdo-user-defined-functions.php',
        $includes_base . '/sqlite/class-wp-sqlite-translator.php',
    ];

    // 4. Connection and driver
    $driver_files = [
        $includes_base . '/sqlite-ast/class-wp-sqlite-connection.php',
        $includes_base . '/sqlite-ast/class-wp-sqlite-configurator.php',
        $includes_base . '/sqlite-ast/class-wp-sqlite-driver.php',
        $includes_base . '/sqlite-ast/class-wp-sqlite-driver-exception.php',
        $includes_base . '/sqlite-ast/class-wp-sqlite-information-schema-builder.php',
        $includes_base . '/sqlite-ast/class-wp-sqlite-information-schema-exception.php',
        $includes_base . '/sqlite-ast/class-wp-sqlite-information-schema-reconstructor.php',
    ];

    $all_files = array_merge($parser_files, $mysql_files, $sqlite_files, $driver_files);

    foreach ($all_files as $file) {
        if (file_exists($file)) {
            require_once $file;
        }
    }

    // Verify the critical class is available.
    if (!class_exists('WP_SQLite_Driver')) {
        throw new RuntimeException(
            "WP_SQLite_Driver class not found after loading plugin files from {$plugin_root}. " .
            "The sqlite-database-integration plugin may be an incompatible version."
        );
    }
}

/**
 * Defines do_action() and apply_filters() stubs if WordPress isn't loaded.
 *
 * WP_SQLite_Driver and its dependencies call these hooks. Outside of
 * WordPress they need to be no-ops. apply_filters() must return its
 * second argument (the unfiltered value) to preserve correct behavior.
 */
function polyfill_wordpress_hooks(): void
{
    if (!function_exists('do_action')) {
        function do_action() {}
    }

    if (!function_exists('apply_filters')) {
        function apply_filters($tag, $value, ...$args) {
            return $value;
        }
    }
}
