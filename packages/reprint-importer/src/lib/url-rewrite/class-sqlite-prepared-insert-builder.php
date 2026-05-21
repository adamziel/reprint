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
    private const SHAPE_CACHE_LIMIT = 32;

    /** @var list<array{table: string, chunks: list<string>, values: list<array<string, mixed>>, base64_indexes: list<int>}> */
    private static array $shape_cache = [];

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

        $cached = self::build_from_cached_shape($sql, $rewrite_value);
        if ($cached !== null) {
            return $cached;
        }

        $fast = FastInsertScanner::scan($sql);
        if ($fast === null || empty($fast['base64_entries'])) {
            return null;
        }

        self::remember_shape($sql, $fast);

        return self::build_from_scan($sql, $fast, $rewrite_value);
    }

    /**
     * @param array{
     *   table: string,
     *   column_map: list<array{int, int, string}>,
     *   base64_entries: list<array{expr_start: int, expr_length: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}>
     * } $fast
     * @param callable|null $rewrite_value
     * @return array{sql: string, params: list<string>}|null
     */
    private static function build_from_scan(string $sql, array $fast, ?callable $rewrite_value): ?array
    {
        $parts = [];
        $params = [];
        $cursor = 0;

        foreach ($fast['base64_entries'] as $entry) {
            if ($entry['expr_start'] < $cursor || $entry['expr_length'] <= 0) {
                return null;
            }

            $decoded = base64_decode($entry['encoded_value'], true);
            $value = $decoded !== false ? $decoded : '';
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
     * @param array{
     *   table: string,
     *   value_entries: list<array{start: int, length: int, kind: string, column: string, quote_start?: int, quote_length?: int, encoded_value?: string}>
     * } $fast
     */
    private static function remember_shape(string $sql, array $fast): void
    {
        if (empty($fast['value_entries'])) {
            return;
        }

        $chunks = [];
        $values = [];
        $base64_indexes = [];
        $cursor = 0;

        foreach ($fast['value_entries'] as $index => $entry) {
            $start = $entry['start'];
            $end = $start + $entry['length'];
            $chunks[] = substr($sql, $cursor, $start - $cursor);

            $shape_entry = [
                'kind' => $entry['kind'],
                'column' => $entry['column'],
            ];

            if ($entry['kind'] === 'base64') {
                $quote_start = $entry['quote_start'];
                $quote_end = $quote_start + $entry['quote_length'];
                $payload_start = $quote_start + 1;
                $payload_end = $quote_end - 1;

                $shape_entry['prefix'] = substr($sql, $start, $payload_start - $start);
                $shape_entry['suffix'] = substr($sql, $payload_end, $end - $payload_end);
                $base64_indexes[] = $index;
            } elseif ($entry['kind'] !== 'number') {
                $shape_entry['literal'] = substr($sql, $start, $entry['length']);
            }

            $values[] = $shape_entry;
            $cursor = $end;
        }

        $chunks[] = substr($sql, $cursor);

        self::$shape_cache[] = [
            'table' => $fast['table'],
            'chunks' => $chunks,
            'values' => $values,
            'base64_indexes' => $base64_indexes,
        ];

        if (count(self::$shape_cache) > self::SHAPE_CACHE_LIMIT) {
            array_shift(self::$shape_cache);
        }
    }

    /**
     * @param callable|null $rewrite_value
     * @return array{sql: string, params: list<string>}|null
     */
    private static function build_from_cached_shape(string $sql, ?callable $rewrite_value): ?array
    {
        $count = count(self::$shape_cache);
        for ($i = $count - 1; $i >= 0; $i--) {
            $matched = self::match_shape(self::$shape_cache[$i], $sql, $rewrite_value);
            if ($matched === null) {
                continue;
            }

            if ($i !== $count - 1) {
                $shape = self::$shape_cache[$i];
                array_splice(self::$shape_cache, $i, 1);
                self::$shape_cache[] = $shape;
            }

            return $matched;
        }

        return null;
    }

    /**
     * @param array{table: string, chunks: list<string>, values: list<array<string, mixed>>, base64_indexes: list<int>} $shape
     * @param callable|null $rewrite_value
     * @return array{sql: string, params: list<string>}|null
     */
    private static function match_shape(array $shape, string $sql, ?callable $rewrite_value): ?array
    {
        $pos = 0;
        $sql_len = strlen($sql);
        $value_ranges = [];
        $encoded_by_index = [];

        $first_chunk = $shape['chunks'][0];
        if (!self::consume_literal($sql, $sql_len, $pos, $first_chunk)) {
            return null;
        }

        foreach ($shape['values'] as $index => $entry) {
            $value_start = $pos;
            if ($entry['kind'] === 'base64') {
                $prefix = $entry['prefix'];
                if (!self::consume_literal($sql, $sql_len, $pos, $prefix)) {
                    return null;
                }
                $payload_start = $pos;
                $quote = strpos($sql, "'", $payload_start);
                if ($quote === false) {
                    return null;
                }
                $encoded = substr($sql, $payload_start, $quote - $payload_start);
                $pos = $quote;
                if (!self::consume_literal($sql, $sql_len, $pos, $entry['suffix'])) {
                    return null;
                }
                $encoded_by_index[$index] = $encoded;
            } elseif ($entry['kind'] === 'number') {
                if (!self::consume_number($sql, $sql_len, $pos)) {
                    return null;
                }
            } else {
                if (!self::consume_literal($sql, $sql_len, $pos, $entry['literal'])) {
                    return null;
                }
            }

            $value_ranges[$index] = [$value_start, $pos];
            if (!self::consume_literal($sql, $sql_len, $pos, $shape['chunks'][$index + 1])) {
                return null;
            }
        }

        if ($pos !== $sql_len) {
            return null;
        }

        $parts = [];
        $params = [];
        $cursor = 0;

        foreach ($shape['base64_indexes'] as $index) {
            [$value_start, $value_end] = $value_ranges[$index];
            if ($value_start < $cursor || $value_end <= $value_start) {
                return null;
            }

            $decoded = base64_decode($encoded_by_index[$index], true);
            if ($decoded === false) {
                return null;
            }
            $value = $decoded;
            if ($rewrite_value !== null) {
                $value = $rewrite_value($value, $shape['table'], $shape['values'][$index]['column']);
            }

            $parts[] = substr($sql, $cursor, $value_start - $cursor);
            $parts[] = '?';
            $params[] = $value;
            $cursor = $value_end;
        }

        $parts[] = substr($sql, $cursor);

        return [
            'sql' => implode('', $parts),
            'params' => $params,
        ];
    }

    private static function consume_literal(string $sql, int $sql_len, int &$pos, string $literal): bool
    {
        $length = strlen($literal);
        if ($pos + $length > $sql_len || substr_compare($sql, $literal, $pos, $length) !== 0) {
            return false;
        }

        $pos += $length;
        return true;
    }

    private static function consume_number(string $sql, int $sql_len, int &$pos): bool
    {
        $start = $pos;
        if ($pos < $sql_len && ($sql[$pos] === '+' || $sql[$pos] === '-')) {
            $pos++;
        }
        $digit_start = $pos;
        while ($pos < $sql_len && $sql[$pos] >= '0' && $sql[$pos] <= '9') {
            $pos++;
        }
        $digits_before_decimal = $pos > $digit_start;
        if ($pos < $sql_len && $sql[$pos] === '.') {
            $pos++;
            $fraction_start = $pos;
            while ($pos < $sql_len && $sql[$pos] >= '0' && $sql[$pos] <= '9') {
                $pos++;
            }
            if (!$digits_before_decimal && $pos === $fraction_start) {
                $pos = $start;
                return false;
            }
        }
        if (!$digits_before_decimal && $pos === $digit_start) {
            $pos = $start;
            return false;
        }
        if ($pos < $sql_len && ($sql[$pos] === 'e' || $sql[$pos] === 'E')) {
            $exponent_start = $pos;
            $pos++;
            if ($pos < $sql_len && ($sql[$pos] === '+' || $sql[$pos] === '-')) {
                $pos++;
            }
            $exponent_digits_start = $pos;
            while ($pos < $sql_len && $sql[$pos] >= '0' && $sql[$pos] <= '9') {
                $pos++;
            }
            if ($pos === $exponent_digits_start) {
                $pos = $exponent_start;
            }
        }

        return true;
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
}
