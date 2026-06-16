<?php

if (!function_exists('resolve_sqlite_integration_path')) {
    function resolve_sqlite_integration_path(string $suffix = ''): string
    {
        $suffixes = [$suffix];
        $moved_paths = [
            '/php-polyfills.php' => '/packages/mysql-on-sqlite/src/php-polyfills.php',
            '/version.php' => '/packages/mysql-on-sqlite/src/version.php',
        ];
        if (isset($moved_paths[$suffix])) {
            $suffixes[] = $moved_paths[$suffix];
        }

        foreach ([dirname(__DIR__, 5), dirname(__DIR__, 6)] as $project_root) {
            foreach ($suffixes as $candidate_suffix) {
                $candidate = $project_root . '/lib/sqlite-database-integration' . $candidate_suffix;
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException(
            'SQLite target support requires lib/sqlite-database-integration to be initialized.'
        );
    }
}

if (!function_exists('resolve_sqlite_integration_plugin_path')) {
    function resolve_sqlite_integration_plugin_path(): string
    {
        foreach ([dirname(__DIR__, 5), dirname(__DIR__, 6)] as $project_root) {
            $root = $project_root . '/lib/sqlite-database-integration';
            $package = $root . '/packages/plugin-sqlite-database-integration';
            if (is_dir($package)) {
                return $package;
            }
            if (is_dir($root . '/wp-includes/sqlite')) {
                return $root;
            }
        }

        throw new RuntimeException(
            'SQLite runtime support requires lib/sqlite-database-integration to be initialized.'
        );
    }
}

if (!function_exists('register_sqlite_function')) {
    /**
     * Register a user-defined SQL function on a SQLite PDO. Routes to
     * Pdo\Sqlite::createFunction() on 8.4+; the legacy
     * PDO::sqliteCreateFunction() alias is deprecated in 8.5.
     */
    function register_sqlite_function(PDO $sqlite_pdo, string $name, callable $fn, int $num_args = 1): void
    {
        if ($sqlite_pdo instanceof PDO\SQLite) {
            $sqlite_pdo->createFunction($name, $fn, $num_args);
        } else {
            $sqlite_pdo->sqliteCreateFunction($name, $fn, $num_args);
        }
    }
}
