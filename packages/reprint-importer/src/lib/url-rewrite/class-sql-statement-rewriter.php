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
 * Column resolution walks the lexer output directly. Both INSERT and UPDATE
 * statements emitted by MySQLDumpProducer follow a constrained set of shapes
 * the walker recognises; for any shape it doesn't, it returns null and every
 * FROM_BASE64() value falls back to plain-text URL rewriting (which is the
 * safe default anyway — block_markup is only ever assigned to a small set of
 * WordPress core columns).
 */
class SqlStatementRewriter
{
    private StructuredDataUrlRewriter $url_rewriter;

    /** @var array<string, array<string, string>> full_table_name => [column_name => content_type] */
    private array $db_columns_with_block_markup;

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
        // build a byte-offset→column map.
        $parsed_statement = $this->parse_statement($sql);

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
            if ($parsed_statement !== null) {
                $column_name = $this->find_column_at_offset(
                    $parsed_statement['column_map'],
                    $scanner->get_match_offset()
                );
                if ($column_name !== null) {
                    $content_type = $this->get_content_type($parsed_statement['table'], $column_name);
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
     * Walk the lexer output to recover, for an INSERT or UPDATE statement,
     * the byte-offset→column map needed to give each FROM_BASE64() value
     * the right content-type hint.
     *
     * Recognised shapes:
     *
     *   INSERT [LOW_PRIORITY|DELAYED|HIGH_PRIORITY] [IGNORE] INTO `t`
     *     (`c1`, `c2`, …) [VALUES|VALUE]
     *     [ROW]?(e1, e2, …) [, [ROW]?(…), …]
     *     [ON DUPLICATE KEY UPDATE …]?
     *     [;]?
     *
     *   REPLACE [LOW_PRIORITY|DELAYED] INTO `t` (… same shape …)
     *
     *   UPDATE [LOW_PRIORITY] [IGNORE] `t`
     *     SET `c1` = e1 [, `c2` = e2, …]
     *     [WHERE … | ORDER BY … | LIMIT …]?
     *     [;]?
     *
     * Anything else — INSERT … SELECT, INSERT … SET col=v, INSERT without a
     * column list, qualified names like `db`.`t`, multi-table UPDATE — returns
     * null. The caller treats null the same as an empty column_map: every
     * FROM_BASE64() value falls through to plain-text URL rewriting, which
     * is the safe default. URL rewriting still happens; only the
     * block_markup hint (relevant for ~5 WordPress core columns) is lost.
     *
     * The lexer already handles strings, comments, escaped backticks, hex /
     * binary / null literals and so on, so the walker only needs to track
     * parenthesis depth at the token level — string-literal tokens that
     * contain `(`, `)`, `,` etc. arrive as a single token and never affect
     * depth.
     *
     * @return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private function parse_statement(string $sql): ?array
    {
        $tokens = self::significant_tokens($sql);
        $token_count = count($tokens);
        if ($token_count < 4) {
            return null;
        }

        $cursor = 0;
        $first_keyword_id = $tokens[$cursor]->id;

        if (
            $first_keyword_id === WP_MySQL_Lexer::INSERT_SYMBOL
            || $first_keyword_id === WP_MySQL_Lexer::REPLACE_SYMBOL
        ) {
            return self::walk_insert($tokens, $token_count, $cursor);
        }

        if ($first_keyword_id === WP_MySQL_Lexer::UPDATE_SYMBOL) {
            return self::walk_update($tokens, $token_count, $cursor);
        }

        return null;
    }

    /**
     * Walk the body of an INSERT / REPLACE statement starting at
     * `$cursor` (which already points at the leading verb).
     *
     * @param WP_MySQL_Token[] $tokens
     * @return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private static function walk_insert(array $tokens, int $token_count, int $cursor): ?array
    {
        // Step past the leading INSERT or REPLACE.
        $cursor++;

        // Optional priority + IGNORE modifiers in any order MySQL accepts.
        // An unrecognised modifier drops us out of the fast path.
        while ($cursor < $token_count) {
            $modifier_id = $tokens[$cursor]->id;
            if (
                $modifier_id === WP_MySQL_Lexer::LOW_PRIORITY_SYMBOL
                || $modifier_id === WP_MySQL_Lexer::DELAYED_SYMBOL
                || $modifier_id === WP_MySQL_Lexer::HIGH_PRIORITY_SYMBOL
                || $modifier_id === WP_MySQL_Lexer::IGNORE_SYMBOL
            ) {
                $cursor++;
                continue;
            }
            break;
        }

        // INTO
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::INTO_SYMBOL) {
            return null;
        }
        $cursor++;

        // Table identifier. Reject qualified names — `db`.`t` would mean we
        // got the database wrong, and the column_map keys are matched by
        // bare table name anyway.
        if ($cursor >= $token_count) {
            return null;
        }
        $table_name = self::unquote_identifier_token($tokens[$cursor]);
        if ($table_name === null) {
            return null;
        }
        $cursor++;
        if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::DOT_SYMBOL) {
            return null;
        }

        // Required column list `( col, col, … )`. Without column names there's
        // nothing to map FROM_BASE64() byte offsets against.
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
            return null;
        }
        $cursor++;

        $column_names = [];
        while ($cursor < $token_count && $tokens[$cursor]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            $column_name = self::unquote_identifier_token($tokens[$cursor]);
            if ($column_name === null) {
                return null;
            }
            $column_names[] = $column_name;
            $cursor++;
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $cursor++;
                continue;
            }
            break;
        }
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            return null;
        }
        $cursor++;

        // VALUES (or its singular alias VALUE).
        if (
            $cursor >= $token_count
            || (
                $tokens[$cursor]->id !== WP_MySQL_Lexer::VALUES_SYMBOL
                && $tokens[$cursor]->id !== WP_MySQL_Lexer::VALUE_SYMBOL
            )
        ) {
            return null;
        }
        $cursor++;

        $column_count = count($column_names);
        $column_map = [];
        while ($cursor < $token_count) {
            // Optional ROW prefix (MySQL 8.0+ explicit row constructor).
            if ($tokens[$cursor]->id === WP_MySQL_Lexer::ROW_SYMBOL) {
                $cursor++;
                if ($cursor >= $token_count) {
                    return null;
                }
            }

            if ($tokens[$cursor]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                return null;
            }
            $cursor++; // step past `(`

            $column_index_in_row = 0;
            $expression_starts_at_token = $cursor;
            $paren_depth_inside_tuple = 0;
            $tuple_was_closed = false;
            while ($cursor < $token_count) {
                $current_token_id = $tokens[$cursor]->id;
                if ($current_token_id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                    $paren_depth_inside_tuple++;
                } elseif ($current_token_id === WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                    if ($paren_depth_inside_tuple === 0) {
                        if ($column_index_in_row < $column_count && $expression_starts_at_token < $cursor) {
                            $expression_first_token = $tokens[$expression_starts_at_token];
                            $expression_last_token = $tokens[$cursor - 1];
                            $column_map[] = [
                                $expression_first_token->start,
                                $expression_last_token->start + $expression_last_token->length,
                                $column_names[$column_index_in_row],
                            ];
                        }
                        $tuple_was_closed = true;
                        break;
                    }
                    $paren_depth_inside_tuple--;
                } elseif ($current_token_id === WP_MySQL_Lexer::COMMA_SYMBOL && $paren_depth_inside_tuple === 0) {
                    if ($column_index_in_row < $column_count && $expression_starts_at_token < $cursor) {
                        $expression_first_token = $tokens[$expression_starts_at_token];
                        $expression_last_token = $tokens[$cursor - 1];
                        $column_map[] = [
                            $expression_first_token->start,
                            $expression_last_token->start + $expression_last_token->length,
                            $column_names[$column_index_in_row],
                        ];
                    }
                    $column_index_in_row++;
                    $expression_starts_at_token = $cursor + 1;
                }
                $cursor++;
            }
            if (!$tuple_was_closed) {
                return null;
            }
            $cursor++; // step past `)`

            // Another tuple, statement terminator, or trailer keyword.
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $cursor++;
                continue;
            }
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::SEMICOLON_SYMBOL) {
                $cursor++;
            }
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::ON_SYMBOL) {
                // ON DUPLICATE KEY UPDATE … — anything past here is the
                // assignment list, which doesn't carry value-tuple
                // FROM_BASE64() the rewriter would map. Stop reading.
                break;
            }
            if ($cursor !== $token_count) {
                return null;
            }
            break;
        }

        return ['table' => $table_name, 'column_map' => $column_map];
    }

    /**
     * Walk the body of an UPDATE statement starting at `$cursor` (which
     * points at the leading UPDATE keyword).
     *
     * Single-table updates only: an unqualified table identifier followed
     * by SET, then a comma-separated list of `col = expression` assignments.
     * The expression range runs to the next comma at depth 0, or to the
     * first occurrence of WHERE / ORDER / LIMIT / `;` / end of input.
     *
     * @param WP_MySQL_Token[] $tokens
     * @return array{table: string, column_map: list<array{int, int, string}>}|null
     */
    private static function walk_update(array $tokens, int $token_count, int $cursor): ?array
    {
        // Step past UPDATE.
        $cursor++;

        while ($cursor < $token_count) {
            $modifier_id = $tokens[$cursor]->id;
            if (
                $modifier_id === WP_MySQL_Lexer::LOW_PRIORITY_SYMBOL
                || $modifier_id === WP_MySQL_Lexer::IGNORE_SYMBOL
            ) {
                $cursor++;
                continue;
            }
            break;
        }

        if ($cursor >= $token_count) {
            return null;
        }
        $table_name = self::unquote_identifier_token($tokens[$cursor]);
        if ($table_name === null) {
            return null;
        }
        $cursor++;
        if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::DOT_SYMBOL) {
            return null;
        }

        // SET keyword.
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::SET_SYMBOL) {
            return null;
        }
        $cursor++;

        // Reject multi-table UPDATEs by refusing anything that looks like a
        // join after the table name. (The walk above already accepts only a
        // single bare identifier, so we don't reach SET on multi-table
        // updates — this is just a guard for the unusual cases.)
        $column_map = [];
        while ($cursor < $token_count) {
            // Column being assigned to.
            $assigned_column_name = self::unquote_identifier_token($tokens[$cursor]);
            if ($assigned_column_name === null) {
                return null;
            }
            $cursor++;
            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::DOT_SYMBOL) {
                // `t.col = …` form — qualified column ref, give up on the fast
                // path so the rewriter falls back to plain-text rewriting.
                return null;
            }

            // EQUAL_OPERATOR, expressed as `=` token.
            if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::EQUAL_OPERATOR) {
                return null;
            }
            $cursor++;

            // Walk the expression until comma at depth 0, or a clause keyword.
            $expression_starts_at_token = $cursor;
            $paren_depth_in_expression = 0;
            while ($cursor < $token_count) {
                $current_token_id = $tokens[$cursor]->id;
                if ($current_token_id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                    $paren_depth_in_expression++;
                } elseif ($current_token_id === WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                    if ($paren_depth_in_expression === 0) {
                        // Stray `)` at depth 0 means the statement is malformed
                        // or shaped in a way we don't understand.
                        return null;
                    }
                    $paren_depth_in_expression--;
                } elseif ($paren_depth_in_expression === 0) {
                    if (
                        $current_token_id === WP_MySQL_Lexer::COMMA_SYMBOL
                        || $current_token_id === WP_MySQL_Lexer::WHERE_SYMBOL
                        || $current_token_id === WP_MySQL_Lexer::ORDER_SYMBOL
                        || $current_token_id === WP_MySQL_Lexer::LIMIT_SYMBOL
                        || $current_token_id === WP_MySQL_Lexer::SEMICOLON_SYMBOL
                    ) {
                        break;
                    }
                }
                $cursor++;
            }

            if ($cursor === $expression_starts_at_token) {
                // Empty expression on the right of `=` — malformed.
                return null;
            }

            $expression_first_token = $tokens[$expression_starts_at_token];
            $expression_last_token = $tokens[$cursor - 1];
            $column_map[] = [
                $expression_first_token->start,
                $expression_last_token->start + $expression_last_token->length,
                $assigned_column_name,
            ];

            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $cursor++;
                continue;
            }
            break;
        }

        return ['table' => $table_name, 'column_map' => $column_map];
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
     * Lex once and drop the tokens the walker doesn't care about: whitespace,
     * comments, and the lexer's terminal EOF marker. The lexer itself already
     * handles all the subtle string / comment / escape rules, so the walker
     * just needs to track parenthesis depth at the token level.
     *
     * @return WP_MySQL_Token[]
     */
    private static function significant_tokens(string $sql): array
    {
        $lexer = new WP_MySQL_Lexer($sql);
        $significant_tokens = [];
        foreach ($lexer->remaining_tokens() as $token) {
            $token_id = $token->id;
            if (
                $token_id === WP_MySQL_Lexer::WHITESPACE
                || $token_id === WP_MySQL_Lexer::COMMENT
                || $token_id === WP_MySQL_Lexer::MYSQL_COMMENT_START
                || $token_id === WP_MySQL_Lexer::MYSQL_COMMENT_END
                || $token_id === WP_MySQL_Lexer::EOF
            ) {
                continue;
            }
            $significant_tokens[] = $token;
        }
        return $significant_tokens;
    }

    /**
     * Strip the surrounding backticks (and unescape doubled backticks) from
     * a backtick-quoted identifier token, or return the raw bytes for an
     * unquoted IDENTIFIER. Anything else returns null so the walker bails.
     */
    private static function unquote_identifier_token($token): ?string
    {
        if ($token->id === WP_MySQL_Lexer::BACK_TICK_QUOTED_ID) {
            $raw_bytes = $token->get_bytes();
            return str_replace('``', '`', substr($raw_bytes, 1, strlen($raw_bytes) - 2));
        }
        if ($token->id === WP_MySQL_Lexer::IDENTIFIER) {
            return $token->get_bytes();
        }
        return null;
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
