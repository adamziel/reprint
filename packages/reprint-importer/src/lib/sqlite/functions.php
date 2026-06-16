<?php

if (!function_exists('reprint_sqlite_integration_roots')) {
    /**
     * Return possible sqlite-database-integration roots for supported runtime
     * layouts. This intentionally does not walk arbitrary parent directories:
     * import/export output directories such as ./exports are data, not runtime
     * dependencies.
     */
    function reprint_sqlite_integration_roots(): array
    {
        $roots = [];

        $explicit = getenv('REPRINT_SQLITE_INTEGRATION_ROOT');
        if (is_string($explicit) && $explicit !== '') {
            $roots[] = rtrim($explicit, '/\\');
        }

        $package_root = dirname(__DIR__, 3);
        $package_parent = dirname($package_root);

        // Monorepo and PHAR layout:
        //   <root>/packages/reprint-importer/src/lib/sqlite/functions.php
        //   phar://reprint.phar/packages/reprint-importer/src/lib/sqlite/functions.php
        if (basename($package_parent) === 'packages') {
            $roots[] = dirname($package_parent) . '/lib/sqlite-database-integration';
        }

        // Composer install layout:
        //   <project>/vendor/wp-php-toolkit/reprint-importer/src/lib/sqlite/functions.php
        $vendor_root = dirname($package_root, 2);
        if (
            basename($package_root) === 'reprint-importer' &&
            basename(dirname($package_root)) === 'wp-php-toolkit' &&
            basename($vendor_root) === 'vendor'
        ) {
            $roots[] = dirname($vendor_root) . '/lib/sqlite-database-integration';
        }

        return array_values(array_unique($roots));
    }
}

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

        foreach (reprint_sqlite_integration_roots() as $root) {
            foreach ($suffixes as $candidate_suffix) {
                $candidate = $root . $candidate_suffix;
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
        foreach (reprint_sqlite_integration_roots() as $root) {
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
