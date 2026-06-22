<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\ImportClient;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\DbPullWorkflow;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class DbPullWorkflowTest extends TestCase
{
    private string $temp_dir;
    private DbPullWorkflowTestClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/db-pull-workflow-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->client = new DbPullWorkflowTestClient($this->temp_dir);
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
        foreach (DbPullWorkflowTestClient::TABLE_ROWS as $row) {
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
            $this->client,
            $this->temp_dir,
            $this->temp_dir . '/.import-audit.log',
            'file',
            null,
            new BufferedImportOutput(),
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

final class DbPullWorkflowTestClient extends ImportClient
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

    public function __construct(string $state_dir)
    {
        parent::__construct(
            'https://example.test/export.php',
            $state_dir,
            $state_dir . '/fs',
            new BufferedImportOutput(),
        );
    }

    public function save_db_pull_checkpoint(DbPullCheckpoint $checkpoint): void
    {
        $this->saved_checkpoints[] = clone $checkpoint;
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
        $this->audit[] = [$message, $to_console];
    }

    public function output_progress(array $data, bool $force = false): void
    {
        $this->progress[] = [$data, $force];
    }

    public function stream_export_endpoint(
        string $endpoint,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        array $params = []
    ): void {
        $this->downloads[] = 'db-index';
        TestCase::assertSame('db_index', $endpoint);
        TestCase::assertNull($post_data);
        TestCase::assertSame(['tables_per_batch' => 1000], $params);

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

    public function assert_can_retry_db_pull_timeout(
        DbPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        $this->retry_checks[] = [
            'phase' => $phase,
            'before' => $cursor_before,
            'after' => $cursor_after,
        ];
        $checkpoint->consecutive_timeouts++;
    }

    public function finalize_stream_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
        $this->finalized[] = compact('endpoint', 'wall_time', 'response_stats');
    }

    public function download_sql_stage(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        $this->downloads[] = 'sql';
        return $checkpoint;
    }
}
