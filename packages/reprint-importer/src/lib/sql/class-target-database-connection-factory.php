<?php

namespace Reprint\Importer\Sql;

use PDO;
use PDOException;
use RuntimeException;
use function Reprint\Importer\SQLite\register_sqlite_function;
use function Reprint\Importer\SQLite\resolve_sqlite_integration_path;

final class TargetDatabaseConnectionFactory
{
    public static function sqlite(string $target_path, string $target_db): PDO
    {
        if (!extension_loaded("pdo_sqlite")) {
            throw new RuntimeException(
                "SQLite target support requires the pdo_sqlite extension.",
            );
        }

        // The bundled loader require_onces a fixed set of class files
        // relative to its own dirname. When the host already loaded a
        // different copy of those same classes, each class declaration
        // would throw a fatal "name already in use". Skip the loader
        // entirely when the host's copy is already in memory.
        $driver_loader = resolve_sqlite_integration_path("/wp-pdo-mysql-on-sqlite.php");
        if (
            class_exists(\WP_PDO_MySQL_On_SQLite::class, false) &&
            class_exists(\WP_Parser_Grammar::class, false)
        ) {
            $driver_loader = null;
        }

        if ($target_path !== ':memory:') {
            $target_dir = dirname($target_path);
            if ($target_dir !== '' && $target_dir !== '.' && !is_dir($target_dir)) {
                if (!mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
                    throw new RuntimeException(
                        "Cannot create SQLite directory: {$target_dir}",
                    );
                }
            }
        }

        if ($driver_loader !== null) {
            require_once $driver_loader;
        }

        $dsn = sprintf(
            "mysql-on-sqlite:path=%s;dbname=%s",
            self::escape_pdo_dsn_value($target_path),
            self::escape_pdo_dsn_value($target_db),
        );

        try {
            $pdo = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Cannot connect to target SQLite database: " . $e->getMessage(),
                0,
                $e,
            );
        }

        // SQL dumps encode values with FROM_BASE64(), and follow-up updates
        // may use TO_BASE64(), so every SQLite target connection needs both.
        $sqlite_pdo = $pdo->get_connection()->get_pdo();
        register_sqlite_function($sqlite_pdo, 'FROM_BASE64', function ($data) {
            if ($data === null) {
                return null;
            }
            return base64_decode($data);
        });
        register_sqlite_function($sqlite_pdo, 'TO_BASE64', function ($data) {
            if ($data === null) {
                return null;
            }
            return base64_encode($data);
        });

        return $pdo;
    }

    public static function mysql(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password
    ): PDO {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Cannot connect to target MySQL database: " . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    private static function escape_pdo_dsn_value(string $value): string
    {
        return str_replace(';', ';;', $value);
    }
}
