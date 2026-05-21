<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

class SQLiteRowStreamSidecarTest extends TestCase
{
    /**
     * @param array{sql: string, params: list<mixed>, param_types: list<int>} $prepared
     */
    private function executePrepared(PDO $pdo, array $prepared): void
    {
        $statement = $pdo->prepare($prepared['sql']);
        $this->assertInstanceOf(PDOStatement::class, $statement);
        foreach ($prepared['params'] as $index => $value) {
            $statement->bindValue($index + 1, $value, $prepared['param_types'][$index]);
        }
        $this->assertTrue($statement->execute());
    }

    private function createSqlite(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for row-stream sidecar tests.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('FROM_BASE64', function ($data) {
            if ($data === null) {
                return null;
            }
            return base64_decode($data);
        }, 1);
        return $pdo;
    }

    public function testRecordPreparedInsertMatchesExistingBuilder(): void
    {
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`, `post_excerpt`, `menu_order`) VALUES" .
            "(1, FROM_BASE64('%s'), '', NULL)," .
            "(2, CONVERT(FROM_BASE64('%s') USING utf8mb4), FROM_BASE64('%s'), -3.5e+2);",
            base64_encode('alpha'),
            base64_encode('{"url":"https://example.test/a"}'),
            base64_encode('excerpt-a')
        );

        $record = SQLiteRowStreamSidecar::record_from_sql($sql, 123);
        $prepared = SQLiteRowStreamSidecar::record_to_prepared_insert($record);
        $existing = SQLitePreparedInsertBuilder::build($sql);

        $this->assertTrue(SQLiteRowStreamSidecar::is_insert_record($record));
        $this->assertSame(123, $record['sql_offset']);
        $this->assertSame(strlen($sql), $record['sql_length']);
        $this->assertSame($existing, $prepared);
    }

    public function testRecordPreparedInsertUsesStructuredUrlRewriter(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(7, FROM_BASE64('%s'));",
            base64_encode('<a href="https://old-site.com/page">Link</a>')
        );

        $record = SQLiteRowStreamSidecar::record_from_sql($sql, 0);
        $prepared = SQLiteRowStreamSidecar::record_to_prepared_insert(
            $record,
            function (string $value, string $table, ?string $column) use ($rewriter): string {
                return $rewriter->rewrite_sqlite_row_stream_value($value, $table, $column);
            }
        );

        $this->assertNotNull($prepared);
        $this->assertSame('7', $prepared['params'][0]);
        $this->assertSame('<a href="https://new-site.com/page">Link</a>', $prepared['params'][1]);
    }

    public function testStructuredAndFallbackRecordsProduceSameSqliteRows(): void
    {
        $statements = [
            "CREATE TABLE `wp_posts` (`ID` INTEGER, `post_content` TEXT, `menu_order`);",
            sprintf(
                "INSERT INTO `wp_posts` (`ID`, `post_content`, `menu_order`) VALUES" .
                "(1, FROM_BASE64('%s'), -3.5e+2)," .
                "(2, FROM_BASE64('%s'), NULL);",
                base64_encode('structured alpha'),
                base64_encode('structured bravo')
            ),
            sprintf(
                "INSERT INTO `wp_posts` VALUES(3, FROM_BASE64('%s'), 4.25);",
                base64_encode('fallback charlie')
            ),
        ];

        $fallback = $this->createSqlite();
        foreach ($statements as $statement) {
            $fallback->exec($statement);
        }

        $rowStream = $this->createSqlite();
        foreach ($statements as $statement) {
            $record = SQLiteRowStreamSidecar::record_from_sql($statement, 0);
            if (SQLiteRowStreamSidecar::is_insert_record($record)) {
                $prepared = SQLiteRowStreamSidecar::record_to_prepared_insert($record);
                $this->assertNotNull($prepared);
                $this->executePrepared($rowStream, $prepared);
            } else {
                $rowStream->exec($statement);
            }
        }

        $fallbackRows = $fallback
            ->query('SELECT ID, post_content, menu_order, typeof(menu_order) AS menu_order_type FROM `wp_posts` ORDER BY ID')
            ->fetchAll(PDO::FETCH_ASSOC);
        $rowStreamRows = $rowStream
            ->query('SELECT ID, post_content, menu_order, typeof(menu_order) AS menu_order_type FROM `wp_posts` ORDER BY ID')
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame($fallbackRows, $rowStreamRows);
    }
}
