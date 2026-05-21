<?php

/**
 * Builds SQLite prepared INSERT statements for MySQLDumpProducer-shaped SQL.
 *
 * This is deliberately narrower than SqlStatementRewriter: it only accepts
 * INSERT statements that FastInsertScanner fully recognises. Every complete
 * FROM_BASE64(...) expression is replaced with a positional placeholder and
 * its decoded bytes are returned as a bound PHP string. Values that do not
 * match the producer shape return null so db-apply can fall back to the normal
 * MySQL-on-SQLite execution path.
 *
 * The important property is that decoded payload bytes never become SQL text.
 * That avoids quoting, escaping, UTF-8, and "garbage codepoint" questions: the
 * SQL parser sees only `?`, and SQLite receives the exact PHP string through
 * PDO binding.
 */
class SQLitePreparedInsertBuilder
{
    /**
     * Build a fully parameterized producer-shape INSERT.
     *
     * Unlike build(), this treats the INSERT as structured rows rather than as
     * SQL text with only FROM_BASE64(...) holes. Every producer value becomes a
     * positional placeholder, so the generated SQL is stable for the same
     * table/column/row-count shape and callers can cache the PDOStatement.
     *
     * Unknown shapes return null so db-apply can fall back to the legacy
     * MySQL-on-SQLite path.
     *
     * @param callable|null $rewrite_value Optional callback:
     *        fn(string $value, string $table, ?string $column): string
     * @return array{sql: string, params: list<mixed>, param_types: list<int>, table: string, columns: list<string>, row_count: int}|null
     */
    public static function build_structured(string $sql, ?callable $rewrite_value = null): ?array
    {
        if (stripos($sql, 'FROM_BASE64(') === false) {
            return null;
        }

        $tokens = self::significant_tokens($sql);
        $token_count = count($tokens);
        $cursor = 0;

        if (
            $token_count < 8
            || $tokens[$cursor]->id !== WP_MySQL_Lexer::INSERT_SYMBOL
        ) {
            return null;
        }
        $cursor++;

        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::INTO_SYMBOL) {
            return null;
        }
        $cursor++;

        if ($cursor >= $token_count) {
            return null;
        }

        $table = self::identifier_token_value($tokens[$cursor]);
        if ($table === null) {
            return null;
        }
        $cursor++;

        if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::DOT_SYMBOL) {
            return null;
        }

        $columns = self::consume_column_list($tokens, $token_count, $cursor);
        if ($columns === null || empty($columns)) {
            return null;
        }

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

        $params = [];
        $param_types = [];
        $column_count = count($columns);
        $row_count = 0;
        $saw_base64 = false;

        while ($cursor < $token_count) {
            if ($tokens[$cursor]->id === WP_MySQL_Lexer::SEMICOLON_SYMBOL) {
                $cursor++;
                break;
            }

            if ($tokens[$cursor]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                return null;
            }
            $cursor++;

            for ($col_idx = 0; $col_idx < $column_count; $col_idx++) {
                $value = self::consume_structured_value($tokens, $token_count, $cursor, $sql);
                if ($value === null) {
                    return null;
                }

                if ($value['kind'] === 'base64') {
                    $saw_base64 = true;
                    $decoded = base64_decode($value['encoded_value'], true);
                    if ($decoded === false) {
                        return null;
                    }

                    if ($rewrite_value !== null) {
                        $decoded = $rewrite_value($decoded, $table, $columns[$col_idx]);
                    }

                    $params[] = $decoded;
                    $param_types[] = PDO::PARAM_STR;
                } else {
                    $params[] = $value['value'];
                    $param_types[] = $value['pdo_type'];
                }

                if ($cursor >= $token_count) {
                    return null;
                }

                if ($tokens[$cursor]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                    if ($col_idx === $column_count - 1) {
                        return null;
                    }
                    $cursor++;
                    continue;
                }

                if ($tokens[$cursor]->id === WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                    if ($col_idx !== $column_count - 1) {
                        return null;
                    }
                    break;
                }

                return null;
            }

            if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                return null;
            }
            $cursor++;
            $row_count++;

            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::COMMA_SYMBOL) {
                $cursor++;
                continue;
            }

            if ($cursor < $token_count && $tokens[$cursor]->id === WP_MySQL_Lexer::SEMICOLON_SYMBOL) {
                $cursor++;
                break;
            }

            if ($cursor >= $token_count) {
                break;
            }

            return null;
        }

        if ($cursor !== $token_count || $row_count === 0 || !$saw_base64) {
            return null;
        }

        $quoted_columns = array_map([self::class, 'quote_identifier'], $columns);
        $tuple = '(' . implode(',', array_fill(0, $column_count, '?')) . ')';

        return [
            'sql' => sprintf(
                'INSERT INTO %s (%s) VALUES %s;',
                self::quote_identifier($table),
                implode(',', $quoted_columns),
                implode(',', array_fill(0, $row_count, $tuple))
            ),
            'params' => $params,
            'param_types' => $param_types,
            'table' => $table,
            'columns' => $columns,
            'row_count' => $row_count,
        ];
    }

    /**
     * @param callable|null $rewrite_value Optional callback:
     *        fn(string $value, string $table, ?string $column): string
     * @return array{sql: string, params: list<string>}|null
     */
    public static function build(string $sql, ?callable $rewrite_value = null): ?array
    {
        if (strpos($sql, 'FROM_BASE64(') === false) {
            return null;
        }

        $fast = FastInsertScanner::scan($sql);
        if ($fast === null || empty($fast['base64_entries'])) {
            return null;
        }

        $parts = [];
        $params = [];
        $cursor = 0;

        foreach ($fast['base64_entries'] as $entry) {
            if ($entry['expr_start'] < $cursor || $entry['expr_length'] <= 0) {
                return null;
            }

            $decoded = base64_decode($entry['encoded_value'], true);
            if ($decoded === false) {
                return null;
            }
            $value = $decoded;
            if ($rewrite_value !== null) {
                $column = self::find_column_at_offset($fast['column_map'], $entry['expr_start']);
                $value = $rewrite_value($value, $fast['table'], $column);
            }

            $parts[] = substr($sql, $cursor, $entry['expr_start'] - $cursor);
            $parts[] = '?';
            $params[] = $value;
            $cursor = $entry['expr_start'] + $entry['expr_length'];
        }

        $parts[] = substr($sql, $cursor);

        return [
            'sql' => implode('', $parts),
            'params' => $params,
        ];
    }

    /**
     * @param list<array{int, int, string}> $column_map
     */
    private static function find_column_at_offset(array $column_map, int $offset): ?string
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
     * @return array{kind: string, value?: mixed, pdo_type?: int, encoded_value?: string}|null
     */
    private static function consume_structured_value(array $tokens, int $token_count, int &$cursor, string $sql): ?array
    {
        if ($cursor >= $token_count) {
            return null;
        }

        $token = $tokens[$cursor];

        if ($token->id === WP_MySQL_Lexer::NULL_SYMBOL) {
            $cursor++;
            return [
                'kind' => 'null',
                'value' => null,
                'pdo_type' => PDO::PARAM_NULL,
            ];
        }

        if (
            (
                $token->id === WP_MySQL_Lexer::SINGLE_QUOTED_TEXT
                || $token->id === WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT
            )
            && $token->get_value() === ''
        ) {
            $cursor++;
            return [
                'kind' => 'empty',
                'value' => '',
                'pdo_type' => PDO::PARAM_STR,
            ];
        }

        if (self::is_from_base64_token($token)) {
            $encoded = self::consume_base64_call($tokens, $token_count, $cursor);
            return $encoded === null
                ? null
                : [
                    'kind' => 'base64',
                    'encoded_value' => $encoded,
                ];
        }

        if ($token->id === WP_MySQL_Lexer::CONVERT_SYMBOL) {
            $cursor++;
            if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                return null;
            }
            $cursor++;

            if ($cursor >= $token_count || !self::is_from_base64_token($tokens[$cursor])) {
                return null;
            }
            $encoded = self::consume_base64_call($tokens, $token_count, $cursor);
            if ($encoded === null) {
                return null;
            }

            if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::USING_SYMBOL) {
                return null;
            }
            $cursor++;

            if ($cursor >= $token_count || strcasecmp($tokens[$cursor]->get_value(), 'utf8mb4') !== 0) {
                return null;
            }
            $cursor++;

            if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                return null;
            }
            $cursor++;

            return [
                'kind' => 'base64',
                'encoded_value' => $encoded,
            ];
        }

        $first_token = $token;
        if (
            $token->id === WP_MySQL_Lexer::PLUS_OPERATOR
            || $token->id === WP_MySQL_Lexer::MINUS_OPERATOR
        ) {
            $cursor++;
            if (
                $cursor >= $token_count
                || !self::is_numeric_token($tokens[$cursor])
                || $first_token->start + $first_token->length !== $tokens[$cursor]->start
            ) {
                return null;
            }
            $token = $tokens[$cursor];
        } elseif (!self::is_numeric_token($token)) {
            return null;
        }
        $cursor++;

        return [
            'kind' => 'numeric',
            'value' => substr($sql, $first_token->start, ($token->start + $token->length) - $first_token->start),
            'pdo_type' => PDO::PARAM_STR,
        ];
    }

    /**
     * @param WP_MySQL_Token[] $tokens
     * @return list<string>|null
     */
    private static function consume_column_list(array $tokens, int $token_count, int &$cursor): ?array
    {
        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
            return null;
        }
        $cursor++;

        $columns = [];
        while ($cursor < $token_count && $tokens[$cursor]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            $column = self::identifier_token_value($tokens[$cursor]);
            if ($column === null) {
                return null;
            }
            $columns[] = $column;
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

        return $columns;
    }

    /**
     * @param WP_MySQL_Token[] $tokens
     */
    private static function consume_base64_call(array $tokens, int $token_count, int &$cursor): ?string
    {
        if ($cursor >= $token_count || !self::is_from_base64_token($tokens[$cursor])) {
            return null;
        }
        $cursor++;

        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
            return null;
        }
        $cursor++;

        if (
            $cursor >= $token_count
            || (
                $tokens[$cursor]->id !== WP_MySQL_Lexer::SINGLE_QUOTED_TEXT
                && $tokens[$cursor]->id !== WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT
            )
        ) {
            return null;
        }
        $payload = $tokens[$cursor]->get_value();
        $cursor++;

        if ($payload !== '' && !preg_match('/\A[A-Za-z0-9+\/=]*\z/', $payload)) {
            return null;
        }

        if ($cursor >= $token_count || $tokens[$cursor]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
            return null;
        }
        $cursor++;

        return $payload;
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function identifier_token_value(WP_MySQL_Token $token): ?string
    {
        return (
            $token->id === WP_MySQL_Lexer::BACK_TICK_QUOTED_ID
            || $token->id === WP_MySQL_Lexer::IDENTIFIER
        )
            ? $token->get_value()
            : null;
    }

    private static function is_from_base64_token(WP_MySQL_Token $token): bool
    {
        return $token->id === WP_MySQL_Lexer::IDENTIFIER
            && strcasecmp($token->get_value(), 'FROM_BASE64') === 0;
    }

    private static function is_numeric_token(WP_MySQL_Token $token): bool
    {
        return $token->id === WP_MySQL_Lexer::INT_NUMBER
            || $token->id === WP_MySQL_Lexer::LONG_NUMBER
            || $token->id === WP_MySQL_Lexer::ULONGLONG_NUMBER
            || $token->id === WP_MySQL_Lexer::DECIMAL_NUMBER
            || $token->id === WP_MySQL_Lexer::FLOAT_NUMBER;
    }

    /**
     * @return WP_MySQL_Token[]
     */
    private static function significant_tokens(string $sql): array
    {
        $lexer = new WP_MySQL_Lexer($sql);
        $tokens = $lexer->remaining_tokens();
        if (
            !empty($tokens)
            && end($tokens)->id === WP_MySQL_Lexer::EOF
        ) {
            array_pop($tokens);
        }
        return $tokens;
    }
}
