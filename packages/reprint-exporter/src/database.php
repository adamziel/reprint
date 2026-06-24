<?php

namespace Reprint\Exporter;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Resolves database credentials from PHP constants and environment variables.
 *
 * Never reads from $config / HTTP parameters; credentials must come from
 * the server environment.
 *
 * @return array{db_engine: string, db_host: string, db_name: string, db_user: string, db_password: string, wp_config_path: ?string, table_prefix: ?string}
 */
function resolve_db_credentials(): array
{
    $db_host = defined("DB_HOST") ? DB_HOST : getenv("DB_HOST");
    $db_name = defined("DB_NAME") ? DB_NAME : getenv("DB_NAME");
    $db_user = defined("DB_USER") ? DB_USER : getenv("DB_USER");
    $db_password = defined("DB_PASSWORD") ? DB_PASSWORD : getenv("DB_PASSWORD");

    $wp_config_path = null;
    $table_prefix = $GLOBALS['table_prefix'] ?? null;

    if (is_sqlite_site()) {
        return [
            "db_engine" => "sqlite",
            "db_host" => "",
            "db_name" => $db_name ?: "wordpress",
            "db_user" => "",
            "db_password" => "",
            "wp_config_path" => $wp_config_path,
            "table_prefix" => $table_prefix,
        ];
    }

    $missing = [];
    if (!$db_host) {
        $missing[] = "db_host";
    }
    if (!$db_name) {
        $missing[] = "db_name";
    }
    if (!$db_user) {
        $missing[] = "db_user";
    }
    if ($db_password === false || $db_password === null) {
        $missing[] = "db_password";
    }
    if (!empty($missing)) {
        throw new InvalidArgumentException(
            "Database credentials not found. Please provide via environment variables, " .
            "PHP constants, or ensure wp-config.php exists with valid credentials. " .
            "Missing: " . implode(", ", $missing),
        );
    }

    return [
        "db_engine" => "mysql",
        "db_host" => $db_host,
        "db_name" => $db_name,
        "db_user" => $db_user,
        "db_password" => $db_password,
        "wp_config_path" => $wp_config_path,
        "table_prefix" => $table_prefix,
    ];
}

/**
 * Returns true when the current WordPress site uses the SQLite backend.
 */
function is_sqlite_site(): bool
{
    return defined('SQLITE_DB_DROPIN_VERSION') && isset($GLOBALS['@pdo']);
}

/**
 * Creates a database connection appropriate for the detected backend.
 *
 * @param array<string, mixed> $creds Credentials from resolve_db_credentials().
 * @param array<int, mixed> $options PDO options used for MySQL connections.
 * @return mixed A real PDO for MySQL, or a PDO-compatible adapter.
 */
function create_db_connection(array $creds, array $options = [])
{
    if (($creds["db_engine"] ?? "mysql") === "sqlite") {
        return create_sqlite_pdo_adapter();
    }

    if (!extension_loaded('pdo_mysql')) {
        return create_wpdb_pdo_adapter();
    }

    $default_options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];
    $merged_options = $options + $default_options;

    return new PDO(
        build_pdo_dsn((string) $creds['db_host'], (string) $creds['db_name']),
        (string) $creds["db_user"],
        (string) $creds["db_password"],
        $merged_options,
    );
}

/**
 * Wraps the already-loaded WP_SQLite_Driver in a PDO-compatible adapter.
 *
 * @return object PDO-compatible adapter (SqliteDriverPDO).
 */
function create_sqlite_pdo_adapter()
{
    global $wpdb;

    $min_version = '2.1.0';

    if (!isset($wpdb) || !($wpdb->dbh instanceof \WP_SQLite_Driver)) {
        throw new RuntimeException(
            "SQLite export requires WordPress loaded with the " .
            "sqlite-database-integration plugin active."
        );
    }

    if (defined('SQLITE_DRIVER_VERSION')) {
        if (version_compare(SQLITE_DRIVER_VERSION, $min_version, '<')) {
            throw new RuntimeException(
                "sqlite-database-integration plugin version " . SQLITE_DRIVER_VERSION .
                " is too old. Minimum required: " . $min_version
            );
        }
    }

    $driver = $wpdb->dbh;
    $raw_pdo = $driver->get_connection()->get_pdo();

    return new SqliteDriverPDO($driver, $raw_pdo);
}

/**
 * Wraps the global $wpdb in a PDO-shaped adapter.
 */
function create_wpdb_pdo_adapter()
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        throw new RuntimeException(
            "MySQL export without PDO requires WordPress \$wpdb to be initialized."
        );
    }

    $adapter = new WpdbDriverPDO($wpdb);
    $adapter->suppress_errors();
    return $adapter;
}
