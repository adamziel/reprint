<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbPullConfiguration;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Infrastructure\JsonlDbIndexTableSinkFactory;
use Reprint\Importer\Sql\Infrastructure\RemoteDbIndexDownloader;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;
use Reprint\Importer\Sql\Port\DbPullObserver;
use Reprint\Importer\Sql\Port\DbPullTimeoutPolicy;
use Reprint\Importer\Sql\Port\SqlDumpDownloader;
use Reprint\Importer\Sql\Port\SqlShutdownToken;
use Reprint\Importer\Sql\Port\SqlStreamClient;
use Reprint\Importer\Sql\DbPullWorkflow;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class DbPullWorkflowTest extends TestCase
{
    private string $temp_dir;
    private DbPullWorkflowTestHarness $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/db-pull-workflow-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->client = new DbPullWorkflowTestHarness();
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testFreshRunIndexesThenDownloadsSqlAndCompletes(): void
    {
        $checkpoint = DbPullCheckpoint::fresh();

        $checkpoint = $this->make_workflow()->run($checkpoint);

        $this->assertSame('complete', $checkpoint->status);
        $this->assertSame('sql', $checkpoint->stage);
        $this->assertSame(['db-index', 'sql'], $this->client->downloads);
        $this->assertSame(3, $checkpoint->db_index->tables);
        $this->assertSame('complete', $this->last_saved_checkpoint()->status);
    }

    public function testPartialDbIndexReturnsWithoutSqlDownload(): void
    {
        $checkpoint = DbPullCheckpoint::fresh();
        $this->client->db_index_times_out = true;

        $checkpoint = $this->make_workflow()->run($checkpoint);

        $this->assertSame('partial', $checkpoint->status);
        $this->assertSame(['db-index'], $this->client->downloads);
        $this->assertSame([
            ['phase' => 'db_index', 'before' => null, 'after' => null],
        ], $this->client->retry_checks);
    }

    public function testDownloadDbIndexWritesTableStatsAndPersistsState(): void
    {
        $checkpoint = DbPullCheckpoint::fresh();
        $tables_file = $this->temp_dir . '/db-tables.jsonl';

        $checkpoint = $this->make_workflow()->download_db_index($checkpoint, $tables_file);

        $expected = '';
        foreach (DbPullWorkflowTestHarness::TABLE_ROWS as $row) {
            $expected .= json_encode($row) . "\n";
        }
        $this->assertSame($expected, file_get_contents($tables_file));
        $this->assertSame('next-cursor', $checkpoint->cursor);
        $this->assertSame($tables_file, $checkpoint->db_index->file);
        $this->assertSame(3, $checkpoint->db_index->tables);
        $this->assertSame(15, $checkpoint->db_index->rows_estimated);
        $this->assertSame(filesize($tables_file), $checkpoint->db_index->bytes);
        $this->assertSame(0, $checkpoint->consecutive_timeouts);
        $this->assertSame('db_index', $this->client->finalized[0]['endpoint']);
    }

    public function testCompletedFileOutputRequiresAbort(): void
    {
        file_put_contents($this->temp_dir . '/db.sql', '');
        $checkpoint = DbPullCheckpoint::fresh();
        $checkpoint->status = 'complete';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db-pull already completed and db.sql exists');

        $this->make_workflow()->run($checkpoint);
    }

    private function make_workflow(): DbPullWorkflow
    {
        return new DbPullWorkflow(
            new DbPullConfiguration(
                $this->temp_dir,
                $this->temp_dir . '/.import-audit.log',
                'file',
                null,
            ),
            new WorkflowTestCheckpointStore($this->client),
            new RemoteDbIndexDownloader(
                new WorkflowTestStreamClient($this->client),
                new WorkflowTestShutdownToken(),
                new WorkflowTestCheckpointStore($this->client),
                new WorkflowTestTimeoutPolicy($this->client),
                new JsonlDbIndexTableSinkFactory(new WorkflowTestAuditLogger($this->client)),
                $this->temp_dir . '/db-tables.jsonl',
            ),
            new WorkflowTestSqlDumpDownloader($this->client),
            new WorkflowTestDbPullObserver(),
            new WorkflowTestAuditLogger($this->client),
        );
    }

    private function last_saved_checkpoint(): DbPullCheckpoint
    {
        return $this->client->saved_checkpoints[count($this->client->saved_checkpoints) - 1];
    }

    private function remove_path(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_link($path) || is_file($path)) {
            return unlink($path);
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->remove_path($path . '/' . $entry)) {
                return false;
            }
        }
        return rmdir($path);
    }
}

final class DbPullWorkflowTestHarness
{
    public const TABLE_ROWS = [
        ['name' => 'wp_posts', 'rows' => 5, 'bytes' => 100],
        ['name' => 'wp_options', 'rows' => 7, 'bytes' => 200],
        ['name' => 'wp_comments', 'rows' => 3, 'bytes' => 300],
    ];

    public array $saved_checkpoints = [];
    public array $audit = [];
    public array $progress = [];
    public array $downloads = [];
    public array $retry_checks = [];
    public array $finalized = [];
    public bool $db_index_times_out = false;

    public function fetch_db_index(
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        $this->downloads[] = 'db-index';
        TestCase::assertNull($post_data);
        TestCase::assertSame('db_index', $phase);

        if ($this->db_index_times_out) {
            throw new CurlTimeoutException('cURL error: timeout');
        }

        ($context->on_chunk)([
            'headers' => [
                'x-chunk-type' => 'table_stats',
                'x-cursor' => 'next-cursor',
            ],
            'body' => json_encode(self::TABLE_ROWS),
        ]);
        ($context->on_chunk)([
            'headers' => [
                'x-chunk-type' => 'completion',
                'x-status' => 'complete',
                'x-tables-processed' => '3',
                'x-rows-estimated' => '15',
            ],
        ]);
    }
}

final class WorkflowTestStreamClient implements SqlStreamClient
{
    private DbPullWorkflowTestHarness $harness;

    public function __construct(DbPullWorkflowTestHarness $harness)
    {
        $this->harness = $harness;
    }

    public function build_url(string $endpoint, ?string $cursor, array $params): string
    {
        TestCase::assertSame('db_index', $endpoint);
        TestCase::assertSame(['tables_per_batch' => 1000], $params);
        return 'https://example.test/export.php?endpoint=db_index';
    }

    public function tuned_params(string $endpoint): array
    {
        return [];
    }

    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        TestCase::assertSame('https://example.test/export.php?endpoint=db_index', $url);
        $this->harness->fetch_db_index($cursor, $context, $post_data, $phase);
    }

    public function finalize_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
        $this->harness->finalized[] = compact('endpoint', 'wall_time', 'response_stats');
    }
}

final class WorkflowTestShutdownToken implements SqlShutdownToken
{
    public function is_shutdown_requested(): bool
    {
        return false;
    }
}

final class WorkflowTestCheckpointStore implements DbPullCheckpointStore
{
    private DbPullWorkflowTestHarness $harness;

    public function __construct(DbPullWorkflowTestHarness $harness)
    {
        $this->harness = $harness;
    }

    public function get(): DbPullCheckpoint
    {
        return DbPullCheckpoint::fresh();
    }

    public function save(DbPullCheckpoint $checkpoint): void
    {
        $this->harness->saved_checkpoints[] = clone $checkpoint;
    }
}

final class WorkflowTestTimeoutPolicy implements DbPullTimeoutPolicy
{
    private DbPullWorkflowTestHarness $harness;

    public function __construct(DbPullWorkflowTestHarness $harness)
    {
        $this->harness = $harness;
    }

    public function assert_can_retry(
        DbPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        $this->harness->retry_checks[] = [
            'phase' => $phase,
            'before' => $cursor_before,
            'after' => $cursor_after,
        ];
        $checkpoint->consecutive_timeouts++;
    }
}

final class WorkflowTestSqlDumpDownloader implements SqlDumpDownloader
{
    private DbPullWorkflowTestHarness $harness;

    public function __construct(DbPullWorkflowTestHarness $harness)
    {
        $this->harness = $harness;
    }

    public function download(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        $this->harness->downloads[] = 'sql';
        return $checkpoint;
    }
}

final class WorkflowTestDbPullObserver implements DbPullObserver
{
    public function on_starting(): void
    {
    }

    public function on_resuming(DbPullCheckpoint $checkpoint): void
    {
    }

    public function on_stage_starting(string $phase, string $message): void
    {
    }

    public function on_complete(DbPullConfiguration $config): void
    {
    }
}

final class WorkflowTestAuditLogger implements AuditLogger
{
    private DbPullWorkflowTestHarness $harness;

    public function __construct(DbPullWorkflowTestHarness $harness)
    {
        $this->harness = $harness;
    }

    public function record(string $message, bool $to_console = true): void
    {
        $this->harness->audit[] = [$message, $to_console];
    }

    public function path(): string
    {
        return '';
    }
}
