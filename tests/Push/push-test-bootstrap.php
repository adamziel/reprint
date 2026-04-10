<?php
/**
 * Bootstrap helper for push tests.
 *
 * Loads the push-related functions from export.php without triggering the
 * "Cannot redeclare" fatal from utils.php double-loading. The Composer
 * autoloader loads vendor/wp-php-toolkit/streaming-exporter/src/utils.php,
 * and export.php does require_once on packages/.../src/utils.php — same
 * content, different paths, so PHP tries to re-declare functions.
 *
 * Solution: define the push functions here directly. They're small,
 * self-contained, and this avoids all side-effect issues with export.php
 * (ob_start, define, require_once).
 */

// PUSH_STAGING_TABLE_PREFIX constant
if (!defined('PUSH_STAGING_TABLE_PREFIX')) {
    define('PUSH_STAGING_TABLE_PREFIX', '_push_');
}

if (!function_exists('parse_multipart_body')) {
    /**
     * Parse a multipart/mixed body into an array of chunks.
     * Extracted from export.php to avoid loading it with side effects.
     */
    function parse_multipart_body(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $chunks = [];
        $parts = explode($delimiter, $body);
        array_shift($parts);

        foreach ($parts as $part) {
            if (str_starts_with(ltrim($part, "\r\n"), '--')) {
                break;
            }

            $header_end = strpos($part, "\r\n\r\n");
            if ($header_end === false) {
                $header_end = strpos($part, "\n\n");
                if ($header_end === false) {
                    continue;
                }
                $header_block = substr($part, 0, $header_end);
                $body_content = substr($part, $header_end + 2);
            } else {
                $header_block = substr($part, 0, $header_end);
                $body_content = substr($part, $header_end + 4);
            }

            $headers = [];
            foreach (explode("\n", $header_block) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $name = strtolower(trim(substr($line, 0, $colon)));
                    $value = ltrim(substr($line, $colon + 1));
                    $headers[$name] = $value;
                }
            }

            $body_content = rtrim($body_content, "\r\n");

            if (isset($headers['content-length'])) {
                $len = (int) $headers['content-length'];
                $body_content = substr($body_content, 0, $len);
            }

            $chunks[] = [
                'headers' => $headers,
                'body' => $body_content,
            ];
        }

        return $chunks;
    }
}

if (!function_exists('rewrite_table_names')) {
    /**
     * Rewrite table names in SQL from original to staging prefix.
     * Extracted from export.php.
     */
    function rewrite_table_names(string $sql, array $table_map): string
    {
        foreach ($table_map as $original => $staging) {
            $patterns = [
                "DROP TABLE IF EXISTS `{$original}`"  => "DROP TABLE IF EXISTS `{$staging}`",
                "CREATE TABLE `{$original}`"          => "CREATE TABLE `{$staging}`",
                "INSERT INTO `{$original}`"           => "INSERT INTO `{$staging}`",
                "REFERENCES `{$original}`"            => "REFERENCES `{$staging}`",
            ];
            foreach ($patterns as $from => $to) {
                $sql = str_replace($from, $to, $sql);
            }
        }
        return $sql;
    }
}

if (!function_exists('build_staging_table_map')) {
    /**
     * Build the table name map for push staging.
     * Extracted from export.php.
     */
    function build_staging_table_map(
        PDO $pdo,
        string $table_prefix,
        array $incoming_tables = []
    ): array {
        $map = [];

        $stmt = $pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['TABLE_NAME'];
            if ($table_prefix === '' || str_starts_with($name, $table_prefix)) {
                $map[$name] = PUSH_STAGING_TABLE_PREFIX . $name;
            }
        }

        foreach ($incoming_tables as $name) {
            if (!isset($map[$name]) && ($table_prefix === '' || str_starts_with($name, $table_prefix))) {
                $map[$name] = PUSH_STAGING_TABLE_PREFIX . $name;
            }
        }

        return $map;
    }
}
