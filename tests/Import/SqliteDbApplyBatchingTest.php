<?php

namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../importer/import.php';

class SqliteDbApplyBatchingTest extends TestCase
{
    private string $tempDir;
    private string $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $this->tempDir = sys_get_temp_dir() . '/sqlite-db-apply-batching-' . uniqid();
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->tempDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testSetAutocommitAndTransactionControlStatementsDoNotCorruptBatching(): void
    {
        $sqlitePath = $this->tempDir . '/target.sqlite';
        $statements = array_merge(
            [
                "SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;",
                "SET AUTOCOMMIT=0;",
            ],
            $this->schemaStatements(),
            [
                "START TRANSACTION;",
                $this->insertStatement(1, 'inside explicit transaction'),
                "COMMIT;",
                $this->insertStatement(2, 'after explicit transaction'),
                "SET AUTOCOMMIT=1;",
                "COMMIT;",
            ],
        );

        $this->writeSql($statements);
        $this->writeState();
        $this->runDbApply($sqlitePath);

        $rows = $this->queryTarget($sqlitePath, "SELECT id, name FROM wp_batch ORDER BY id");
        $this->assertSame([
            ['id' => 1, 'name' => 'inside explicit transaction'],
            ['id' => 2, 'name' => 'after explicit transaction'],
        ], $rows);

        $state = $this->readState();
        $this->assertSame('complete', $state['status']);
        $this->assertSame(count($statements), $state['apply']['statements_executed']);
    }

    public function testPreparedInsertFastPathHonorsRollbackAndCommit(): void
    {
        $sqlitePath = $this->tempDir . '/target.sqlite';
        $statements = array_merge(
            $this->schemaStatements(),
            [
                "START TRANSACTION;",
                $this->insertStatement(1, 'rolled back'),
                "ROLLBACK;",
                "START TRANSACTION;",
                $this->insertStatement(2, 'committed'),
                "COMMIT;",
            ],
        );

        $this->writeSql($statements);
        $this->writeState();
        $this->runDbApply($sqlitePath);

        $rows = $this->queryTarget($sqlitePath, "SELECT id, name FROM wp_batch ORDER BY id");
        $this->assertSame([
            ['id' => 2, 'name' => 'committed'],
        ], $rows);
    }

    public function testMarkerAndDataAreAtomicWhenBatchRollsBackWithFallbackStatement(): void
    {
        $sqlitePath = $this->tempDir . '/target.sqlite';
        $statements = array_merge(
            $this->schemaStatements(),
            [
                $this->insertStatement(1, 'before fallback'),
                $this->updateStatement(1, 'updated by fallback'),
                $this->insertStatement(1, 'duplicate id'),
            ],
        );

        $this->writeSql($statements);
        $this->writeState();

        $this->expectException(RuntimeException::class);
        try {
            $this->runDbApply($sqlitePath);
        } finally {
            $rows = $this->queryTarget($sqlitePath, "SELECT COUNT(*) AS c FROM wp_batch");
            $this->assertSame(0, (int) $rows[0]['c']);

            $marker = $this->readMarker($sqlitePath);
            $this->assertSame(2, $marker['statements_executed']);
            $this->assertSame($this->bytesThroughStatements($this->schemaStatements(), 2), $marker['bytes_read']);
        }
    }

    public function testCommittedBatchSurvivesLaterBatchRollback(): void
    {
        $sqlitePath = $this->tempDir . '/target.sqlite';
        $insertStatements = [];
        for ($id = 1; $id <= 505; $id++) {
            $insertStatements[] = $this->insertStatement($id, 'row ' . $id);
        }

        $statements = array_merge(
            $this->schemaStatements(),
            $insertStatements,
            [
                $this->insertStatement(501, 'duplicate in second batch'),
            ],
        );

        $this->writeSql($statements);
        $this->writeState();

        $this->expectException(RuntimeException::class);
        try {
            $this->runDbApply($sqlitePath);
        } finally {
            $rows = $this->queryTarget($sqlitePath, "SELECT COUNT(*) AS c FROM wp_batch");
            $this->assertSame(500, (int) $rows[0]['c']);

            $rows = $this->queryTarget($sqlitePath, "SELECT id, name FROM wp_batch WHERE id IN (500, 501) ORDER BY id");
            $this->assertSame([
                ['id' => 500, 'name' => 'row 500'],
            ], $rows);

            $committedStatements = array_merge(
                $this->schemaStatements(),
                array_slice($insertStatements, 0, 500),
            );
            $marker = $this->readMarker($sqlitePath);
            $this->assertSame(count($committedStatements), $marker['statements_executed']);
            $this->assertSame(
                $this->bytesThroughStatements($statements, count($committedStatements)),
                $marker['bytes_read'],
            );
        }
    }

    public function testResumeReconcilesJsonAheadOfCommittedMarker(): void
    {
        $sqlitePath = $this->tempDir . '/target.sqlite';
        $prefixStatements = $this->schemaStatements();
        $fullStatements = array_merge(
            $prefixStatements,
            [
                $this->insertStatement(1, 'one'),
                $this->insertStatement(2, 'two'),
                $this->insertStatement(3, 'three'),
            ],
        );

        $this->writeSql($prefixStatements);
        $this->writeState();
        $this->runDbApply($sqlitePath);

        $this->writeSql($fullStatements);
        $this->writeState([
            'command' => 'db-apply',
            'status' => 'in_progress',
            'apply' => [
                'statements_executed' => count($fullStatements),
                'bytes_read' => strlen($this->sqlText($fullStatements)),
                'target_engine' => 'sqlite',
                'target_db' => 'wp_test',
                'target_sqlite_path' => $sqlitePath,
            ],
        ]);
        $this->runDbApply($sqlitePath);

        $rows = $this->queryTarget($sqlitePath, "SELECT id, name FROM wp_batch ORDER BY id");
        $this->assertSame([
            ['id' => 1, 'name' => 'one'],
            ['id' => 2, 'name' => 'two'],
            ['id' => 3, 'name' => 'three'],
        ], $rows);

        $state = $this->readState();
        $this->assertSame('complete', $state['status']);
        $this->assertSame(count($fullStatements), $state['apply']['statements_executed']);
        $this->assertSame(
            $this->bytesThroughStatements($fullStatements, count($fullStatements)),
            $state['apply']['bytes_read'],
        );
    }

    /**
     * @return string[]
     */
    private function schemaStatements(): array
    {
        return [
            "DROP TABLE IF EXISTS `wp_batch`;",
            "CREATE TABLE `wp_batch` (" .
            "`id` bigint(20) unsigned NOT NULL, " .
            "`name` longtext NOT NULL, " .
            "PRIMARY KEY (`id`)" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        ];
    }

    private function insertStatement(int $id, string $name): string
    {
        return sprintf(
            "INSERT INTO `wp_batch` (`id`, `name`) VALUES (%d, FROM_BASE64('%s'));",
            $id,
            base64_encode($name),
        );
    }

    private function updateStatement(int $id, string $name): string
    {
        return sprintf(
            "UPDATE `wp_batch` SET `name` = FROM_BASE64('%s') WHERE `id` = %d;",
            base64_encode($name),
            $id,
        );
    }

    /**
     * @param string[] $statements
     */
    private function writeSql(array $statements): void
    {
        file_put_contents($this->tempDir . '/db.sql', $this->sqlText($statements));
    }

    /**
     * @param string[] $statements
     */
    private function sqlText(array $statements): string
    {
        return implode("\n", $statements) . "\n";
    }

    /**
     * @param string[] $statements
     */
    private function bytesThroughStatements(array $statements, int $count): int
    {
        $bytes = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                $bytes++;
            }
            $bytes += strlen($statements[$i]);
        }
        return $bytes;
    }

    private function writeState(array $state = []): void
    {
        file_put_contents(
            $this->tempDir . '/.import-state.json',
            json_encode($state, JSON_PRETTY_PRINT),
        );
    }

    private function readState(): array
    {
        $state = json_decode(file_get_contents($this->tempDir . '/.import-state.json'), true);
        $this->assertIsArray($state);
        return $state;
    }

    private function runDbApply(string $sqlitePath): void
    {
        $client = new \ImportClient(
            'https://source.example/export.php',
            $this->tempDir,
            $this->fsRoot,
        );
        $client->run([
            'command' => 'db-apply',
            'abort' => false,
            'verbose' => false,
            'secret' => null,
            'tuning_config' => [],
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $sqlitePath,
            'target_db' => 'wp_test',
        ]);
    }

    private function queryTarget(string $sqlitePath, string $sql): array
    {
        $polyfills = resolve_sqlite_integration_path('/php-polyfills.php');
        $driver = resolve_sqlite_integration_path('/wp-pdo-mysql-on-sqlite.php');
        require_once $polyfills;
        require_once $driver;

        $pdo = new \WP_PDO_MySQL_On_SQLite(
            "mysql-on-sqlite:path={$sqlitePath};dbname=wp_test",
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function readMarker(string $sqlitePath): array
    {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $row = $pdo
            ->query('SELECT statements_executed, bytes_read FROM _reprint_db_apply_progress WHERE id = 1')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return [
            'statements_executed' => (int) $row['statements_executed'],
            'bytes_read' => (int) $row['bytes_read'],
        ];
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
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }
        rmdir($dir);
    }
}
