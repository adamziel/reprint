<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class RecordingSqlitePreparedStatement extends \PDOStatement
{
    /** @var array<int|string, array{value: mixed, type: int}> */
    public array $current_bindings = [];

    /** @var list<array<int|string, array{is_null: bool, length: ?int, hash: ?string, value: mixed, type: int}>> */
    public array $execute_snapshots = [];

    /** @var list<array{param: int|string, is_null: bool, length: ?int, hash: ?string, value: mixed, type: int}> */
    public array $bind_log = [];

    public int $close_cursor_calls = 0;

    protected function __construct()
    {
    }

    public static function create(): self
    {
        $reflection = new \ReflectionClass(self::class);
        /** @var self $statement */
        $statement = $reflection->newInstanceWithoutConstructor();
        return $statement;
    }

    #[\ReturnTypeWillChange]
    public function bindValue($param, $value, $type = \PDO::PARAM_STR)
    {
        $this->current_bindings[$param] = [
            'value' => $value,
            'type' => $type,
        ];
        $this->bind_log[] = $this->describe_binding($param, $value, $type);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        $snapshot = [];
        foreach ($this->current_bindings as $param => $binding) {
            $snapshot[$param] = $this->describe_binding($param, $binding['value'], $binding['type']);
        }
        $this->execute_snapshots[] = $snapshot;
        return true;
    }

    #[\ReturnTypeWillChange]
    public function closeCursor()
    {
        $this->close_cursor_calls++;
        return true;
    }

    /**
     * @return array{param: int|string, is_null: bool, length: ?int, hash: ?string, value: mixed, type: int}
     */
    private function describe_binding($param, $value, int $type): array
    {
        return [
            'param' => $param,
            'is_null' => $value === null,
            'length' => is_string($value) ? strlen($value) : null,
            'hash' => is_string($value) ? sha1($value) : null,
            'value' => is_string($value) && strlen($value) <= 128 ? $value : null,
            'type' => $type,
        ];
    }
}

class RecordingSqlitePdo extends \PDO
{
    /** @var list<string> */
    public array $prepared_sql = [];

    /** @var array<string, RecordingSqlitePreparedStatement> */
    public array $statements_by_sql = [];

    public function __construct()
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $this->prepared_sql[] = $query;
        $statement = RecordingSqlitePreparedStatement::create();
        $this->statements_by_sql[$query] = $statement;
        return $statement;
    }
}

class SqlitePreparedStatementBoundValuesTest extends TestCase
{
    public function testSqlitePreparedInsertClearsLargeBoundValuesAfterExecute(): void
    {
        $large_value = str_repeat('large-bound-value-', 128 * 1024);
        $query = $this->buildInsertQuery(1, 'large_payload', $large_value);

        $pdo = new RecordingSqlitePdo();
        $cache = [];
        $cache_order = [];
        $executed_query = '';

        $this->executePreparedPath($pdo, $query, $cache, $cache_order, $executed_query);

        $this->assertCount(1, $pdo->prepared_sql);
        $statement = $pdo->statements_by_sql[$pdo->prepared_sql[0]];
        $this->assertSame($pdo->prepared_sql[0], $executed_query);
        $this->assertCount(1, $statement->execute_snapshots);
        $this->assertSame(strlen($large_value), $statement->execute_snapshots[0][3]['length']);
        $this->assertSame(sha1($large_value), $statement->execute_snapshots[0][3]['hash']);

        $this->assertBoundValuesCleared($statement, 3);
    }

    public function testCachedSqlitePreparedInsertRebindsValuesAfterClearing(): void
    {
        $first_value = str_repeat('first-row-', 96 * 1024);
        $second_value = 'second row payload';
        $first_query = $this->buildInsertQuery(1, 'first_payload', $first_value);
        $second_query = $this->buildInsertQuery(2, 'second_payload', $second_value);

        $pdo = new RecordingSqlitePdo();
        $cache = [];
        $cache_order = [];
        $executed_query = '';

        $this->executePreparedPath($pdo, $first_query, $cache, $cache_order, $executed_query);
        $first_executed_query = $executed_query;
        $this->executePreparedPath($pdo, $second_query, $cache, $cache_order, $executed_query);

        $this->assertSame($first_executed_query, $executed_query);
        $this->assertCount(1, $pdo->prepared_sql, 'same INSERT shape should reuse one cached statement');
        $this->assertCount(1, $cache);

        $statement = $pdo->statements_by_sql[$pdo->prepared_sql[0]];
        $this->assertCount(2, $statement->execute_snapshots);
        $this->assertSame(strlen($first_value), $statement->execute_snapshots[0][3]['length']);
        $this->assertSame(sha1($first_value), $statement->execute_snapshots[0][3]['hash']);
        $this->assertSame($second_value, $statement->execute_snapshots[1][3]['value']);
        $this->assertSame(\PDO::PARAM_STR, $statement->execute_snapshots[1][3]['type']);

        $this->assertBoundValuesCleared($statement, 3);
    }

    /**
     * @param array<string, \PDOStatement> $cache
     * @param list<string> $cache_order
     */
    private function executePreparedPath(
        \PDO $pdo,
        string $query,
        array &$cache,
        array &$cache_order,
        string &$executed_query
    ): void {
        $client_reflection = new \ReflectionClass(\ImportClient::class);
        $client = $client_reflection->newInstanceWithoutConstructor();
        $method = $client_reflection->getMethod('execute_db_apply_query');
        $method->setAccessible(true);

        $method->invokeArgs($client, [
            $pdo,
            $query,
            null,
            $pdo,
            &$cache,
            &$cache_order,
            &$executed_query,
        ]);
    }

    private function buildInsertQuery(int $id, string $name, string $value): string
    {
        return sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`) VALUES " .
            "(%d, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            $id,
            base64_encode($name),
            base64_encode($value),
        );
    }

    private function assertBoundValuesCleared(RecordingSqlitePreparedStatement $statement, int $param_count): void
    {
        for ($index = 1; $index <= $param_count; $index++) {
            $this->assertArrayHasKey($index, $statement->current_bindings);
            $this->assertNull($statement->current_bindings[$index]['value']);
            $this->assertSame(\PDO::PARAM_NULL, $statement->current_bindings[$index]['type']);
        }

        $this->assertGreaterThanOrEqual(1, $statement->close_cursor_calls);
        $clear_binds = array_slice($statement->bind_log, -$param_count);
        $this->assertCount($param_count, $clear_binds);
        foreach ($clear_binds as $clear_bind) {
            $this->assertTrue($clear_bind['is_null']);
            $this->assertSame(\PDO::PARAM_NULL, $clear_bind['type']);
        }
    }
}
