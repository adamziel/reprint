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
