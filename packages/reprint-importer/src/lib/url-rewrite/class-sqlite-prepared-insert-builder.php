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

        if (!preg_match(
            '/\A\s*INSERT\s+INTO\s+`((?:[^`]|``)+)`\s*\(([^)]+)\)\s*VALUES\b/i',
            $sql,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $table = str_replace('``', '`', $m[1][0]);
        $columns = self::parse_column_list($m[2][0]);
        if ($columns === null || empty($columns)) {
            return null;
        }

        $column_count = count($columns);
        $cursor = $m[0][1] + strlen($m[0][0]);
        $sql_len = strlen($sql);
        $params = [];
        $param_types = [];
        $row_count = 0;
        $saw_base64 = false;

        while (true) {
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }

            if ($cursor >= $sql_len) {
                break;
            }

            if ($sql[$cursor] === ';') {
                $cursor++;
                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }
                if ($cursor !== $sql_len) {
                    return null;
                }
                break;
            }

            if ($sql[$cursor] !== '(') {
                return null;
            }
            $cursor++;

            for ($col_idx = 0; $col_idx < $column_count; $col_idx++) {
                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }

                $value = self::scan_structured_value($sql, $sql_len, $cursor);
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

                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }

                if ($cursor >= $sql_len) {
                    return null;
                }

                if ($sql[$cursor] === ',') {
                    if ($col_idx === $column_count - 1) {
                        return null;
                    }
                    $cursor++;
                    continue;
                }

                if ($sql[$cursor] === ')') {
                    if ($col_idx !== $column_count - 1) {
                        return null;
                    }
                    break;
                }

                return null;
            }

            if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
                return null;
            }
            $cursor++;
            $row_count++;

            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }

            if ($cursor < $sql_len && $sql[$cursor] === ',') {
                $cursor++;
                continue;
            }

            if ($cursor < $sql_len && $sql[$cursor] === ';') {
                $cursor++;
                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }
                if ($cursor !== $sql_len) {
                    return null;
                }
                break;
            }

            if ($cursor >= $sql_len) {
                break;
            }

            return null;
        }

        if ($row_count === 0 || !$saw_base64) {
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
     * @return list<string>|null
     */
    private static function parse_column_list(string $columns_body): ?array
    {
        $columns = [];
        if (!preg_match_all(
            '/\s*`((?:[^`]|``)+)`\s*(,|$)/A',
            $columns_body,
            $matches,
            PREG_SET_ORDER
        )) {
            return null;
        }

        $offset = 0;
        foreach ($matches as $match) {
            $columns[] = str_replace('``', '`', $match[1]);
            $offset += strlen($match[0]);
        }

        return $offset === strlen($columns_body) ? $columns : null;
    }

    /**
     * @return array{kind: string, value?: mixed, pdo_type?: int, encoded_value?: string}|null
     */
    private static function scan_structured_value(string $sql, int $sql_len, int &$cursor): ?array
    {
        if ($cursor >= $sql_len) {
            return null;
        }

        $c = $sql[$cursor];

        if (
            ($c === 'N' || $c === 'n')
            && $cursor + 4 <= $sql_len
            && substr_compare($sql, 'NULL', $cursor, 4, true) === 0
        ) {
            $cursor += 4;
            return [
                'kind' => 'null',
                'value' => null,
                'pdo_type' => PDO::PARAM_NULL,
            ];
        }

        if ($c === "'" && $cursor + 1 < $sql_len && $sql[$cursor + 1] === "'") {
            $cursor += 2;
            return [
                'kind' => 'empty',
                'value' => '',
                'pdo_type' => PDO::PARAM_STR,
            ];
        }

        if (
            ($c === 'F' || $c === 'f')
            && $cursor + 13 <= $sql_len
            && substr_compare($sql, 'FROM_BASE64(', $cursor, 12, true) === 0
        ) {
            $cursor += 12;
            $encoded = self::consume_base64_payload($sql, $sql_len, $cursor);
            return $encoded === null
                ? null
                : [
                    'kind' => 'base64',
                    'encoded_value' => $encoded,
                ];
        }

        if (
            ($c === 'C' || $c === 'c')
            && $cursor + 8 <= $sql_len
            && substr_compare($sql, 'CONVERT(', $cursor, 8, true) === 0
        ) {
            $cursor += 8;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if (
                $cursor + 12 > $sql_len
                || substr_compare($sql, 'FROM_BASE64(', $cursor, 12, true) !== 0
            ) {
                return null;
            }
            $cursor += 12;
            $encoded = self::consume_base64_payload($sql, $sql_len, $cursor);
            if ($encoded === null) {
                return null;
            }

            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor + 5 > $sql_len || substr_compare($sql, 'USING', $cursor, 5, true) !== 0) {
                return null;
            }
            $cursor += 5;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor + 7 > $sql_len || substr_compare($sql, 'utf8mb4', $cursor, 7, true) !== 0) {
                return null;
            }
            $cursor += 7;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
                return null;
            }
            $cursor++;

            return [
                'kind' => 'base64',
                'encoded_value' => $encoded,
            ];
        }

        $start = $cursor;
        if ($c === '+' || $c === '-') {
            $cursor++;
        }

        $digits_before_decimal = 0;
        while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
            $cursor++;
            $digits_before_decimal++;
        }

        $digits_after_decimal = 0;
        if ($cursor < $sql_len && $sql[$cursor] === '.') {
            $cursor++;
            while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
                $cursor++;
                $digits_after_decimal++;
            }
        }

        if ($digits_before_decimal === 0 && $digits_after_decimal === 0) {
            $cursor = $start;
            return null;
        }

        if ($cursor < $sql_len && ($sql[$cursor] === 'e' || $sql[$cursor] === 'E')) {
            $cursor++;
            if ($cursor < $sql_len && ($sql[$cursor] === '+' || $sql[$cursor] === '-')) {
                $cursor++;
            }

            $exponent_start = $cursor;
            while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
                $cursor++;
            }

            if ($cursor === $exponent_start) {
                $cursor = $start;
                return null;
            }
        }

        return [
            'kind' => 'numeric',
            'value' => substr($sql, $start, $cursor - $start),
            'pdo_type' => PDO::PARAM_STR,
        ];
    }

    private static function consume_base64_payload(string $sql, int $sql_len, int &$cursor): ?string
    {
        while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $sql_len || $sql[$cursor] !== "'") {
            return null;
        }

        $cursor++;
        $payload_start = $cursor;
        $close = strpos($sql, "'", $cursor);
        if ($close === false) {
            return null;
        }

        $payload = substr($sql, $payload_start, $close - $payload_start);
        if ($payload !== '' && !preg_match('/\A[A-Za-z0-9+\/=]*\z/', $payload)) {
            return null;
        }

        $cursor = $close + 1;
        while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
            return null;
        }
        $cursor++;

        return $payload;
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function is_ws(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
    }
}
