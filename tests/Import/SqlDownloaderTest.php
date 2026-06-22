<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Observability\NullAuditLogger;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbIndexCheckpoint;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Infrastructure\JsonSqlDomainStore;
use Reprint\Importer\Sql\Infrastructure\JsonSqlStatementStatsStore;
use Reprint\Importer\Sql\Infrastructure\LocalSqlOutputSinkFactory;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;
use Reprint\Importer\Sql\Port\DbPullTimeoutPolicy;
use Reprint\Importer\Sql\Port\SqlShutdownToken;
use Reprint\Importer\Sql\Port\SqlStreamClient;
use Reprint\Importer\Sql\Port\SqlStreamObserver;
use Reprint\Importer\Sql\SqlDownloader;

require_once __DIR__ . '/../../importer/import.php';

final class SqlDownloaderTest extends TestCase
{
    private string $state_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state_dir = sys_get_temp_dir() . '/sql-downloader-' . bin2hex(random_bytes(6));
        mkdir($this->state_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->state_dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->state_dir);
        parent::tearDown();
    }

    public function testDownloadsSqlToFileAndPersistsState(): void
    {
        $checkpoint = DbPullCheckpoint::fresh();
        $checkpoint->db_index = new DbIndexCheckpoint(null, 0, 0, 100);
        $saved_checkpoints = [];
        $finalized = [];
        $progress_bytes = [];
        $completion_progress = [];
        $sql = "CREATE TABLE wp_posts (ID int);\n";
        $audit = new NullAuditLogger();

        $downloader = new SqlDownloader(
            new TestSqlStreamClient(
                function (string $endpoint, ?string $cursor, array $params): string {
                    $this->assertSame('sql_chunk', $endpoint);
                    $this->assertNull($cursor);
                    $this->assertSame(['chunk_rows' => 100], $params);

                    return 'https://example.test/export.php?endpoint=sql_chunk';
                },
                function (
                    string $url,
                    ?string $cursor,
                    StreamingContext $context,
                    ?array $post_data,
                    string $phase
                ) use ($sql): void {
                    $this->assertSame('https://example.test/export.php?endpoint=sql_chunk', $url);
                    $this->assertNull($cursor);
                    $this->assertNull($post_data);
                    $this->assertSame('sql_chunk', $phase);

                    ($context->on_chunk)([
                        'headers' => [
                            'x-chunk-type' => 'sql',
                            'x-cursor' => 'done-cursor',
                            'x-query-complete' => '1',
                        ],
                        'body' => $sql,
                    ]);
                    ($context->on_chunk)([
                        'headers' => [
                            'x-chunk-type' => 'completion',
                            'x-status' => 'complete',
                            'x-sql-bytes' => (string) strlen($sql),
                        ],
                    ]);
                },
                fn(string $endpoint): array => ['chunk_rows' => 100],
                function (string $endpoint, float $wall_time, array $stats) use (&$finalized): void {
                    $finalized[] = compact('endpoint', 'wall_time', 'stats');
                },
            ),
            new DownloaderTestSqlShutdownToken(),
            new DownloaderTestDbPullCheckpointStore($saved_checkpoints),
            new DownloaderTestDbPullTimeoutPolicy(),
            new LocalSqlOutputSinkFactory($audit),
            new JsonSqlDomainStore($this->state_dir . '/.import-domains.json'),
            new JsonSqlStatementStatsStore($this->state_dir . '/.import-sql-stats.json'),
            new DownloaderTestSqlStreamObserver(
                $progress_bytes,
                $completion_progress,
            ),
            $audit,
        );

        $downloader->download(
            $checkpoint,
            [
                'mode' => 'file',
                'state_dir' => $this->state_dir,
                'remote_url' => 'https://source.example/export.php',
                'save_every' => 50,
            ],
        );

        $this->assertSame($sql, file_get_contents($this->state_dir . '/db.sql'));
        $this->assertSame('done-cursor', $checkpoint->cursor);
        $this->assertNull($checkpoint->sql_bytes);
        $this->assertSame(0, $checkpoint->consecutive_timeouts);
        $this->assertSame([strlen($sql)], $progress_bytes);
        $this->assertNotEmpty($saved_checkpoints);
        $this->assertSame('sql_chunk', $finalized[0]['endpoint']);
        $this->assertSame('complete', $finalized[0]['stats']['status']);
        $this->assertSame('complete', $completion_progress[0]['status']);
        $this->assertStringContainsString(
            'https://source.example',
            (string) file_get_contents($this->state_dir . '/.import-domains.json'),
        );
    }
}

final class TestSqlStreamClient implements SqlStreamClient
{
    /** @var callable */
    private $build_url;

    /** @var callable */
    private $fetch_streaming;

    /** @var callable */
    private $tuned_params;

    /** @var callable */
    private $finalize_request;

    public function __construct(
        callable $build_url,
        callable $fetch_streaming,
        callable $tuned_params,
        callable $finalize_request
    ) {
        $this->build_url = $build_url;
        $this->fetch_streaming = $fetch_streaming;
        $this->tuned_params = $tuned_params;
        $this->finalize_request = $finalize_request;
    }

    public function build_url(string $endpoint, ?string $cursor, array $params): string
    {
        return ($this->build_url)($endpoint, $cursor, $params);
    }

    public function tuned_params(string $endpoint): array
    {
        return ($this->tuned_params)($endpoint);
    }

    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        ($this->fetch_streaming)($url, $cursor, $context, $post_data, $phase);
    }

    public function finalize_request(string $endpoint, float $wall_time, array $response_stats): void
    {
        ($this->finalize_request)($endpoint, $wall_time, $response_stats);
    }
}

final class DownloaderTestSqlShutdownToken implements SqlShutdownToken
{
    public function is_shutdown_requested(): bool
    {
        return false;
    }
}

final class DownloaderTestDbPullCheckpointStore implements DbPullCheckpointStore
{
    /** @var array<int, DbPullCheckpoint> */
    private $saved;

    public function __construct(array &$saved)
    {
        $this->saved =& $saved;
    }

    public function get(): DbPullCheckpoint
    {
        return DbPullCheckpoint::fresh();
    }

    public function save(DbPullCheckpoint $checkpoint): void
    {
        $this->saved[] = clone $checkpoint;
    }
}

final class DownloaderTestDbPullTimeoutPolicy implements DbPullTimeoutPolicy
{
    public function assert_can_retry(
        DbPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        $checkpoint->consecutive_timeouts++;
    }
}

final class DownloaderTestSqlStreamObserver implements SqlStreamObserver
{
    private $progress_bytes;
    private $completion_progress;

    public function __construct(array &$progress_bytes, array &$completion_progress)
    {
        $this->progress_bytes =& $progress_bytes;
        $this->completion_progress =& $completion_progress;
    }

    public function on_sql_progress(int $sql_bytes_written): void
    {
        $this->progress_bytes[] = $sql_bytes_written;
    }

    public function on_progress_chunk(array $chunk, string $phase): void
    {
    }

    public function on_error_chunk(array $chunk, string $phase, StreamingContext $context): void
    {
    }

    public function on_completion_progress(array $progress): void
    {
        $this->completion_progress[] = $progress;
    }

    public function on_stdout_write_failed(): void
    {
    }
}
