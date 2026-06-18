<?php

namespace Reprint\Importer\Sql;

use PDO;
use PDOException;
use PDOStatement;
use Reprint\Importer\UrlRewrite\SQLitePreparedInsertBuilder;
use Reprint\Importer\UrlRewrite\SqlStatementRewriter;

final class DbApplyQueryExecutor
{
    private const DEFAULT_SQLITE_PREPARED_INSERT_CACHE_MAX = 128;

    private PDO $pdo;
    private ?SqlStatementRewriter $stmt_rewriter;
    private ?PDO $sqlite_prepared_pdo;
    private int $sqlite_prepared_insert_cache_max;

    /** @var array<string, PDOStatement> */
    private array $sqlite_prepared_statement_cache = [];

    /** @var string[] */
    private array $sqlite_prepared_statement_cache_order = [];

    public function __construct(
        PDO $pdo,
        ?SqlStatementRewriter $stmt_rewriter = null,
        ?PDO $sqlite_prepared_pdo = null,
        int $sqlite_prepared_insert_cache_max = self::DEFAULT_SQLITE_PREPARED_INSERT_CACHE_MAX
    ) {
        $this->pdo = $pdo;
        $this->stmt_rewriter = $stmt_rewriter;
        $this->sqlite_prepared_pdo = $sqlite_prepared_pdo;
        $this->sqlite_prepared_insert_cache_max = max(1, $sqlite_prepared_insert_cache_max);
    }

    /**
     * Execute a single db-apply statement and return the SQL form actually used.
     */
    public function execute(string $query): string
    {
        if ($this->sqlite_prepared_pdo !== null) {
            $prepared_insert = $this->stmt_rewriter !== null
                ? $this->stmt_rewriter->build_sqlite_prepared_insert($query)
                : SQLitePreparedInsertBuilder::build($query);

            if ($prepared_insert !== null) {
                return $this->execute_prepared_insert($prepared_insert);
            }
        }

        $executed_query = $this->stmt_rewriter !== null
            ? $this->stmt_rewriter->rewrite($query)
            : $query;

        $this->pdo->exec($executed_query);
        return $executed_query;
    }

    /**
     * @param array{sql: string, params: list<mixed>, param_types: list<int>} $prepared_insert
     */
    private function execute_prepared_insert(array $prepared_insert): string
    {
        if ($this->sqlite_prepared_pdo === null) {
            throw new PDOException('SQLite prepared insert execution requires a SQLite PDO.');
        }

        $statement = $this->sqlite_prepared_statement_cache[$prepared_insert['sql']] ?? null;
        if (!$statement instanceof PDOStatement) {
            $statement = $this->sqlite_prepared_pdo->prepare($prepared_insert['sql']);
            if ($statement === false) {
                throw new PDOException('Failed to prepare SQLite INSERT statement.');
            }

            $this->sqlite_prepared_statement_cache[$prepared_insert['sql']] = $statement;
            $this->sqlite_prepared_statement_cache_order[] = $prepared_insert['sql'];
            $this->evict_oldest_prepared_insert_if_needed();
        } else {
            $statement->closeCursor();
        }

        foreach ($prepared_insert['params'] as $index => $value) {
            $statement->bindValue(
                $index + 1,
                $value,
                $prepared_insert['param_types'][$index] ?? PDO::PARAM_STR
            );
        }

        if ($statement->execute() === false) {
            throw new PDOException('Failed to execute SQLite INSERT statement.');
        }

        return $prepared_insert['sql'];
    }

    private function evict_oldest_prepared_insert_if_needed(): void
    {
        if (
            count($this->sqlite_prepared_statement_cache_order) <=
            $this->sqlite_prepared_insert_cache_max
        ) {
            return;
        }

        $oldest_sql = array_shift($this->sqlite_prepared_statement_cache_order);
        if (is_string($oldest_sql)) {
            unset($this->sqlite_prepared_statement_cache[$oldest_sql]);
        }
    }
}
