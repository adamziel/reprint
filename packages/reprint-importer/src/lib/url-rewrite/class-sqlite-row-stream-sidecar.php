<?php

/**
 * Builds and consumes the experimental SQLite row-stream sidecar.
 *
 * The sidecar is JSONL, one record per SQL statement. Unsupported or ambiguous
 * statements are represented as byte ranges into db.sql so db-apply can fall
 * back to the existing SQL execution path without reparsing structured rows.
 *
 * Supported producer-shaped INSERT records carry table/column names and typed
 * row values:
 *
 *   null          SQL NULL
 *   empty_string  SQL ''
 *   numeric       raw numeric SQL literal, bound as a string and CAST by SQLite
 *   base64        original FROM_BASE64 payload, decoded only when applying
 */
class SQLiteRowStreamSidecar
{
    public const VERSION = 1;

    private const TEMPLATE_CACHE_MAX = 256;

    /** @var array<string, string> */
    private static array $template_sql_cache = [];

    /** @var string[] */
    private static array $template_sql_cache_order = [];

    /**
     * @return array{v: int, kind: string, sql_bytes: int, format: string}
     */
    public static function meta_record(int $sql_bytes): array
    {
        return [
            'v' => self::VERSION,
            'kind' => 'meta',
            'format' => 'sqlite-row-stream-sidecar',
            'sql_bytes' => $sql_bytes,
        ];
    }

    /**
     * @return array{v: int, kind: string, sql_offset: int, sql_length: int}
     */
    public static function sql_record(int $sql_offset, string $sql): array
    {
        return self::sql_range_record($sql_offset, strlen($sql));
    }

    /**
     * @return array{v: int, kind: string, sql_offset: int, sql_length: int}
     */
    public static function sql_range_record(int $sql_offset, int $sql_length): array
    {
        return [
            'v' => self::VERSION,
            'kind' => 'sql',
            'sql_offset' => $sql_offset,
            'sql_length' => $sql_length,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function record_from_sql(string $sql, int $sql_offset): array
    {
        $fallback = self::sql_record($sql_offset, $sql);

        $fast = FastInsertScanner::scan($sql, false);
        if ($fast === null || empty($fast['value_entries'])) {
            return $fallback;
        }

        $column_count = count($fast['columns']);
        $value_count = count($fast['value_entries']);
        if ($column_count === 0 || $value_count === 0 || $value_count % $column_count !== 0) {
            return $fallback;
        }

        $rows = [];
        $row = [];
        foreach ($fast['value_entries'] as $entry) {
            $value = self::value_entry_to_record($entry);
            if ($value === null) {
                return $fallback;
            }

            $row[] = $value;
            if (count($row) === $column_count) {
                $rows[] = $row;
                $row = [];
            }
        }

        return [
            'v' => self::VERSION,
            'kind' => 'insert',
            'sql_offset' => $sql_offset,
            'sql_length' => strlen($sql),
            'table' => $fast['table'],
            'columns' => $fast['columns'],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function encode_record(array $record): ?string
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }
        return $json . "\n";
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function is_insert_record(array $record): bool
    {
        return
            ($record['v'] ?? null) === self::VERSION &&
            ($record['kind'] ?? null) === 'insert';
    }

    /**
     * @param array<string, mixed> $record
     * @param callable|null $rewrite_value Optional callback:
     *        fn(string $value, string $table, ?string $column): string
     * @return array{sql: string, params: list<mixed>, param_types: list<int>}|null
     */
    public static function record_to_prepared_insert(array $record, ?callable $rewrite_value = null): ?array
    {
        if (!self::is_insert_record($record)) {
            return null;
        }

        $table = $record['table'] ?? null;
        $columns = $record['columns'] ?? null;
        $rows = $record['rows'] ?? null;
        if (!is_string($table) || !is_array($columns) || !is_array($rows) || empty($columns) || empty($rows)) {
            return null;
        }

        foreach ($columns as $column) {
            if (!is_string($column)) {
                return null;
            }
        }

        $column_count = count($columns);
        $shape_key_parts = [
            strlen($table) . ':' . $table,
            (string) $column_count,
        ];
        foreach ($columns as $column) {
            $shape_key_parts[] = strlen($column) . ':' . $column;
        }

        $row_placeholders = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== $column_count) {
                return null;
            }

            $placeholders = [];
            foreach ($row as $value_record) {
                if (!is_array($value_record)) {
                    return null;
                }
                $placeholder = self::placeholder_for_value_record($value_record);
                if ($placeholder === null) {
                    return null;
                }
                $shape_key_parts[] = $placeholder;
                $placeholders[] = $placeholder;
            }
            $row_placeholders[] = $placeholders;
        }

        $shape_key = implode('|', $shape_key_parts);
        $template_sql = self::$template_sql_cache[$shape_key] ?? null;
        if ($template_sql === null) {
            $quoted_columns = [];
            foreach ($columns as $column) {
                $quoted_columns[] = self::quote_identifier($column);
            }

            $sql_rows = [];
            foreach ($row_placeholders as $placeholders) {
                $sql_rows[] = '(' . implode(', ', $placeholders) . ')';
            }

            $template_sql = 'INSERT INTO '
                . self::quote_identifier($table)
                . ' (' . implode(', ', $quoted_columns) . ') VALUES'
                . implode(',', $sql_rows)
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
        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $value_record) {
                $param = self::param_for_value_record(
                    $value_record,
                    $table,
                    $columns[$index] ?? null,
                    $rewrite_value
                );
                if ($param === null) {
                    return null;
                }
                $params[] = $param['value'];
                $param_types[] = $param['type'];
            }
        }

        return [
            'sql' => $template_sql,
            'params' => $params,
            'param_types' => $param_types,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{t: string, v?: string}|null
     */
    private static function value_entry_to_record(array $entry): ?array
    {
        switch ($entry['kind'] ?? null) {
            case 'null':
                return ['t' => 'null'];

            case 'empty_string':
                return ['t' => 'empty_string'];

            case 'numeric':
                if (!isset($entry['raw']) || !is_string($entry['raw'])) {
                    return null;
                }
                return ['t' => 'numeric', 'v' => $entry['raw']];

            case 'base64':
                if (!isset($entry['encoded_value']) || !is_string($entry['encoded_value'])) {
                    return null;
                }
                if (base64_decode($entry['encoded_value'], true) === false) {
                    return null;
                }
                return ['t' => 'base64', 'v' => $entry['encoded_value']];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value_record
     */
    private static function placeholder_for_value_record(array $value_record): ?string
    {
        switch ($value_record['t'] ?? null) {
            case 'null':
            case 'empty_string':
            case 'base64':
                return '?';

            case 'numeric':
                $raw = $value_record['v'] ?? null;
                if (!is_string($raw)) {
                    return null;
                }
                return strpbrk($raw, '.eE') === false
                    ? 'CAST(? AS NUMERIC)'
                    : 'CAST(? AS REAL)';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value_record
     * @return array{value: mixed, type: int}|null
     */
    private static function param_for_value_record(
        array $value_record,
        string $table,
        ?string $column,
        ?callable $rewrite_value
    ): ?array {
        switch ($value_record['t'] ?? null) {
            case 'null':
                return ['value' => null, 'type' => PDO::PARAM_NULL];

            case 'empty_string':
                return ['value' => '', 'type' => PDO::PARAM_STR];

            case 'numeric':
                $raw = $value_record['v'] ?? null;
                if (!is_string($raw)) {
                    return null;
                }
                return ['value' => $raw, 'type' => PDO::PARAM_STR];

            case 'base64':
                $encoded = $value_record['v'] ?? null;
                if (!is_string($encoded)) {
                    return null;
                }
                $value = base64_decode($encoded, true);
                if ($value === false) {
                    return null;
                }
                if ($rewrite_value !== null) {
                    $value = $rewrite_value($value, $table, $column);
                }
                return ['value' => $value, 'type' => PDO::PARAM_STR];
        }

        return null;
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
