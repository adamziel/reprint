<?php

/**
 * Buffers adjacent PR #244 structured SQLite INSERTs and executes them as
 * larger prepared multi-row INSERTs without letting decoded values become SQL.
 */
class SQLitePreparedInsertCoalescer
{
    private const DEFAULT_MAX_PARAMETERS = 999;
    private const CACHE_LIMIT = 64;

    private PDO $pdo;
    private int $max_parameters;

    /** @var array<string, PDOStatement> */
    private array $statement_cache = [];

    /**
     * @var array{
     *     table: string,
     *     columns: list<string>,
     *     params: list<mixed>,
     *     param_types: list<int>,
     *     row_count: int,
     *     statement_count: int,
     *     last_statement_end_offset: int
     * }|null
     */
    private ?array $buffer = null;

    public function __construct(PDO $pdo, ?int $max_parameters = null)
    {
        $this->pdo = $pdo;
        $this->max_parameters = max(1, $max_parameters ?? self::detect_max_parameters($pdo));
    }

    public static function detect_max_parameters(PDO $pdo): int
    {
        $limit = null;

        try {
            $rows = $pdo->query('PRAGMA compile_options');
            if ($rows !== false) {
                while (($option = $rows->fetchColumn()) !== false) {
                    if (
                        is_string($option)
                        && preg_match('/\AMAX_VARIABLE_NUMBER=(\d+)\z/', $option, $m)
                    ) {
                        $limit = (int) $m[1];
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $limit = null;
        }

        if ($limit === null) {
            try {
                $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
                if (is_string($version) && version_compare($version, '3.32.0', '>=')) {
                    $limit = 32766;
                }
            } catch (Throwable $e) {
                $limit = null;
            }
        }

        return max(1, min($limit ?? self::DEFAULT_MAX_PARAMETERS, 32766));
    }

    public function get_max_parameters(): int
    {
        return $this->max_parameters;
    }

    public function pending_statement_count(): int
    {
        return (int) ($this->buffer['statement_count'] ?? 0);
    }

    /**
     * @param array{
     *     table: string,
     *     columns: list<string>,
     *     params: list<mixed>,
     *     param_types: list<int>,
     *     row_count: int
     * } $structured_insert
     * @return array{statements_executed: int, bytes_read: ?int}
     */
    public function append(array $structured_insert, int $statement_end_offset, string &$executed_query): array
    {
        $this->assert_valid_insert($structured_insert);

        $result = self::empty_result();
        if (
            $this->buffer !== null
            && (
                !$this->is_compatible($structured_insert)
                || $this->would_exceed_parameter_limit($structured_insert)
            )
        ) {
            $result = self::merge_results($result, $this->flush($executed_query));
        }

        $this->append_to_buffer($structured_insert, $statement_end_offset);

        if ($this->buffer !== null && $this->buffer_parameter_count() >= $this->max_parameters) {
            $result = self::merge_results($result, $this->flush($executed_query));
        }

        return $result;
    }

    /**
     * @return array{statements_executed: int, bytes_read: ?int}
     */
    public function flush(string &$executed_query): array
    {
        if ($this->buffer === null) {
            return self::empty_result();
        }

        $buffer = $this->buffer;
        $this->buffer = null;

        $this->execute_buffer($buffer, $executed_query);

        return [
            'statements_executed' => $buffer['statement_count'],
            'bytes_read' => $buffer['last_statement_end_offset'],
        ];
    }

    /**
     * @param array{columns: list<string>, params: list<mixed>, param_types: list<int>, row_count: int} $structured_insert
     */
    private function assert_valid_insert(array $structured_insert): void
    {
        $column_count = count($structured_insert['columns']);
        $row_count = (int) $structured_insert['row_count'];
        $param_count = count($structured_insert['params']);

        if ($column_count <= 0 || $row_count <= 0) {
            throw new InvalidArgumentException('Structured SQLite INSERT must have columns and rows.');
        }
        if ($param_count !== $column_count * $row_count) {
            throw new InvalidArgumentException('Structured SQLite INSERT parameter count does not match its shape.');
        }
        if (count($structured_insert['param_types']) !== $param_count) {
            throw new InvalidArgumentException('Structured SQLite INSERT parameter type count does not match its parameters.');
        }
    }

    /**
     * @param array{table: string, columns: list<string>} $structured_insert
     */
    private function is_compatible(array $structured_insert): bool
    {
        return $this->buffer !== null
            && $this->buffer['table'] === $structured_insert['table']
            && $this->buffer['columns'] === $structured_insert['columns'];
    }

    /**
     * @param array{params: list<mixed>} $structured_insert
     */
    private function would_exceed_parameter_limit(array $structured_insert): bool
    {
        return $this->buffer !== null
            && $this->buffer_parameter_count() > 0
            && $this->buffer_parameter_count() + count($structured_insert['params']) > $this->max_parameters;
    }

    /**
     * @param array{
     *     table: string,
     *     columns: list<string>,
     *     params: list<mixed>,
     *     param_types: list<int>,
     *     row_count: int
     * } $structured_insert
     */
    private function append_to_buffer(array $structured_insert, int $statement_end_offset): void
    {
        if ($this->buffer === null) {
            $this->buffer = [
                'table' => $structured_insert['table'],
                'columns' => $structured_insert['columns'],
                'params' => $structured_insert['params'],
                'param_types' => $structured_insert['param_types'],
                'row_count' => (int) $structured_insert['row_count'],
                'statement_count' => 1,
                'last_statement_end_offset' => $statement_end_offset,
            ];
            return;
        }

        array_push($this->buffer['params'], ...$structured_insert['params']);
        array_push($this->buffer['param_types'], ...$structured_insert['param_types']);
        $this->buffer['row_count'] += (int) $structured_insert['row_count'];
        $this->buffer['statement_count']++;
        $this->buffer['last_statement_end_offset'] = $statement_end_offset;
    }

    private function buffer_parameter_count(): int
    {
        return $this->buffer === null ? 0 : count($this->buffer['params']);
    }

    /**
     * @param array{
     *     table: string,
     *     columns: list<string>,
     *     params: list<mixed>,
     *     param_types: list<int>,
     *     row_count: int
     * } $buffer
     */
    private function execute_buffer(array $buffer, string &$executed_query): void
    {
        $column_count = count($buffer['columns']);
        $rows_per_statement = max(1, intdiv($this->max_parameters, $column_count));
        $rows_remaining = $buffer['row_count'];
        $row_offset = 0;

        $this->pdo->exec('SAVEPOINT reprint_insert_coalesce');
        try {
            while ($rows_remaining > 0) {
                $rows_in_chunk = min($rows_remaining, $rows_per_statement);
                $this->execute_chunk($buffer, $row_offset, $rows_in_chunk, $executed_query);
                $row_offset += $rows_in_chunk;
                $rows_remaining -= $rows_in_chunk;
            }
            $this->pdo->exec('RELEASE SAVEPOINT reprint_insert_coalesce');
        } catch (Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT reprint_insert_coalesce');
            } catch (Throwable $rollback_error) {
            }
            try {
                $this->pdo->exec('RELEASE SAVEPOINT reprint_insert_coalesce');
            } catch (Throwable $release_error) {
            }
            throw $e;
        }
    }

    /**
     * @param array{
     *     table: string,
     *     columns: list<string>,
     *     params: list<mixed>,
     *     param_types: list<int>
     * } $buffer
     */
    private function execute_chunk(array $buffer, int $row_offset, int $row_count, string &$executed_query): void
    {
        $column_count = count($buffer['columns']);
        $param_offset = $row_offset * $column_count;
        $param_count = $row_count * $column_count;
        $sql = $this->build_sql($buffer['table'], $buffer['columns'], $row_count);
        $executed_query = $sql;

        $statement = $this->statement_cache[$sql] ?? null;
        if ($statement === null) {
            $statement = $this->pdo->prepare($sql);
            if ($statement === false) {
                throw new PDOException('Failed to prepare coalesced SQLite INSERT statement.');
            }
            if (count($this->statement_cache) < self::CACHE_LIMIT) {
                $this->statement_cache[$sql] = $statement;
            }
        }

        for ($i = 0; $i < $param_count; $i++) {
            $statement->bindValue(
                $i + 1,
                $buffer['params'][$param_offset + $i],
                $buffer['param_types'][$param_offset + $i] ?? PDO::PARAM_STR
            );
        }

        if ($statement->execute() === false) {
            throw new PDOException('Failed to execute coalesced SQLite INSERT statement.');
        }
        $statement->closeCursor();
    }

    /**
     * @param list<string> $columns
     */
    private function build_sql(string $table, array $columns, int $row_count): string
    {
        $quoted_columns = array_map([self::class, 'quote_identifier'], $columns);
        $tuple = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';

        return sprintf(
            'INSERT INTO %s (%s) VALUES %s;',
            self::quote_identifier($table),
            implode(',', $quoted_columns),
            implode(',', array_fill(0, $row_count, $tuple))
        );
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @return array{statements_executed: int, bytes_read: ?int}
     */
    private static function empty_result(): array
    {
        return [
            'statements_executed' => 0,
            'bytes_read' => null,
        ];
    }

    /**
     * @param array{statements_executed: int, bytes_read: ?int} $left
     * @param array{statements_executed: int, bytes_read: ?int} $right
     * @return array{statements_executed: int, bytes_read: ?int}
     */
    private static function merge_results(array $left, array $right): array
    {
        return [
            'statements_executed' => $left['statements_executed'] + $right['statements_executed'],
            'bytes_read' => $right['bytes_read'] ?? $left['bytes_read'],
        ];
    }
}
