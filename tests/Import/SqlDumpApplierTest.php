<?php

namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\Sql\DbApplyQueryExecutor;
use Reprint\Importer\Sql\SqlDumpApplier;

require_once __DIR__ . '/../../importer/import.php';

final class SqlDumpApplierTest extends TestCase
{
    private string $state_dir;
    private string $sql_file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state_dir = sys_get_temp_dir() . '/sql-dump-applier-' . bin2hex(random_bytes(6));
        mkdir($this->state_dir, 0755, true);
        $this->sql_file = $this->state_dir . '/db.sql';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->state_dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->state_dir);
        parent::tearDown();
    }

    public function testAppliesSqlDumpAndMarksStateComplete(): void
    {
        file_put_contents(
            $this->sql_file,
            "CREATE TABLE wp_options (option_name TEXT, option_value TEXT);\n" .
            "INSERT INTO wp_options VALUES ('siteurl', 'https://example.test');\n",
        );
        file_put_contents(
            $this->state_dir . '/.import-sql-stats.json',
            json_encode(['statements_total' => 2]) . "\n",
        );

        $checkpoint = DbApplyCheckpoint::fresh();
        $checkpoint->status = 'in_progress';
        $saved_checkpoints = [];
        $progress = [];
        $pdo = new PDO('sqlite::memory:');

        $applier = new SqlDumpApplier(
            fn(): bool => false,
            function (DbApplyCheckpoint $checkpoint) use (&$saved_checkpoints): void {
                $saved_checkpoints[] = clone $checkpoint;
            },
            function (): void {
            },
            function (array $event, bool $force) use (&$progress): void {
                $progress[] = [$event, $force];
            },
            function (): void {
            },
            function (): void {
            },
            function (): void {
            },
            fn(): bool => true,
            fn(PDO $pdo): array => [],
            fn(PDO $pdo, string $new_site_url): array => [],
        );

        $checkpoint = $applier->apply(
            $checkpoint,
            [
                'sql_file' => $this->sql_file,
                'state_dir' => $this->state_dir,
                'new_site_url' => '',
            ],
            new DbApplyQueryExecutor($pdo),
            $pdo,
        );

        $this->assertSame('complete', $checkpoint->status);
        $this->assertSame(2, $checkpoint->statements_executed);
        $this->assertGreaterThan(0, $checkpoint->bytes_read);
        $this->assertSame(
            'https://example.test',
            $pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'")->fetchColumn(),
        );
        $this->assertNotEmpty($saved_checkpoints);
        $this->assertSame('complete', $progress[array_key_last($progress)][0]['status']);
    }
}
