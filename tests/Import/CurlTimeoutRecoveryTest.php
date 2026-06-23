<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Application\UseCase\DbPullHandler;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\FetchCheckpoint;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\Application\Importer;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Session\StatePathCodec;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\SqlStreamClient;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify that cURL timeouts during download save state and set status to
 * "partial" instead of crashing with a fatal RuntimeException.
 *
 * Each download method (download_sql, download_file_fetch, download_remote_index,
 * download_db_index) is tested by injecting a CurlTimeoutException via a
 * subclass that overrides fetch_streaming.
 *
 * Also verifies the consecutive-timeout safety net: after
 * MAX_CONSECUTIVE_TIMEOUTS with no cursor progress, the importer gives up
 * with a RuntimeException instead of retrying forever.
 */
class CurlTimeoutRecoveryTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/curl-timeout-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fs_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fs_root, 0755, true);
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
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function writeState(array $state): void
    {
        $defaults = [
            "command" => null,
            "status" => null,
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
            "max_allowed_packet" => null,
        ];
        $dir = $this->stateDir . '/.reprint';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $run_state = array_merge($defaults, $state);

        if (($run_state["command"] ?? null) === "files-pull") {
            $this->writeFilesPullCheckpoint($this->filesPullCheckpointFromState($run_state));
        }

        unset($run_state["cursor"], $run_state["stage"]);
        file_put_contents(
            $this->stateDir . '/.reprint/run.json',
            json_encode($run_state, JSON_PRETTY_PRINT),
        );
    }

    private function filesPullCheckpointFromState(array $state): FilesPullCheckpoint
    {
        $diff = is_array($state["diff"] ?? null)
            ? $state["diff"]
            : ["remote_offset" => 0, "local_after" => null];

        return new FilesPullCheckpoint(
            isset($state["status"]) && is_string($state["status"]) ? $state["status"] : null,
            isset($state["stage"]) && is_string($state["stage"]) ? $state["stage"] : null,
            isset($state["index"]["cursor"]) && is_string($state["index"]["cursor"])
                ? $state["index"]["cursor"]
                : null,
            (int) ($diff["remote_offset"] ?? 0),
            isset($diff["local_after"]) && is_string($diff["local_after"])
                ? $diff["local_after"]
                : null,
            FetchCheckpoint::from_array(is_array($state["fetch"] ?? null) ? $state["fetch"] : []),
            FetchCheckpoint::from_array(
                is_array($state["fetch_skipped"] ?? null) ? $state["fetch_skipped"] : [],
            ),
            isset($state["current_file"]) && is_string($state["current_file"])
                ? $state["current_file"]
                : null,
            isset($state["current_file_bytes"]) ? (int) $state["current_file_bytes"] : null,
            (int) ($state["consecutive_timeouts"] ?? 0),
        );
    }

    private function writeFilesPullCheckpoint(FilesPullCheckpoint $checkpoint): void
    {
        $dir = $this->stateDir . '/.reprint/files-pull';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $codec = new StatePathCodec();
        file_put_contents(
            $dir . '/checkpoint.json',
            json_encode(
                $checkpoint->to_persisted_array([$codec, 'encode_value']),
                JSON_PRETTY_PRINT,
            ),
        );
    }

    private function writeDbPullCheckpoint(array $checkpoint): void
    {
        $dir = $this->stateDir . '/.reprint/db-pull';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/checkpoint.json',
            json_encode($checkpoint, JSON_PRETTY_PRINT),
        );
    }

    private function readState(): array
    {
        $contents = file_get_contents($this->stateDir . '/.reprint/run.json');
        return json_decode($contents, true);
    }

    private function readDbPullCheckpoint(): array
    {
        $contents = file_get_contents($this->stateDir . '/.reprint/db-pull/checkpoint.json');
        return json_decode($contents, true);
    }

    private function readFilesPullCheckpoint(): FilesPullCheckpoint
    {
        $contents = file_get_contents($this->stateDir . '/.reprint/files-pull/checkpoint.json');
        $data = json_decode($contents, true);
        $codec = new StatePathCodec();

        return FilesPullCheckpoint::from_persisted_array(
            is_array($data) ? $data : [],
            [$codec, 'decode_value'],
        );
    }

    public static function fileCursorForBytes(int $bytes): string
    {
        return base64_encode(json_encode([
            "phase" => "streaming",
            "root" => base64_encode('/srv/htdocs'),
            "path" => base64_encode('/uploads/large.bin'),
            "ctime" => 1234567890,
            "bytes" => $bytes,
        ]));
    }

    private static function fileCursorBytes(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $json = base64_decode($cursor, true);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded["bytes"])) {
            return null;
        }
        return (int) $decoded["bytes"];
    }

    /**
     * Prepare the application context and inject transport test doubles through
     * the service boundary instead of subclassing the importer shell.
     */
    private function prepareClient(
        ?FileSyncStreamClient $file_sync_stream = null,
        ?SqlStreamClient $sql_stream = null
    ): array
    {
        $client = new Importer(
            'http://fake.url',
            $this->stateDir,
            $this->fs_root,
            new BufferedImportOutput(),
        );
        $context = $client->context();
        $context->state();
        $file_sync_stream = $file_sync_stream ?? new TimeoutTestStreamClient();
        if ($sql_stream === null) {
            if (!$file_sync_stream instanceof SqlStreamClient) {
                throw new \LogicException('The default SQL stream must implement SqlStreamClient.');
            }
            $sql_stream = $file_sync_stream;
        }

        return [
            $client,
            new ImportServices($context, $file_sync_stream, $sql_stream),
        ];
    }

    // ---------------------------------------------------------------
    // download_sql: timeout saves state as "partial"
    // ---------------------------------------------------------------

    public function testSqlDownloadTimeoutSavesPartialState()
    {
        $this->writeState([
            "command" => "db-pull",
            "status" => "in_progress",
        ]);
        $this->writeDbPullCheckpoint([
            "status" => "in_progress",
            "stage" => "sql",
            "cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            "sql_bytes" => 1024,
            "sql_statements_counted" => 0,
            "consecutive_timeouts" => 0,
        ]);

        $sql_content = str_pad("", 1024, "INSERT INTO t VALUES (1);\n");
        file_put_contents($this->stateDir . '/db.sql', $sql_content);

        [$client, $services] = $this->prepareClient();
        $client->context()->set_sql_output_mode('file');

        $checkpoint = $services->download_sql(
            DbPullCheckpoint::from_array($this->readDbPullCheckpoint()),
        );

        $this->assertEquals(
            "partial",
            $checkpoint->status,
            "After cURL timeout, status should be 'partial' not an exception"
        );
        $this->assertNotNull(
            $checkpoint->cursor,
            "Cursor should be preserved for resumption"
        );
        $this->assertNotNull(
            $checkpoint->sql_bytes,
            "sql_bytes should be saved for crash recovery"
        );
    }

    // ---------------------------------------------------------------
    // download_file_fetch: timeout saves state and returns false
    // ---------------------------------------------------------------

    public function testFileFetchTimeoutSavesPartialState()
    {
        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => [
                "offset" => 0,
                "next_offset" => 100,
                "batch_file" => null,
                "cursor" => base64_encode('{"path":"/wp-content/uploads/photo.jpg","offset":4096}'),
            ],
        ]);

        [$client, $services] = $this->prepareClient();

        $result = $services->download_file_fetch(
            $client->context()->files_pull_checkpoint(),
            null,
            base64_encode('{"path":"/photo.jpg","offset":4096}'),
            "fetch",
        );

        $this->assertFalse(
            $result,
            "download_file_fetch should return false (not complete) on timeout"
        );

        $checkpoint = $this->readFilesPullCheckpoint();
        $this->assertEquals(
            "partial",
            $checkpoint->status,
            "After cURL timeout during file fetch, status should be 'partial'"
        );
    }

    public function testFileFetchHardCrashCheckpointDoesNotPutCursorBehindBytes()
    {
        $trackedPath = $this->fs_root . '/uploads/large.bin';
        mkdir(dirname($trackedPath), 0755, true);
        file_put_contents($trackedPath, str_repeat('a', 256));

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => [
                "offset" => 0,
                "next_offset" => 100,
                "batch_file" => null,
                "cursor" => self::fileCursorForBytes(256),
            ],
            "current_file" => $trackedPath,
            "current_file_bytes" => 256,
        ]);

        [$client, $services] = $this->prepareClient(
            new InterruptedAfterStreamedPartCloseStreamClient(),
        );

        try {
            $services->download_file_fetch(
                $client->context()->files_pull_checkpoint(),
                null,
                self::fileCursorForBytes(256),
                "fetch",
            );
            $this->fail('Expected simulated hard crash during file fetch');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Simulated hard crash after streamed file part close',
                $e->getMessage(),
            );
        }

        $checkpoint = $this->readFilesPullCheckpoint();
        $savedBytes = $checkpoint->current_file_bytes;
        $savedCursorBytes = self::fileCursorBytes(
            $checkpoint->fetch->cursor,
        );

        $this->assertNotNull(
            $savedBytes,
            'The state should retain a crash-recovery file byte count',
        );
        $this->assertSame(
            $savedBytes,
            $savedCursorBytes,
            'A hard-crash checkpoint must not put the saved cursor behind the bytes retained on disk',
        );
    }

    // ---------------------------------------------------------------
    // download_remote_index: timeout saves state and returns false
    // ---------------------------------------------------------------

    public function testRemoteIndexTimeoutSavesPartialState()
    {
        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "index",
            "index" => [
                "cursor" => base64_encode('{"dir":"/wp-content","offset":500}'),
            ],
            "preflight" => [
                "data" => [
                    "ok" => true,
                    "wp_detect" => [
                        "roots" => [
                            ["path" => "/srv/htdocs"],
                        ],
                    ],
                ],
                "http_code" => 200,
            ],
        ]);

        [$client, $services] = $this->prepareClient();

        $result = $services->download_remote_index(
            $client->context()->files_pull_checkpoint(),
        );

        $this->assertFalse(
            $result,
            "download_remote_index should return false on timeout"
        );

        $checkpoint = $this->readFilesPullCheckpoint();
        $this->assertEquals(
            "partial",
            $checkpoint->status,
            "After cURL timeout during index download, status should be 'partial'"
        );
        $this->assertNotNull(
            $checkpoint->index_cursor,
            "Index cursor should be preserved for resumption"
        );
    }

    // ---------------------------------------------------------------
    // download_db_index: timeout saves state as "partial"
    // ---------------------------------------------------------------

    public function testDbIndexTimeoutSavesPartialState()
    {
        $this->writeState([
            "command" => "db-pull",
            "status" => "in_progress",
        ]);
        $this->writeDbPullCheckpoint([
            "status" => "in_progress",
            "stage" => "db-index",
            "cursor" => base64_encode('{"table_offset":5}'),
            "db_index" => [
                "file" => null,
                "tables" => 3,
                "rows_estimated" => 1000,
                "bytes" => 256,
                "updated_at" => time(),
            ],
            "sql_bytes" => null,
            "sql_statements_counted" => 0,
            "consecutive_timeouts" => 0,
        ]);

        [$client, $services] = $this->prepareClient();

        (new DbPullHandler())->execute($client->context(), $services, []);

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["status"],
            "After cURL timeout during db-index, status should be 'partial'"
        );
    }

    // ---------------------------------------------------------------
    // run_db_sync: timeout propagates as "partial", not exception
    // ---------------------------------------------------------------

    public function testRunDbSyncExitsPartialOnSqlTimeout()
    {
        $this->writeState([
            "command" => "db-pull",
            "status" => "in_progress",
        ]);
        $this->writeDbPullCheckpoint([
            "status" => "in_progress",
            "stage" => "sql",
            "cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            "sql_bytes" => 0,
            "db_index" => [
                "file" => $this->stateDir . "/db-tables.jsonl",
                "tables" => 5,
                "rows_estimated" => 10000,
                "bytes" => 100,
                "updated_at" => time(),
            ],
            "sql_statements_counted" => 0,
            "consecutive_timeouts" => 0,
        ]);

        [$client, $services] = $this->prepareClient();
        $client->context()->set_sql_output_mode('file');

        // run_db_sync should NOT throw — it should return with partial status
        (new DbPullHandler())->execute($client->context(), $services, []);

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["status"],
            "run_db_sync should set status to 'partial' on timeout, not throw"
        );
    }

    // ---------------------------------------------------------------
    // Exception hierarchy
    // ---------------------------------------------------------------

    public function testCurlTimeoutExceptionExtendsRuntimeException()
    {
        $e = new CurlTimeoutException("Operation timed out");
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /**
     * End-to-end: download_sql with counter already at MAX-1 and no
     * cursor progress should throw RuntimeException.
     */
    public function testSqlDownloadGivesUpAfterMaxConsecutiveTimeouts()
    {
        $this->writeState([
            "command" => "db-pull",
            "status" => "in_progress",
        ]);
        $this->writeDbPullCheckpoint([
            "status" => "in_progress",
            "stage" => "sql",
            "cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            "sql_bytes" => 1024,
            "consecutive_timeouts" => 2,
            "sql_statements_counted" => 0,
        ]);

        $sql_content = str_pad("", 1024, "INSERT INTO t VALUES (1);\n");
        file_put_contents($this->stateDir . '/db.sql', $sql_content);

        [$client, $services] = $this->prepareClient();
        $client->context()->set_sql_output_mode('file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive');

        $services->download_sql(DbPullCheckpoint::from_array($this->readDbPullCheckpoint()));
    }

    public function testFirstTimeoutIncrementsDbPullCheckpointCounter()
    {
        $this->writeState([
            "command" => "db-pull",
            "status" => "in_progress",
        ]);
        $this->writeDbPullCheckpoint([
            "status" => "in_progress",
            "stage" => "sql",
            "cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            "sql_bytes" => 1024,
            "consecutive_timeouts" => 0,
            "sql_statements_counted" => 0,
        ]);

        $sql_content = str_pad("", 1024, "INSERT INTO t VALUES (1);\n");
        file_put_contents($this->stateDir . '/db.sql', $sql_content);

        [$client, $services] = $this->prepareClient();
        $client->context()->set_sql_output_mode('file');

        $checkpoint = $services->download_sql(
            DbPullCheckpoint::from_array($this->readDbPullCheckpoint()),
        );

        $this->assertEquals("partial", $checkpoint->status);
        $this->assertEquals(
            1,
            $checkpoint->consecutive_timeouts,
            "First no-progress timeout should increment counter to 1"
        );
    }

    public function testSuccessfulRequestResetsCounter()
    {
        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "index",
            "index" => [
                "cursor" => base64_encode('{"dir":"/wp-content","offset":500}'),
            ],
            "consecutive_timeouts" => 2,
            "preflight" => [
                "data" => [
                    "ok" => true,
                    "wp_detect" => [
                        "roots" => [
                            ["path" => "/srv/htdocs"],
                        ],
                    ],
                ],
                "http_code" => 200,
            ],
        ]);

        [$client, $services] = $this->prepareClient(new SuccessTestStreamClient());

        $services->download_remote_index($client->context()->files_pull_checkpoint());

        $checkpoint = $this->readFilesPullCheckpoint();
        $this->assertEquals(
            0,
            $checkpoint->consecutive_timeouts,
            "Successful request should reset consecutive_timeouts to 0"
        );
    }

}

/**
 * Test double that throws CurlTimeoutException from fetch_streaming,
 * simulating a cURL timeout without making real HTTP requests.
 * The cursor is NOT advanced — simulates a complete stall.
 */
abstract class CurlTimeoutRecoveryTestStreamClient implements FileSyncStreamClient, SqlStreamClient
{
    public function build_url(string $endpoint, ?string $cursor, array $params): string
    {
        return 'http://fake.url/?endpoint=' . rawurlencode($endpoint);
    }

    public function tuned_params(string $endpoint): array
    {
        return [];
    }

    public function finalize_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
    }
}

/**
 * Test double that simulates a process dying immediately after a streamed
 * file part-complete checkpoint. This is a hard crash, so download_file_fetch()
 * must not get a chance to do its normal final save.
 */
class InterruptedAfterStreamedPartCloseStreamClient extends CurlTimeoutRecoveryTestStreamClient
{
    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        $headers = [
            "x-chunk-type" => "file",
            "x-cursor" => CurlTimeoutRecoveryTest::fileCursorForBytes(512),
            "x-file-path" => base64_encode('/uploads/large.bin'),
            "x-file-size" => "1024",
            "x-file-ctime" => "1234567890",
            "x-chunk-offset" => "256",
            "x-chunk-size" => "256",
            "x-first-chunk" => "0",
            "x-last-chunk" => "0",
        ];

        ($context->on_chunk)([
            "headers" => $headers,
            "body" => str_repeat('b', 256),
            "is_streaming_body" => true,
        ]);
        ($context->on_chunk)([
            "headers" => $headers,
            "body" => "",
            "is_streaming_close" => true,
        ]);

        throw new \RuntimeException(
            'Simulated hard crash after streamed file part close',
        );
    }
}

/**
 * Test double that completes successfully without throwing,
 * simulating a normal request that finishes.
 */
class TimeoutTestStreamClient extends CurlTimeoutRecoveryTestStreamClient
{
    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        throw new CurlTimeoutException(
            "cURL error: Operation timed out after 300001 milliseconds with 0 bytes received"
        );
    }
}

class SuccessTestStreamClient extends CurlTimeoutRecoveryTestStreamClient
{
    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        // Signal completion
        $context->saw_completion = true;
    }
}
