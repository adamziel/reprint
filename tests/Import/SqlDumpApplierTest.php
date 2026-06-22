<?php

namespace ImportTests;

use PDO;
use PHPUnit\Framework\TestCase;
use Reprint\Importer\Observability\NullAuditLogger;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\Sql\DbApplyQueryExecutor;
use Reprint\Importer\Sql\Port\DbApplyCheckpointStore;
use Reprint\Importer\Sql\Port\DbApplyObserver;
use Reprint\Importer\Sql\Port\DbApplyShutdownToken;
use Reprint\Importer\Sql\Port\PluginDeactivationPolicy;
use Reprint\Importer\Sql\Port\SqlStatementStatsStore;
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
            new SqlDumpApplierTestShutdownToken(),
            new SqlDumpApplierTestCheckpointStore($saved_checkpoints),
            new NullAuditLogger(),
            new SqlDumpApplierTestObserver($progress),
            new SqlDumpApplierTestStatsStore(2),
            new SqlDumpApplierTestPluginPolicy(),
        );

        $checkpoint = $applier->apply(
            $checkpoint,
            [
                'sql_file' => $this->sql_file,
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

final class SqlDumpApplierTestShutdownToken implements DbApplyShutdownToken
{
    public function is_shutdown_requested(): bool
    {
        return false;
    }
}

final class SqlDumpApplierTestCheckpointStore implements DbApplyCheckpointStore
{
    /** @var array<int, DbApplyCheckpoint> */
    private $saved;

    public function __construct(array &$saved)
    {
        $this->saved =& $saved;
    }

    public function get(): DbApplyCheckpoint
    {
        return DbApplyCheckpoint::fresh();
    }

    public function save(DbApplyCheckpoint $checkpoint): void
    {
        $this->saved[] = clone $checkpoint;
    }
}

final class SqlDumpApplierTestObserver implements DbApplyObserver
{
    private $progress;

    public function __construct(array &$progress)
    {
        $this->progress =& $progress;
    }

    public function on_workflow_starting(): void
    {
    }

    public function on_workflow_resuming(int $statements_executed, int $bytes_read): void
    {
    }

    public function on_domains_discovered(array $domains, array $url_mapping): void
    {
    }

    public function on_lifecycle_line(string $message): void
    {
    }

    public function on_apply_starting(?int $statements_total): void
    {
        $this->progress[] = [['status' => 'starting', 'statements_total' => $statements_total], false];
    }

    public function on_apply_progress(
        int $statements_executed,
        ?int $statements_total,
        int $bytes_read,
        int $bytes_total
    ): void {
    }

    public function on_apply_partial(int $statements_executed, ?int $statements_total): void
    {
        $this->progress[] = [['status' => 'partial'], true];
    }

    public function on_apply_complete(int $statements_executed, ?int $statements_total): void
    {
        $this->progress[] = [['status' => 'complete'], false];
    }

    public function on_fast_query_stream_fallback(int $byte_offset): void
    {
    }
}

final class SqlDumpApplierTestStatsStore implements SqlStatementStatsStore
{
    private ?int $total;

    public function __construct(?int $total)
    {
        $this->total = $total;
    }

    public function load_total(): ?int
    {
        return $this->total;
    }

    public function persist_total(int $statements_total): void
    {
    }
}

final class SqlDumpApplierTestPluginPolicy implements PluginDeactivationPolicy
{
    public function deactivate_host_specific(PDO $pdo): array
    {
        return [];
    }

    public function deactivate_path_incompatible(PDO $pdo, string $new_site_url): array
    {
        return [];
    }
}
