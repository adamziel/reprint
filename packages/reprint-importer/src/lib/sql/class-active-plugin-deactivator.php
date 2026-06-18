<?php

namespace Reprint\Importer\Sql;

use PDO;
use Reprint\Importer\UrlRewrite\PhpSerializationProcessor;

final class ActivePluginDeactivator
{
    /**
     * Remove active plugins whose directories are listed in removed host paths.
     *
     * @param string[] $paths_to_remove Relative paths declared by the host manifest.
     * @return string[] Plugin basenames actually removed.
     */
    public static function deactivate_for_removed_paths(
        PDO $pdo,
        array $paths_to_remove,
        string $table_prefix = 'wp_',
        ?callable $audit = null
    ): array {
        $plugin_dirs = [];
        foreach ($paths_to_remove as $rel_path) {
            if (preg_match('#^wp-content/plugins/([^/]+)$#', $rel_path, $m)) {
                $plugin_dirs[] = $m[1];
            }
        }

        return self::deactivate_by_plugin_dirs(
            $pdo,
            $plugin_dirs,
            $table_prefix,
            "host-specific",
            $audit,
        );
    }

    /**
     * Deactivate plugins whose URL builders break when the new site URL
     * has a non-/ path segment.
     *
     * @return string[] Plugin basenames actually removed.
     */
    public static function deactivate_path_incompatible(
        PDO $pdo,
        string $new_site_url,
        string $table_prefix = 'wp_',
        ?callable $audit = null
    ): array {
        if ($new_site_url === "") {
            return [];
        }

        $path = parse_url($new_site_url, PHP_URL_PATH);
        if ($path === null || $path === "" || $path === "/") {
            return [];
        }

        return self::deactivate_by_plugin_dirs(
            $pdo,
            ['page-optimize'],
            $table_prefix,
            "path-incompatible siteurl",
            $audit,
        );
    }

    /**
     * Remove plugin entries whose basename starts with one of $plugin_dirs
     * from the `active_plugins` option in the target database.
     *
     * Requires `$pdo` to support `FROM_BASE64()` — native on MySQL 5.6+,
     * registered on SQLite by the importer SQLite target connection.
     *
     * @param string[] $plugin_dirs Plugin directory names to match against
     *                              each `active_plugins` entry's basename.
     * @return string[] Plugin basenames actually removed.
     */
    public static function deactivate_by_plugin_dirs(
        PDO $pdo,
        array $plugin_dirs,
        string $table_prefix = 'wp_',
        string $reason = 'plugin',
        ?callable $audit = null
    ): array {
        if (empty($plugin_dirs)) {
            return [];
        }

        $options_table = self::quote_table_name($table_prefix . 'options');

        // Stick to query()/exec() — WP_PDO_MySQL_On_SQLite overrides those
        // but not prepare(), and prepare() throws "object is uninitialized"
        // on the wrapper.
        $row = $pdo->query(
            "SELECT option_value FROM {$options_table} WHERE option_name = 'active_plugins'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($row['option_value'])) {
            return [];
        }

        // Use PhpSerializationProcessor to iterate string values safely —
        // no unserialize(), no risk of arbitrary object instantiation.
        $serialized = $row['option_value'];
        $processor = new PhpSerializationProcessor($serialized);
        if ($processor->is_malformed()) {
            return [];
        }

        $deactivated_plugins = [];
        $retained_plugins = [];
        while ($processor->next_value()) {
            $basename = $processor->get_value();
            $is_match = false;
            foreach ($plugin_dirs as $dir) {
                if (strpos($basename, $dir . '/') === 0) {
                    $is_match = true;
                    break;
                }
            }
            if ($is_match) {
                $deactivated_plugins[] = $basename;
            } else {
                $retained_plugins[] = $basename;
            }
        }

        if (empty($deactivated_plugins)) {
            self::audit($audit, "DB-APPLY | no {$reason} plugins found in active_plugins");
            return [];
        }

        // FROM_BASE64 carries the new value into SQL — base64 is
        // [A-Za-z0-9+/=], so the literal can't carry SQL-special characters
        // regardless of what a plugin basename contains.
        $encoded_value = base64_encode(serialize(array_values($retained_plugins)));
        $pdo->exec(
            "UPDATE {$options_table} SET option_value = FROM_BASE64('{$encoded_value}') WHERE option_name = 'active_plugins'"
        );
        // The SQL dump runs with AUTOCOMMIT=0 and issues a final COMMIT,
        // but autocommit stays off. Our UPDATE needs an explicit COMMIT.
        $pdo->exec('COMMIT');

        self::audit(
            $audit,
            "DB-APPLY | updated active_plugins (" .
            count($deactivated_plugins) . " {$reason} plugin(s) removed)",
        );

        return $deactivated_plugins;
    }

    private static function quote_table_name(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function audit(?callable $audit, string $message): void
    {
        if ($audit !== null) {
            $audit($message);
        }
    }
}
