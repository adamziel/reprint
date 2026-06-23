<?php

namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Sql\DbApplyQueryExecutor;
use Reprint\Importer\UrlRewrite\SqlStatementRewriter;
use Reprint\Importer\UrlRewrite\StructuredDataUrlRewriter;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/bootstrap.php';

final class DbApplyQueryExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
    }

    public function testExecutesSqlitePreparedInsert(): void
    {
        $pdo = $this->createSqlitePdo();
        $pdo->exec('CREATE TABLE `wp_options` (`option_id` INTEGER, `option_value` BLOB)');

        $executor = new DbApplyQueryExecutor($pdo, null, $pdo);
        $executed = $executor->execute(sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode("hello\0world"),
        ));

        $this->assertSame(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(CAST(? AS NUMERIC), ?);",
            $executed,
        );

        $row = $pdo->query('SELECT option_id, option_value FROM `wp_options`')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, $row['option_id']);
        $this->assertSame("hello\0world", $row['option_value']);
    }

    public function testPreparedInsertUsesRewriterWhenConfigured(): void
    {
        $pdo = $this->createSqlitePdo();
        $pdo->exec('CREATE TABLE `wp_options` (`option_id` INTEGER, `option_value` TEXT)');

        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old.example' => 'https://new.example',
            ]),
            'wp_',
        );

        $executor = new DbApplyQueryExecutor($pdo, $rewriter, $pdo);
        $executor->execute(sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('https://old.example/path'),
        ));

        $value = $pdo->query('SELECT option_value FROM `wp_options`')->fetchColumn();
        $this->assertSame('https://new.example/path', $value);
    }

    public function testFallsBackToExecForNonPreparedStatements(): void
    {
        $pdo = $this->createSqlitePdo();
        $executor = new DbApplyQueryExecutor($pdo);

        $executed = $executor->execute('CREATE TABLE `wp_options` (`option_id` INTEGER)');

        $this->assertSame('CREATE TABLE `wp_options` (`option_id` INTEGER)', $executed);
        $this->assertSame(
            'wp_options',
            $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchColumn(),
        );
    }

    private function createSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }
}
