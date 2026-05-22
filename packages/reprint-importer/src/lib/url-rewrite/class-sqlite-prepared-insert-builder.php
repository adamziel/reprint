<?php

/**
 * Builds SQLite prepared INSERT statements for MySQLDumpProducer-shaped SQL.
 *
 * This is deliberately narrower than SqlStatementRewriter: it only accepts
 * INSERT statements that FastInsertScanner fully recognises and that contain
 * at least one FROM_BASE64(...) expression. Every tuple value is replaced with
 * a positional placeholder (integer-looking numeric literals use
 * CAST(? AS NUMERIC), dotted/exponent literals use CAST(? AS REAL)), so repeated
 * dump batches for the same table/column/row-count shape compile to the same
 * SQLite SQL template. Values that do not match the producer shape return null
 * so db-apply can fall back to the normal MySQL-on-SQLite execution path.
 *
 * The important property is that decoded payload bytes never become SQL text.
 * That avoids quoting, escaping, UTF-8, and "garbage codepoint" questions: the
 * SQL parser sees only `?`, and SQLite receives the exact PHP string through
 * PDO binding. Numeric literals stay numeric through SQLite's NUMERIC cast,
 * including large values that cannot be represented as PHP integers.
 */
class SQLitePreparedInsertBuilder
{
    private const TEMPLATE_CACHE_MAX = 256;

    /** @var array<string, string> */
    private static array $template_sql_cache = [];

    /** @var string[] */
    private static array $template_sql_cache_order = [];

    /**
     * @param callable|null $rewrite_value Optional callback:
     *        fn(string $value, string $table, ?string $column): string
     * @return array{sql: string, params: list<mixed>, param_types: list<int>}|null
     */
    public static function build(string $sql, ?callable $rewrite_value = null): ?array
    {
        if (stripos($sql, 'FROM_BASE64(') === false) {
            return null;
        }

        return self::build_with_fast_insert_scanner($sql, $rewrite_value);
    }

    /**
     * @param callable|null $rewrite_value Optional callback:
     *        fn(string $value, string $table, ?string $column): string
     * @return array{sql: string, params: list<mixed>, param_types: list<int>}|null
     */
    private static function build_with_fast_insert_scanner(string $sql, ?callable $rewrite_value): ?array
    {
        $fast = FastInsertScanner::scan($sql, false, false, true);
        if ($fast === null || empty($fast['has_base64'])) {
            return null;
        }

        $value_count = $fast['value_count'] ?? 0;
        $value_codes = $fast['value_codes'] ?? '';
        $value_shape_codes = $fast['value_shape_codes'] ?? '';
        $value_payloads = $fast['value_payloads'] ?? [];
        if ($value_codes === '') {
            return null;
        }

        $column_count = count($fast['columns']);
        if (
            $column_count === 0
            || $value_count === 0
            || $value_count % $column_count !== 0
            || strlen($value_codes) !== $value_count
            || strlen($value_shape_codes) !== $value_count
        ) {
            return null;
        }

        $shape_key = self::compact_shape_key($fast['table'], $fast['columns'], $value_shape_codes);
        $template_sql = self::$template_sql_cache[$shape_key] ?? null;
        if ($template_sql === null) {
            // Cache misses are the only time we build SQL text. Payload bytes
            // and numeric literals still never become SQL; this only emits
            // identifiers and placeholders for the producer-recognised shape.
            // Preserve one reusable SQL string per table/column/value-shape so
            // PDO and SQLite can reuse the compiled statement across dump rows.
            $template_sql = self::template_sql_from_shape($fast['table'], $fast['columns'], $value_shape_codes);

            self::$template_sql_cache[$shape_key] = $template_sql;
            self::$template_sql_cache_order[] = $shape_key;
            if (count(self::$template_sql_cache_order) > self::TEMPLATE_CACHE_MAX) {
                // Bound cache growth: large imports can touch many plugin
                // tables, but old shapes are cheap to rebuild if seen again.
                $oldest_key = array_shift(self::$template_sql_cache_order);
                if (is_string($oldest_key)) {
                    unset(self::$template_sql_cache[$oldest_key]);
                }
            }
        }

        // Build the bound values after the template is selected. This keeps
        // untrusted decoded bytes out of SQL text while still allowing URL
        // rewriting to use table/column context for structured data.
        $params = [];
        $param_types = [];
        $payload_index = 0;

        for ($i = 0; $i < $value_count; $i++) {
            switch ($value_codes[$i]) {
                case 'n':
                    $params[] = null;
                    $param_types[] = PDO::PARAM_NULL;
                    break;

                case 'e':
                    $params[] = '';
                    $param_types[] = PDO::PARAM_STR;
                    break;

                case 'i':
                case 'r':
                    if (!array_key_exists($payload_index, $value_payloads)) {
                        return null;
                    }
                    $params[] = $value_payloads[$payload_index];
                    $payload_index++;
                    $param_types[] = PDO::PARAM_STR;
                    break;

                case 'b':
                    if (!array_key_exists($payload_index, $value_payloads)) {
                        return null;
                    }
                    $decoded = base64_decode($value_payloads[$payload_index], true);
                    $payload_index++;
                    if ($decoded === false) {
                        return null;
                    }
                    $value = $decoded;
                    if ($rewrite_value !== null) {
                        $value = $rewrite_value($value, $fast['table'], $fast['columns'][$i % $column_count]);
                    }
                    $params[] = $value;
                    $param_types[] = PDO::PARAM_STR;
                    break;

                default:
                    return null;
            }
        }

        if ($payload_index !== count($value_payloads)) {
            return null;
        }

        return [
            'sql' => $template_sql,
            'params' => $params,
            'param_types' => $param_types,
        ];
    }

    /**
     * @param list<string> $columns
     */
    private static function compact_shape_key(string $table, array $columns, string $shape_value_codes): string
    {
        // Describe the prepared SQL template, not the concrete bound values.
        // Length-prefixing identifiers prevents separator collisions.
        $key = 'stream1|' . strlen($table) . ':' . $table . '|' . count($columns);
        foreach ($columns as $column) {
            $key .= '|' . strlen($column) . ':' . $column;
        }
        return $key . '|' . $shape_value_codes;
    }

    /**
     * @param list<string> $columns
     */
    private static function template_sql_from_shape(string $table, array $columns, string $shape_value_codes): string
    {
        $column_count = count($columns);
        $value_count = strlen($shape_value_codes);

        $template_sql = 'INSERT INTO ' . self::quote_identifier($table) . ' (';
        foreach ($columns as $index => $column) {
            if ($index > 0) {
                $template_sql .= ', ';
            }
            $template_sql .= self::quote_identifier($column);
        }
        $template_sql .= ') VALUES';

        for ($offset = 0; $offset < $value_count; $offset += $column_count) {
            if ($offset > 0) {
                $template_sql .= ',';
            }
            $template_sql .= '(';
            for ($i = 0; $i < $column_count; $i++) {
                if ($i > 0) {
                    $template_sql .= ', ';
                }
                $template_sql .= self::placeholder_for_shape_code($shape_value_codes[$offset + $i]);
            }
            $template_sql .= ')';
        }

        return $template_sql . ';';
    }

    private static function placeholder_for_shape_code(string $code): string
    {
        if ($code === 'i') {
            return 'CAST(? AS NUMERIC)';
        }
        if ($code === 'r') {
            return 'CAST(? AS REAL)';
        }
        return '?';
    }

    private static function quote_identifier(string $identifier): string
    {
        if (strpos($identifier, '`') === false) {
            return '`' . $identifier . '`';
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

}
