<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify that cURL timeouts during download save state and set status to
 * "partial" instead of crashing with a fatal RuntimeException.
 *
 * Each download method (download_sql, download_file_fetch, download_remote_index,
 * download_db_index) is tested by injecting a CurlTimeoutException via a
 * subclass that overrides fetch_streaming.
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
            "cursor" => null,
            "stage" => null,
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
            "max_allowed_packet" => null,
        ];
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function readState(): array
    {
        $contents = file_get_contents($this->stateDir . '/.import-state.json');
        return json_decode($contents, true);
    }

    /**
     * Prepare a TimeoutTestClient with state loaded and TTY disabled.
     */
    private function prepareClient(): array
    {
        $client = new TimeoutTestClient(
            'http://fake.url',
            $this->stateDir,
            $this->fs_root,
        );
        $reflection = new \ReflectionClass(\ImportClient::class);

        $stateProperty = $reflection->getProperty('state');
        $loadState = $reflection->getMethod('load_state');
        $stateProperty->setValue($client, $loadState->invoke($client));

        $ttyProperty = $reflection->getProperty('is_tty');
        $ttyProperty->setValue($client, false);

        return [$client, $reflection];
    }

    // ---------------------------------------------------------------
    // download_sql: timeout saves state as "partial"
    // ---------------------------------------------------------------

    public function testSqlDownloadTimeoutSavesPartialState()
    {
        $this->writeState([
            "command" => "db-sync",
            "status" => "in_progress",
            "stage" => "sql",
            "cursor" => base64_encode('{"table":"wp_posts","pk":42}'),
            "sql_bytes" => 1024,
        ]);

        $sql_content = str_pad("", 1024, "INSERT INTO t VALUES (1);\n");
        file_put_contents($this->stateDir . '/db.sql', $sql_content);

        [$client, $reflection] = $this->prepareClient();

        $modeProp = $reflection->getProperty('sql_output_mode');
        $modeProp->setValue($client, 'file');

        $downloadSql = $reflection->getMethod('download_sql');
        $downloadSql->invoke($client);

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["status"],
            "After cURL timeout, status should be 'partial' not an exception"
        );
        $this->assertNotNull(
            $state["cursor"],
            "Cursor should be preserved for resumption"
        );
        $this->assertNotNull(
            $state["sql_bytes"],
            "sql_bytes should be saved for crash recovery"
        );
    }

    // ---------------------------------------------------------------
    // download_file_fetch: timeout saves state and returns false
    // ---------------------------------------------------------------

    public function testFileFetchTimeoutSavesPartialState()
    {
        $this->writeState([
            "command" => "files-sync",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => [
                "offset" => 0,
                "next_offset" => 100,
                "batch_file" => null,
                "cursor" => base64_encode('{"path":"/wp-content/uploads/photo.jpg","offset":4096}'),
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();

        $downloadFilesFetch = $reflection->getMethod('download_file_fetch');
        $result = $downloadFilesFetch->invoke(
            $client,
            null,
            base64_encode('{"path":"/photo.jpg","offset":4096}'),
            "fetch",
        );

        $this->assertFalse(
            $result,
            "download_file_fetch should return false (not complete) on timeout"
        );

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["status"],
            "After cURL timeout during file fetch, status should be 'partial'"
        );
    }

    // ---------------------------------------------------------------
    // download_remote_index: timeout saves state and returns false
    // ---------------------------------------------------------------

    public function testRemoteIndexTimeoutSavesPartialState()
    {
        $this->writeState([
            "command" => "files-sync",
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

        [$client, $reflection] = $this->prepareClient();

        $downloadIndex = $reflection->getMethod('download_remote_index');
        $result = $downloadIndex->invoke($client);

        $this->assertFalse(
            $result,
            "download_remote_index should return false on timeout"
        );

        $state = $this->readState();
        $this->assertEquals(
            "partial",
            $state["status"],
            "After cURL timeout during index download, status should be 'partial'"
        );
        $this->assertNotNull(
            $state["index"]["cursor"] ?? null,
            "Index cursor should be preserved for resumption"
        );
    }

    // ---------------------------------------------------------------
    // download_db_index: timeout saves state as "partial"
    // ---------------------------------------------------------------

    public function testDbIndexTimeoutSavesPartialState()
    {
        $this->writeState([
            "command" => "db-sync",
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
        ]);

        [$client, $reflection] = $this->prepareClient();

        $downloadDbIndex = $reflection->getMethod('download_db_index');
        $downloadDbIndex->invoke($client);

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
            "command" => "db-sync",
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
        ]);

        [$client, $reflection] = $this->prepareClient();

        $modeProp = $reflection->getProperty('sql_output_mode');
        $modeProp->setValue($client, 'file');

        // run_db_sync should NOT throw — it should return with partial status
        $runDbSync = $reflection->getMethod('run_db_sync');
        $runDbSync->invoke($client);

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
        $e = new \CurlTimeoutException("Operation timed out");
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}

/**
 * Test double that throws CurlTimeoutException from fetch_streaming,
 * simulating a cURL timeout without making real HTTP requests.
 */
class TimeoutTestClient extends \ImportClient
{
    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        \StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        throw new \CurlTimeoutException(
            "cURL error: Operation timed out after 300001 milliseconds with 0 bytes received"
        );
    }
}
