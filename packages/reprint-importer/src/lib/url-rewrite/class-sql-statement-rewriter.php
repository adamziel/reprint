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
     * Parse the SQL and build an offset→column map.
     *
     * Fast path: a lexer-only walk handles the overwhelming common case
     * (multi-row `INSERT INTO \`t\` (\`c1\`,\`c2\`,…) VALUES (…),(…),…;` as
     * emitted by `MySQLDumpProducer`). Every INSERT in a dump matches that
     * shape, and a full AST build there was costing ~125 ms per statement
     * under WASM PHP — more than the actual URL rewriting on each statement.
     *
     * Slow path: UPDATE and anything the fast path rejects fall through to
     * the full `WP_MySQL_Parser` AST, which handles complex constructs
     * (subqueries, CONVERT(...), nested expressions, joins in UPDATE) that
     * a token walker can't safely characterise.
     *
     * @return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private function parse_statement(string $sql): ?array
    {
        $fast = $this->parse_insert_fast($sql);
        if ($fast !== null) {
            return $fast;
        }
        return $this->parse_statement_via_ast($sql);
    }

    /**
     * Token-only extractor for the canonical INSERT shape emitted by
     * MySQLDumpProducer. Returns null on anything unexpected so the caller
     * falls back to the full grammar-driven parser.
     *
     * Accepted shape (whitespace / comments between tokens allowed):
     *
     *     INSERT INTO `t` (`c1`,`c2`,…) VALUES (e1,e2,…),(e1,e2,…),…;
     *
     * Not accepted (fast path returns null):
     *   - INSERT … SELECT …
     *   - INSERT … SET col=expr, …
     *   - INSERT without column list
     *   - REPLACE / INSERT IGNORE / INSERT … ON DUPLICATE KEY UPDATE
     *   - fully-qualified table names like `db`.`t`
     *
     * @return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private function parse_insert_fast(string $sql): ?array
    {
        $lexer = new WP_MySQL_Lexer($sql);
        $tokens = self::filter_significant_tokens($lexer->remaining_tokens());

        $n = count($tokens);
        if ($n < 6) {
            return null;
        }

        // Expect: INSERT INTO <ident>
        if (
            $tokens[0]->id !== WP_MySQL_Lexer::INSERT_SYMBOL
            || $tokens[1]->id !== WP_MySQL_Lexer::INTO_SYMBOL
        ) {
            return null;
        }

        $table = self::unquote_identifier_token($tokens[2]);
        if ($table === null) {
            return null;
        }

        // Expect: `(` col_list `)` VALUES `(` … `)` [ `,` `(` … `)` ]* `;`
        $i = 3;
        if ($tokens[$i]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
            return null;
        }
        $i++;

        $columns = [];
        while ($i < $n && $tokens[$i]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            $col = self::unquote_identifier_token($tokens[$i]);
            if ($col === null) {
                return null;
            }
            $columns[] = $col;
            $i++;
            if ($i < $n && $tokens[$i]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $i++;
            }
        }
        if ($i >= $n || $tokens[$i]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            return null;
        }
        $i++;

        if ($i >= $n || $tokens[$i]->id !== WP_MySQL_Lexer::VALUES_SYMBOL) {
            return null;
        }
        $i++;

        // Walk each `(…)` tuple, mapping each top-level expression to a
        // column by position. Expression byte ranges run from the first
        // byte of the first token in the expression through the last byte
        // of the last token, giving the same [start, end) boundaries the
        // AST-driven path produced.
        $column_map = [];
        $col_count = count($columns);
        while ($i < $n) {
            if ($tokens[$i]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                return null;
            }
            $i++; // skip `(`

            $col_index = 0;
            $expr_start_token = $i;
            $depth = 0;
            while ($i < $n) {
                $id = $tokens[$i]->id;
                if ($id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                    $depth++;
                } elseif ($id === WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                    if ($depth === 0) {
                        // End of tuple — record the last expression.
                        if ($col_index < $col_count && $expr_start_token < $i) {
                            $first = $tokens[$expr_start_token];
                            $last = $tokens[$i - 1];
                            $column_map[] = [
                                $first->start,
                                $last->start + $last->length,
                                $columns[$col_index],
                            ];
                        }
                        break;
                    }
                    $depth--;
                } elseif ($id === WP_MySQL_Lexer::COMMA_SYMBOL && $depth === 0) {
                    if ($col_index < $col_count && $expr_start_token < $i) {
                        $first = $tokens[$expr_start_token];
                        $last = $tokens[$i - 1];
                        $column_map[] = [
                            $first->start,
                            $last->start + $last->length,
                            $columns[$col_index],
                        ];
                    }
                    $col_index++;
                    $expr_start_token = $i + 1;
                }
                $i++;
            }
            if ($i >= $n) {
                return null;
            }
            $i++; // skip `)`

            if ($i < $n && $tokens[$i]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $i++; // another tuple follows
                continue;
            }
            // Allow an optional trailing `;` — nothing else may follow.
            if ($i < $n && $tokens[$i]->id === WP_MySQL_Lexer::SEMICOLON_SYMBOL) {
                $i++;
            }
            if ($i !== $n) {
                return null;
            }
            break;
        }

        return ['table' => $table, 'column_map' => $column_map];
    }

    /**
     * Return the input array minus whitespace and comment tokens. The lexer
     * emits those as real tokens; we want to walk the stream at statement
     * granularity so they just get in the way.
     *
     * @param WP_MySQL_Token[] $tokens
     * @return WP_MySQL_Token[]
     */
    private static function filter_significant_tokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if (
                $t->id === WP_MySQL_Lexer::WHITESPACE
                || $t->id === WP_MySQL_Lexer::COMMENT
                || $t->id === WP_MySQL_Lexer::MYSQL_COMMENT_START
                || $t->id === WP_MySQL_Lexer::MYSQL_COMMENT_END
            ) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Extract the bare identifier text from a single-identifier token. Returns
     * null for anything that isn't an identifier (keyword, punctuation, etc.)
     * or for qualified names like `db`.`t` that would span multiple tokens —
     * the fast path punts on those and falls back to the AST.
     */
    private static function unquote_identifier_token(WP_MySQL_Token $token): ?string
    {
        if ($token->id === WP_MySQL_Lexer::BACK_TICK_QUOTED_ID) {
            $raw = $token->get_bytes();
            // Strip surrounding backticks and unescape `` -> `
            return str_replace('``', '`', substr($raw, 1, strlen($raw) - 2));
        }
        if ($token->id === WP_MySQL_Lexer::IDENTIFIER) {
            return $token->get_bytes();
        }
        return null;
    }

    /**
     * Slow-path parser used when the fast INSERT extractor can't handle the
     * statement (UPDATE, INSERT with unexpected shape, etc.).
     *
     * @return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private function parse_statement_via_ast(string $sql): ?array
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
     * @param list<array{int, int, string}> $column_map [start, end, column_name] entries.
     * @param int $offset Byte offset of the CONVERT or FROM_BASE64 token.
     * @return string|null Column name, or null if the offset isn't in any range.
     */
    private function find_column_at_offset(array $column_map, int $offset): ?string
    {
        foreach ($column_map as [$start, $end, $column]) {
            if ($offset >= $start && $offset < $end) {
                return $column;
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
