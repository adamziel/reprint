<?php

/**
 * Combines Base64ValueScanner and StructuredDataUrlRewriter to rewrite URLs
 * in an entire SQL statement.
 *
 * Only modifies INSERT and UPDATE statements containing FROM_BASE64() expressions.
 * DDL statements (CREATE TABLE, ALTER TABLE, etc.) pass through unchanged.
 *
 * Column-aware: for each FROM_BASE64() match, determines which column it belongs
 * to and passes the appropriate content type hint to StructuredDataUrlRewriter.
 * WordPress core columns known to contain block markup (post_content, comment_content,
 * etc.) get the 'block_markup' hint so wp_rewrite_urls() handles HTML attributes,
 * block comment JSON, and CSS url(). All other columns default to auto-detect with
 * plain text strtr() replacement, which is simpler and more predictable for columns
 * that contain serialized PHP, JSON, or plain strings.
 */
class SqlStatementRewriter
{
    private StructuredDataUrlRewriter $url_rewriter;

    /** @var array<string, array<string, string>> table_suffix => [column_name => content_type] */
    private array $column_hints;

    /**
     * WordPress core columns that contain block markup and benefit from
     * wp_rewrite_urls() over simple string replacement. Keyed by table suffix
     * (without prefix) so they match wp_posts, myprefix_posts, etc.
     * 
     * @TODO: Make this extensible, find a way to treat the relevant columns from plugin tables.
     */
    private const WP_BLOCK_MARKUP_COLUMNS = [
        'posts' => [
            'post_content' => 'block_markup',
            'post_content_filtered' => 'block_markup',
            'post_excerpt' => 'block_markup',
        ],
        'comments' => [
            'comment_content' => 'block_markup',
        ],
        'term_taxonomy' => [
            'description' => 'block_markup',
        ],
    ];

    /**
     * @param StructuredDataUrlRewriter $url_rewriter
     * @param array<string, array<string, string>> $column_hints Consumer-provided hints:
     *        table_suffix => [column_name => content_type]. Merged on top of WordPress defaults.
     */
    public function __construct(StructuredDataUrlRewriter $url_rewriter, array $column_hints = [])
    {
        $this->url_rewriter = $url_rewriter;
        $this->column_hints = $column_hints;
    }

    /**
     * Rewrite URLs in a SQL statement.
     *
     * @param string $sql The SQL statement.
     * @return string The modified SQL statement.
     */
    public function rewrite(string $sql): string
    {
        // Quick check: if no base64 values, nothing to rewrite
        if (strpos($sql, "FROM_BASE64(") === false) {
            return $sql;
        }

        // Parse the INSERT/UPDATE header to determine table and columns
        $header = $this->parse_header($sql);

        // Iterate over all FROM_BASE64() values using the cursor-based scanner
        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $value = $scanner->get_value();

            // Determine content type hint for this column
            $content_type = null;
            if ($header !== null) {
                $column_name = $this->resolve_column_for_match($sql, $scanner->get_match_offset(), $header);
                if ($column_name !== null) {
                    $content_type = $this->get_content_type($header['table'], $column_name);
                }
            }

            // Rewrite URLs in the value — StructuredDataUrlRewriter classifies the
            // content type and applies the right strategy for each.
            $rewritten = $this->url_rewriter->rewrite($value, $content_type);

            // Only replace if the value actually changed
            if ($rewritten !== $value) {
                $scanner->set_value($rewritten);
            }
        }

        return $scanner->get_result();
    }

    /**
     * Parse the table name and column list from an INSERT or UPDATE statement.
     *
     * INSERT: INSERT INTO `table` (`col1`,`col2`,...) VALUES ...
     * UPDATE: UPDATE `table` SET `col` = ...
     * 
     * @TODO: Use the MySQL parser, not naive regexes.
     *
     * @return array{type: string, table: string, columns: string[]}|null
     */
    private function parse_header(string $sql): ?array
    {
        // INSERT INTO `table` (`col1`,`col2`,...) VALUES
        if (preg_match('/^INSERT\s+INTO\s+`([^`]+)`\s*\(([^)]+)\)\s*VALUES/i', $sql, $m)) {
            $table = $m[1];
            // Parse column names from `col1`,`col2`,...
            preg_match_all('/`([^`]+)`/', $m[2], $col_matches);
            return [
                'type' => 'INSERT',
                'table' => $table,
                'columns' => $col_matches[1],
            ];
        }

        // INSERT INTO `table` VALUES (no explicit column list — can't determine columns)
        if (preg_match('/^INSERT\s+INTO\s+`([^`]+)`\s*VALUES/i', $sql, $m)) {
            return [
                'type' => 'INSERT',
                'table' => $m[1],
                'columns' => [],
            ];
        }

        // UPDATE `table` SET ...
        if (preg_match('/^UPDATE\s+`([^`]+)`\s+SET\s+/i', $sql, $m)) {
            return [
                'type' => 'UPDATE',
                'table' => $m[1],
                'columns' => [],
            ];
        }

        return null;
    }

    /**
     * Determine which column a FROM_BASE64() expression belongs to.
     *
     * For INSERT: counts comma-separated positions between the row-opening
     * parenthesis and the expression offset to get the column index.
     *
     * For UPDATE: extracts the column name from `col` = before the expression.
     *
     * @param string $sql          The full SQL statement.
     * @param int    $match_offset Byte offset of the outermost expression (CONVERT or FROM_BASE64).
     * @param array  $header       Parsed header from parse_header().
     * @return string|null The column name, or null if it can't be determined.
     */
    private function resolve_column_for_match(string $sql, int $match_offset, array $header): ?string
    {
        if ($header['type'] === 'INSERT' && !empty($header['columns'])) {
            return $this->resolve_insert_column($sql, $match_offset, $header['columns']);
        }

        if ($header['type'] === 'UPDATE') {
            return $this->resolve_update_column($sql, $match_offset);
        }

        return null;
    }

    /**
     * For INSERT statements, determine column index by counting commas at
     * parenthesis depth 0 between the row-opening ( and the match offset.
     *
     * Scans backward from the match to find the row-opening parenthesis
     * (tracking depth to skip past CONVERT(...) wrappers), then counts
     * commas at depth 0 between that position and the match.
     */
    private function resolve_insert_column(string $sql, int $match_offset, array $columns): ?string
    {
        // Scan backward from the match to find the row-opening (
        $depth = 0;
        $row_start = null;
        for ($i = $match_offset - 1; $i >= 0; $i--) {
            $ch = $sql[$i];
            if ($ch === ')') {
                $depth++;
            } elseif ($ch === '(') {
                if ($depth === 0) {
                    $row_start = $i;
                    break;
                }
                $depth--;
            }
        }

        if ($row_start === null) {
            return null;
        }

        // Count commas at depth 0 between row_start and match_offset
        $comma_count = 0;
        $depth = 0;
        for ($i = $row_start + 1; $i < $match_offset; $i++) {
            $ch = $sql[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $comma_count++;
            }
        }

        if ($comma_count < count($columns)) {
            return $columns[$comma_count];
        }

        return null;
    }

    /**
     * For UPDATE statements, extract the column name from `col` = before
     * the FROM_BASE64() or CONCAT(`col`, FROM_BASE64()) expression.
     *
     * Matches patterns like:
     *   `column` = FROM_BASE64('...')
     *   `column` = CONVERT(FROM_BASE64('...') USING utf8mb4)
     *   `column` = CONCAT(`column`, FROM_BASE64('...'))
     */
    private function resolve_update_column(string $sql, int $match_offset): ?string
    {
        // Look at the SQL before this match for `column_name` = pattern
        $prefix = substr($sql, 0, $match_offset);

        // Match the last `column` = (possibly with CONCAT( in between)
        if (preg_match('/`([^`]+)`\s*=\s*(?:CONCAT\s*\(`[^`]+`,\s*)?(?:CONVERT\s*\()?$/', $prefix, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Look up the content type for a given table and column from the hints map.
     *
     * Checks consumer-provided hints first (exact table suffix match), then
     * falls back to WordPress core defaults. Returns null if neither has an
     * entry for this table+column, meaning auto-detect with plain text default.
     */
    private function get_content_type(string $table, string $column): ?string
    {
        // Check consumer hints and WP defaults, both keyed by table suffix
        foreach ([$this->column_hints, self::WP_BLOCK_MARKUP_COLUMNS] as $hints) {
            foreach ($hints as $suffix => $columns) {
                if ($this->table_matches_suffix($table, $suffix)) {
                    if (isset($columns[$column])) {
                        return $columns[$column];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if a table name matches a suffix. Handles both prefixed and
     * unprefixed table names: "wp_posts" matches "posts", "posts" matches
     * "posts", "myprefix_posts" matches "posts".
     * 
     * @TODO: Actually extract the table prefix from wp_config, do not use
     *        a naive heuristic like this.
     */
    private function table_matches_suffix(string $table, string $suffix): bool
    {
        if ($table === $suffix) {
            return true;
        }

        // Table name ends with _suffix
        $needle = '_' . $suffix;
        return substr($table, -strlen($needle)) === $needle;
    }
}
