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

        $shape_key_parts = [
            strlen($fast['table']) . ':' . $fast['table'],
            (string) $column_count,
        ];
        foreach ($fast['columns'] as $column) {
            $shape_key_parts[] = strlen($column) . ':' . $column;
        }
        foreach ($fast['value_entries'] as $entry) {
            if ($entry['kind'] === 'numeric') {
                $shape_key_parts[] = strpbrk($entry['raw'], '.eE') === false
                    ? 'CAST(? AS NUMERIC)'
                    : 'CAST(? AS REAL)';
            } else {
                $shape_key_parts[] = '?';
            }
        }

        $shape_key = implode('|', $shape_key_parts);
        $template_sql = self::$template_sql_cache[$shape_key] ?? null;
        if ($template_sql === null) {
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

            $template_sql = 'INSERT INTO '
                . self::quote_identifier($fast['table'])
                . ' (' . implode(', ', $quoted_columns) . ') VALUES'
                . implode(',', $rows)
                . ';';

            self::$template_sql_cache[$shape_key] = $template_sql;
            self::$template_sql_cache_order[] = $shape_key;
            if (count(self::$template_sql_cache_order) > self::TEMPLATE_CACHE_MAX) {
                $oldest_key = array_shift(self::$template_sql_cache_order);
                if (is_string($oldest_key)) {
                    unset(self::$template_sql_cache[$oldest_key]);
                }
            }
        }

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
