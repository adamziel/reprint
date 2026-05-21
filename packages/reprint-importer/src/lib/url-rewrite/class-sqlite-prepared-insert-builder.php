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
        if (strpos($sql, 'FROM_BASE64(') === false) {
            return null;
        }

        $fast = FastInsertScanner::scan($sql, false);
        if ($fast === null || empty($fast['base64_entries']) || empty($fast['value_entries'])) {
            return null;
        }

        $column_count = count($fast['columns']);
        $value_count = count($fast['value_entries']);
        if ($column_count === 0 || $value_count === 0 || $value_count % $column_count !== 0) {
            return null;
        }

        // Describe the prepared SQL template, not the concrete bound values.
        // The cache key must change when the generated SQL text changes, but
        // must stay stable across batches that only differ by decoded payloads
        // or numeric literal values.
        $shape_key_parts = [
            // Table names are emitted into the SQL template, so they are part
            // of the shape. Length-prefixing prevents separator collisions.
            strlen($fast['table']) . ':' . $fast['table'],
            // The number of columns determines tuple width and avoids treating
            // a prefix of one column list as the same shape as a shorter list.
            (string) $column_count,
        ];
        foreach ($fast['columns'] as $column) {
            // Column order is part of INSERT semantics and of the generated SQL
            // text, so each quoted identifier contributes to the template key.
            $shape_key_parts[] = strlen($column) . ':' . $column;
        }
        foreach ($fast['value_entries'] as $entry) {
            if ($entry['kind'] === 'numeric') {
                // Numeric values are bound, not embedded. Only SQLite's target
                // storage class affects the template: dotted/exponent literals
                // need REAL while integer-looking literals use NUMERIC.
                $shape_key_parts[] = strpbrk($entry['raw'], '.eE') === false
                    ? 'CAST(? AS NUMERIC)'
                    : 'CAST(? AS REAL)';
            } else {
                // NULL, empty strings, and decoded base64 payloads all compile
                // to the same bare placeholder; their values are supplied later.
                $shape_key_parts[] = '?';
            }
        }

        $shape_key = implode('|', $shape_key_parts);
        $template_sql = self::$template_sql_cache[$shape_key] ?? null;
        if ($template_sql === null) {
            // Cache misses are the only time we build SQL text. Payload bytes
            // and numeric literals still never become SQL; this only emits
            // identifiers and placeholders for the producer-recognised shape.
            $quoted_columns = [];
            foreach ($fast['columns'] as $column) {
                $quoted_columns[] = self::quote_identifier($column);
            }

            $rows = [];
            for ($offset = 0; $offset < $value_count; $offset += $column_count) {
                $placeholders = [];
                for ($i = 0; $i < $column_count; $i++) {
                    $entry = $fast['value_entries'][$offset + $i];
                    if ($entry['kind'] === 'numeric') {
                        // Dotted/exponent literals need REAL to match SQLite's literal typing.
                        $placeholders[] = strpbrk($entry['raw'], '.eE') === false
                            ? 'CAST(? AS NUMERIC)'
                            : 'CAST(? AS REAL)';
                    } else {
                        $placeholders[] = '?';
                    }
                }
                $rows[] = '(' . implode(', ', $placeholders) . ')';
            }

            // Preserve one reusable SQL string per table/column/value-shape so
            // PDO and SQLite can reuse the compiled statement across dump rows.
            $template_sql = 'INSERT INTO '
                . self::quote_identifier($fast['table'])
                . ' (' . implode(', ', $quoted_columns) . ') VALUES'
                . implode(',', $rows)
                . ';';

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

        foreach ($fast['value_entries'] as $entry) {
            switch ($entry['kind']) {
                case 'null':
                    $params[] = null;
                    $param_types[] = PDO::PARAM_NULL;
                    break;

                case 'empty_string':
                    $params[] = '';
                    $param_types[] = PDO::PARAM_STR;
                    break;

                case 'numeric':
                    $params[] = $entry['raw'];
                    $param_types[] = PDO::PARAM_STR;
                    break;

                case 'base64':
                    $decoded = base64_decode($entry['encoded_value'], true);
                    if ($decoded === false) {
                        return null;
                    }
                    $value = $decoded;
                    if ($rewrite_value !== null) {
                        $value = $rewrite_value($value, $fast['table'], $entry['column']);
                    }
                    $params[] = $value;
                    $param_types[] = PDO::PARAM_STR;
                    break;

                default:
                    return null;
            }
        }

        return [
            'sql' => $template_sql,
            'params' => $params,
            'param_types' => $param_types,
        ];
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

}
