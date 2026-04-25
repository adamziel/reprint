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
 *
 * Column resolution uses WP_MySQL_Parser to build a full AST, then maps each value
 * expression's byte range to its column name. Base64ValueScanner's match offset is
 * looked up against this map — no regex or manual comma-counting needed.
 */
class SqlStatementRewriter
{
    private StructuredDataUrlRewriter $url_rewriter;

    /** @var array<string, array<string, string>> full_table_name => [column_name => content_type] */
    private array $db_columns_with_block_markup;

    /** @var WP_Parser_Grammar|null Lazily loaded, shared across all instances. */
    private static ?WP_Parser_Grammar $grammar = null;

    /**
     * WordPress core columns that contain block markup and benefit from
     * wp_rewrite_urls() over simple string replacement. Keyed by table suffix
     * (without prefix) — the constructor prepends the actual table_prefix to
     * build full table names for exact matching.
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
     * @param string $table_prefix WordPress table prefix (e.g. "wp_"), used to
     *        build full table names for exact matching.
     * @param array<string, array<string, string>> $extra_db_columns_with_block_markup Consumer-provided hints:
     *        table_suffix => [column_name => content_type]. Merged on top of WordPress defaults.
     */
    public function __construct(StructuredDataUrlRewriter $url_rewriter, string $table_prefix = 'wp_', array $extra_db_columns_with_block_markup = [])
    {
        $this->url_rewriter = $url_rewriter;

        // Merge WP defaults with consumer hints (both keyed by suffix),
        // then prepend the table prefix to build full table names.
        // array_replace_recursive so consumer hints override WP defaults
        // for the same table+column (e.g. marking post_content as 'skip').
        $by_suffix = array_replace_recursive(
			self::WP_BLOCK_MARKUP_COLUMNS,
			$extra_db_columns_with_block_markup
        );
        $this->db_columns_with_block_markup = [];
        foreach ($by_suffix as $suffix => $columns) {
            // Some plugins create unprefixed tables, so we match both with and without the table
            // prefix.
            $this->db_columns_with_block_markup[$table_prefix . $suffix] = $columns;
            $this->db_columns_with_block_markup[$suffix] = $columns;
        }
    }

    /**
     * Rewrite URLs in a SQL statement.
     *
     * NOTE: base64-encoded values that do not contain the string "http" are
     * skipped entirely — column resolution and the StructuredDataUrlRewriter
     * pipeline are never run for them. This means URLs stored in base64
     * without an http/https scheme will not be rewritten.
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

        // Parse the INSERT/UPDATE statement to extract the table name and
        // build a byte-offset→column map from the AST.
        $parsed = $this->parse_statement($sql);

        // Iterate over all FROM_BASE64() values using the cursor-based scanner
        $scanner = new Base64ValueScanner($sql);
        while ($scanner->next_value()) {
            $value = $scanner->get_value();

            // Skip values that can't contain a URL we'd rewrite. Every
            // rewritable domain starts with http:// or https://, so a value
            // without "http" anywhere in it has nothing for us to do. This
            // avoids the column-map lookup and the full StructuredDataUrlRewriter
            // pipeline (HTML parse, block markup, PHP/JSON recursion) per value.
            // See https://github.com/adamziel/reprint/pull/152
            if (strpos($value, 'http') === false) {
                continue;
            }

            // Determine content type hint for this column
            $content_type = null;
            if ($parsed !== null) {
                $column_name = $this->find_column_at_offset($parsed['column_map'], $scanner->get_match_offset());
                if ($column_name !== null) {
                    $content_type = $this->get_content_type($parsed['table'], $column_name);
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
     * Parse the SQL with WP_MySQL_Parser to extract the table name and build
     * an offset→column map. Each map entry is [start, end, column_name] where
     * start/end are byte offsets of the value expression in the SQL string.
     *
     * Returns null for non-INSERT/UPDATE statements or parse failures — the
     * caller falls back to auto-detect with no column awareness.
     *
     * @return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private function parse_statement(string $sql): ?array
    {
        $lexer  = new WP_MySQL_Lexer($sql);
        $tokens = $lexer->remaining_tokens();
        $parser = new WP_MySQL_Parser(self::get_grammar(), $tokens);

        if (!$parser->next_query()) {
            return null;
        }

        $ast = $parser->get_query_ast();
        if (!$ast) {
            return null;
        }

        // AST: query → simpleStatement → insertStatement|updateStatement
        $simple = $ast->get_first_child_node('simpleStatement');
        if (!$simple) {
            return null;
        }

        $insert = $simple->get_first_child_node('insertStatement');
        if ($insert) {
            return $this->parse_insert($insert);
        }

        $update = $simple->get_first_child_node('updateStatement');
        if ($update) {
            return $this->parse_update($update);
        }

        return null;
    }

    /**
     * Extract table name, column list, and value expression ranges from an
     * INSERT statement AST node.
     *
     * AST shape:
     *   insertStatement
     *     tableRef                    → table name
     *     insertFromConstructor
     *       fields                    → column list (absent when no column list)
     *         insertIdentifier...     → one per column
     *       insertValues
     *         valueList
     *           values (per row)
     *             expr (per column)   → byte range mapped to column index
     */
    private function parse_insert(WP_Parser_Node $stmt): ?array
    {
        $table_ref = $stmt->get_first_child_node('tableRef');
        if (!$table_ref) {
            return null;
        }

        $table = $this->extract_identifier($table_ref);
        if ($table === null) {
            return null;
        }

        $constructor = $stmt->get_first_child_node('insertFromConstructor');
        if (!$constructor) {
            return ['table' => $table, 'column_map' => []];
        }

        // Column names from the optional `fields` node. When absent (INSERT
        // without column list), columns stays empty and the column_map will
        // have no entries — every value falls back to auto-detect.
        $columns = [];
        $fields_node = $constructor->get_first_child_node('fields');
        if ($fields_node) {
            foreach ($fields_node->get_child_nodes('insertIdentifier') as $insert_id) {
                $col_name = $this->extract_identifier($insert_id);
                if ($col_name !== null) {
                    $columns[] = $col_name;
                }
            }
        }

        // Build the offset→column map. Each `values` node has one `expr` child
        // per column, in declaration order. Multi-row INSERTs repeat this for
        // every row in the valueList.
        $column_map = [];
        $insert_values = $constructor->get_first_child_node('insertValues');
        if ($insert_values) {
            $value_list = $insert_values->get_first_child_node('valueList');
            if ($value_list) {
                foreach ($value_list->get_child_nodes('values') as $values_node) {
                    $exprs = $values_node->get_child_nodes('expr');
                    foreach ($exprs as $i => $expr) {
                        if ($i < count($columns)) {
                            $column_map[] = [
                                $expr->get_start(),
                                $expr->get_start() + $expr->get_length(),
                                $columns[$i],
                            ];
                        }
                    }
                }
            }
        }

        return ['table' => $table, 'column_map' => $column_map];
    }

    /**
     * Extract table name and value expression ranges from an UPDATE statement
     * AST node.
     *
     * AST shape:
     *   updateStatement
     *     tableReferenceList → tableRef   → table name
     *     updateList
     *       updateElement (per SET assignment)
     *         columnRef                    → column name
     *         expr                         → byte range for the value
     */
    private function parse_update(WP_Parser_Node $stmt): ?array
    {
        $table_ref_list = $stmt->get_first_child_node('tableReferenceList');
        if (!$table_ref_list) {
            return null;
        }

        $table_ref = $table_ref_list->get_first_descendant_node('tableRef');
        if (!$table_ref) {
            return null;
        }

        $table = $this->extract_identifier($table_ref);
        if ($table === null) {
            return null;
        }

        // Each updateElement has a columnRef (the column name) and an expr
        // (the value expression). The FROM_BASE64() call lives somewhere
        // inside the expr — possibly wrapped in CONVERT() or CONCAT().
        $column_map = [];
        $update_list = $stmt->get_first_child_node('updateList');
        if ($update_list) {
            foreach ($update_list->get_child_nodes('updateElement') as $element) {
                $col_ref = $element->get_first_child_node('columnRef');
                if (!$col_ref) {
                    continue;
                }

                $col_name = $this->extract_identifier($col_ref);
                $expr = $element->get_first_child_node('expr');
                if ($expr && $col_name !== null) {
                    $column_map[] = [
                        $expr->get_start(),
                        $expr->get_start() + $expr->get_length(),
                        $col_name,
                    ];
                }
            }
        }

        return ['table' => $table, 'column_map' => $column_map];
    }

    /**
     * Find which column a FROM_BASE64() expression belongs to by checking
     * which expression range contains the given byte offset.
     *
     * The column_map is built by parse_insert / parse_update by walking the
     * statement in source order, so it's already sorted by `start` offset
     * and the ranges never overlap. That lets us use a binary search instead
     * of the obvious linear scan — a multi-row INSERT can produce thousands
     * of entries and we look up an offset for every FROM_BASE64() value, so
     * the linear cost was quadratic in row count and showed up as ~150 µs per
     * lookup under WASM PHP.
     *
     * @param list<array{int, int, string}> $column_map [start, end, column_name] entries.
     * @param int $offset Byte offset of the CONVERT or FROM_BASE64 token.
     * @return string|null Column name, or null if the offset isn't in any range.
     */
    private function find_column_at_offset(array $column_map, int $offset): ?string
    {
        $low = 0;
        $high = count($column_map) - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $entry = $column_map[$mid];
            if ($offset < $entry[0]) {
                $high = $mid - 1;
            } elseif ($offset >= $entry[1]) {
                $low = $mid + 1;
            } else {
                return $entry[2];
            }
        }
        return null;
    }

    /**
     * Extract the identifier text from an AST node by finding the last
     * BACK_TICK_QUOTED_ID or IDENTIFIER descendant token. Using the last
     * token handles qualified names like `schema`.`table` — we want `table`.
     */
    private function extract_identifier(WP_Parser_Node $node): ?string
    {
        $tokens = $node->get_descendant_tokens(WP_MySQL_Lexer::BACK_TICK_QUOTED_ID);
        if (empty($tokens)) {
            $tokens = $node->get_descendant_tokens(WP_MySQL_Lexer::IDENTIFIER);
        }
        if (empty($tokens)) {
            return null;
        }
        return end($tokens)->get_value();
    }

    /**
     * Lazily load and cache the MySQL grammar. The grammar data (~200KB PHP
     * array) is expensive to inflate into a WP_Parser_Grammar, so we do it
     * once and share across all SqlStatementRewriter instances.
     */
    private static function get_grammar(): WP_Parser_Grammar
    {
        if (self::$grammar === null) {
            $path = null;
            foreach ([
                dirname(__DIR__, 5) . '/lib/sqlite-database-integration/wp-includes/mysql/mysql-grammar.php',
                dirname(__DIR__, 6) . '/lib/sqlite-database-integration/wp-includes/mysql/mysql-grammar.php',
            ] as $candidate) {
                if (file_exists($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
            if ($path === null) {
                throw new RuntimeException(
                    'sqlite-database-integration is missing. Run: git submodule update --init'
                );
            }
            $data = require $path;
            self::$grammar = new WP_Parser_Grammar($data);
        }
        return self::$grammar;
    }

    /**
     * Look up the content type for a given table and column. The
     * db_columns_with_block_markup map is keyed by full table name (prefix
     * already applied at construction time), so this is a direct lookup.
     *
     * Returns null if there's no entry for this table+column, meaning
     * auto-detect with plain text default.
     */
    private function get_content_type(string $table, string $column): ?string
    {
        return $this->db_columns_with_block_markup[$table][$column] ?? null;
    }
}
