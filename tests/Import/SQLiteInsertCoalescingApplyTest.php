<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../../importer/import.php';

class SQLiteInsertCoalescingApplyTest extends TestCase
{
    private \PDO $pdo;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->tempDir = sys_get_temp_dir() . '/import-sqlite-coalesce-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/fs-root', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function structuredInsert(string $sql, ?\SqlStatementRewriter $rewriter = null): array
    {
        $insert = $rewriter !== null
            ? $rewriter->build_sqlite_structured_insert($sql)
            : \SQLitePreparedInsertBuilder::build_structured($sql);

        $this->assertNotNull($insert, $sql);
        return $insert;
    }

    private function insertSql(string $table, int $id, string $value): string
    {
        return sprintf(
            "INSERT INTO `%s` (`id`, `value`) VALUES (%d, FROM_BASE64('%s'));",
            $table,
            $id,
            base64_encode($value)
        );
    }

    public function testShapeMismatchFlushesBufferedInsertBeforeNextShape(): void
    {
        $this->pdo->exec('CREATE TABLE first_table (id INTEGER PRIMARY KEY, value TEXT)');
        $this->pdo->exec('CREATE TABLE second_table (id INTEGER PRIMARY KEY, value TEXT)');

        $coalescer = new \SQLitePreparedInsertCoalescer($this->pdo, 999);
        $executed = '';
        $result = $coalescer->append($this->structuredInsert($this->insertSql('first_table', 1, 'one')), 10, $executed);
        $this->assertSame(0, $result['statements_executed']);

        $result = $coalescer->append($this->structuredInsert($this->insertSql('second_table', 2, 'two')), 20, $executed);
        $this->assertSame(1, $result['statements_executed']);
        $this->assertSame(10, $result['bytes_read']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM first_table')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM second_table')->fetchColumn());

        $result = $coalescer->flush($executed);
        $this->assertSame(1, $result['statements_executed']);
        $this->assertSame(20, $result['bytes_read']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM second_table')->fetchColumn());
    }

    public function testParameterLimitSplitsBufferedStatements(): void
    {
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $coalescer = new \SQLitePreparedInsertCoalescer($this->pdo, 4);
        $executed = '';

        $this->assertSame(
            0,
            $coalescer->append($this->structuredInsert($this->insertSql('items', 1, 'one')), 10, $executed)['statements_executed']
        );

        $result = $coalescer->append($this->structuredInsert($this->insertSql('items', 2, 'two')), 20, $executed);
        $this->assertSame(2, $result['statements_executed']);
        $this->assertSame(20, $result['bytes_read']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());

        $result = $coalescer->append($this->structuredInsert($this->insertSql('items', 3, 'three')), 30, $executed);
        $this->assertSame(0, $result['statements_executed']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());

        $coalescer->flush($executed);
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testApplyFlushesCoalescedInsertBeforeDdlFallback(): void
    {
        $this->pdo->exec('CREATE TABLE source_rows (id INTEGER PRIMARY KEY, value TEXT)');

        $client = new \ImportClient('https://old.example/?reprint-api', $this->tempDir, $this->tempDir . '/fs-root');
        $method = new ReflectionMethod(\ImportClient::class, 'execute_db_apply_query');
        $method->setAccessible(true);

        $coalescer = new \SQLitePreparedInsertCoalescer($this->pdo, 999);
        $executed = '';
        $insert = $this->insertSql('source_rows', 1, 'before-ddl');
        $result = $method->invokeArgs($client, [
            $this->pdo,
            $insert,
            null,
            $this->pdo,
            $coalescer,
            strlen($insert),
            &$executed,
        ]);
        $this->assertSame(0, $result['statements_executed']);

        $ddl = 'CREATE TABLE copied_rows AS SELECT * FROM source_rows;';
        $result = $method->invokeArgs($client, [
            $this->pdo,
            $ddl,
            null,
            $this->pdo,
            $coalescer,
            strlen($insert) + strlen($ddl),
            &$executed,
        ]);
        $this->assertSame(2, $result['statements_executed']);
        $this->assertSame('before-ddl', $this->pdo->query('SELECT value FROM copied_rows WHERE id = 1')->fetchColumn());
    }

    public function testApplyExecutesStructuredInsertImmediatelyWithoutSharedCoalescer(): void
    {
        $this->pdo->exec('CREATE TABLE immediate_rows (id INTEGER PRIMARY KEY, value TEXT)');

        $client = new \ImportClient('https://old.example/?reprint-api', $this->tempDir, $this->tempDir . '/fs-root');
        $method = new ReflectionMethod(\ImportClient::class, 'execute_db_apply_query');
        $method->setAccessible(true);

        $executed = '';
        $insert = $this->insertSql('immediate_rows', 1, 'one-off');
        $result = $method->invokeArgs($client, [
            $this->pdo,
            $insert,
            null,
            $this->pdo,
            null,
            strlen($insert),
            &$executed,
        ]);

        $this->assertSame(1, $result['statements_executed']);
        $this->assertSame(strlen($insert), $result['bytes_read']);
        $this->assertSame('one-off', $this->pdo->query('SELECT value FROM immediate_rows WHERE id = 1')->fetchColumn());
    }

    public function testErrorRollsBackWholeCoalescedFlushAcrossParameterSplits(): void
    {
        $this->pdo->exec('CREATE TABLE unique_items (id INTEGER PRIMARY KEY, value TEXT)');
        $coalescer = new \SQLitePreparedInsertCoalescer($this->pdo, 5);
        $executed = '';
        $sql = sprintf(
            "INSERT INTO `unique_items` (`id`, `value`) VALUES "
            . "(1, FROM_BASE64('%s')),"
            . "(2, FROM_BASE64('%s')),"
            . "(1, FROM_BASE64('%s'));",
            base64_encode('one'),
            base64_encode('two'),
            base64_encode('duplicate')
        );

        try {
            $coalescer->append($this->structuredInsert($sql), 30, $executed);
            $this->fail('Expected duplicate primary key failure.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('UNIQUE', strtoupper($e->getMessage()));
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM unique_items')->fetchColumn());
    }

    public function testCoalescedFlushSavepointStaysInsideExistingTransaction(): void
    {
        $this->pdo->exec('CREATE TABLE transaction_rows (id INTEGER PRIMARY KEY, value TEXT)');
        $coalescer = new \SQLitePreparedInsertCoalescer($this->pdo, 999);
        $executed = '';

        $this->pdo->beginTransaction();
        $coalescer->append($this->structuredInsert($this->insertSql('transaction_rows', 1, 'inside')), 10, $executed);
        $coalescer->append($this->structuredInsert($this->insertSql('transaction_rows', 2, 'transaction')), 20, $executed);
        $result = $coalescer->flush($executed);

        $this->assertSame(2, $result['statements_executed']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM transaction_rows')->fetchColumn());

        $this->pdo->rollBack();
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM transaction_rows')->fetchColumn());
    }

    public function testCoalescedInsertPreservesRowOrder(): void
    {
        $this->pdo->exec('CREATE TABLE ordered_log (label TEXT)');
        $coalescer = new \SQLitePreparedInsertCoalescer($this->pdo, 999);
        $executed = '';

        foreach (['first', 'second', 'third'] as $index => $label) {
            $sql = sprintf(
                "INSERT INTO `ordered_log` (`label`) VALUES (FROM_BASE64('%s'));",
                base64_encode($label)
            );
            $coalescer->append($this->structuredInsert($sql), ($index + 1) * 10, $executed);
        }
        $coalescer->flush($executed);

        $rows = $this->pdo->query('SELECT label FROM ordered_log ORDER BY rowid')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['first', 'second', 'third'], $rows);
    }

    public function testCoalescedStructuredInsertsKeepUrlRewriteSemantics(): void
    {
        $this->pdo->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT, post_title TEXT)');
        $rewriter = new \SqlStatementRewriter(
            new \StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
        $coalescer = new \SQLitePreparedInsertCoalescer($this->pdo, 999);
        $executed = '';

        $values = [
            '<a href="HTTPS://OLD-SITE.COM/case-only">Case</a>',
            '<!-- wp:image {"src":"https:\/\/old-site.com\/escaped.jpg"} -->',
        ];
        foreach ($values as $index => $value) {
            $sql = sprintf(
                "INSERT INTO `wp_posts` (`ID`, `post_content`, `post_title`) VALUES "
                . "(%d, FROM_BASE64('%s'), FROM_BASE64('%s'));",
                $index + 1,
                base64_encode($value),
                base64_encode('Title')
            );
            $coalescer->append($this->structuredInsert($sql, $rewriter), ($index + 1) * 10, $executed);
        }
        $coalescer->flush($executed);

        $contents = $this->pdo
            ->query('SELECT post_content FROM wp_posts ORDER BY ID')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertStringContainsString('https://new-site.com/case-only', $contents[0]);
        $this->assertStringContainsString('https:\/\/new-site.com\/escaped.jpg', $contents[1]);
        $this->assertStringNotContainsString('old-site.com', strtolower(implode("\n", $contents)));
    }
}
