<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class PullFilterFakeClient extends \ImportClient
{
    private bool $create_skipped_list;
    public int $preflight_calls = 0;
    public int $prepare_files_download_options_calls = 0;
    public int $files_sync_calls = 0;
    public int $db_sync_calls = 0;
    public int $db_apply_calls = 0;
    public ?array $db_apply_options = null;
    public array $call_order = array();
    public array $progress_events = array();

    public function __construct(string $state_dir, string $fs_root, bool $create_skipped_list)
    {
        $this->create_skipped_list = $create_skipped_list;
        parent::__construct('http://fake.invalid', $state_dir, $fs_root);
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
    }

    public function output_progress(array $data, bool $force = false): void
    {
        $this->progress_events[] = $data;
    }

    public function write_status_file(?string $error = null): void
    {
    }

    public function index_count(): int
    {
        return 12;
    }

    public function run_preflight(): void
    {
        ++$this->preflight_calls;
        $this->call_order[] = 'preflight';
        $this->mutate_state(function (array $state) {
            $state["preflight"] = [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "database" => [
                        "wp" => [
                            "wp_version" => "6.8",
                        ],
                    ],
                    "runtime" => [
                        "phpversion" => "8.2",
                    ],
                ],
            ];
            $state["status"] = "complete";
            return $state;
        });
    }

    public function prepare_files_download_options(array $options): void
    {
        ++$this->prepare_files_download_options_calls;
        $this->call_order[] = 'prepare-files';
        parent::prepare_files_download_options($options);
    }

    public function run_files_sync(): void
    {
        ++$this->files_sync_calls;
        $this->call_order[] = 'files-download';
        if ($this->create_skipped_list) {
            file_put_contents(
                $this->state_dir . '/.import-download-list-skipped.jsonl',
                "{\"path\":\"" . base64_encode('/wp-content/uploads/2024/01/photo.jpg') . "\"}\n",
            );
        } else {
            @unlink($this->state_dir . '/.import-download-list-skipped.jsonl');
        }

        $this->mutate_state(function (array $state) {
            $state["command"] = "files-download";
            $state["status"] = "complete";
            $state["stage"] = null;
            return $state;
        });
    }

    public function run_db_sync(): void
    {
        ++$this->db_sync_calls;
        $this->call_order[] = 'db-download';
        file_put_contents($this->state_dir . '/db.sql', "SELECT 1;\n");
        $this->mutate_state(function (array $state) {
            $state["command"] = "db-download";
            $state["status"] = "complete";
            $state["stage"] = null;
            return $state;
        });
    }

    public function run_db_apply(array $options): void
    {
        ++$this->db_apply_calls;
        $this->db_apply_options = $options;
        $this->call_order[] = 'db-apply';
        $this->mutate_state(function (array $state) {
            $state["command"] = "db-apply";
            $state["status"] = "complete";
            $state["stage"] = null;
            $state["apply"]["statements_executed"] = 42;
            return $state;
        });
    }
}

/**
 * Fake client that records the options the pull pipeline hands to
 * apply-runtime, so we can assert the flatten_to -> flat_document_root
 * bridge. flat-docroot is stubbed to a no-op.
 */
class PullBridgeFakeClient extends PullFilterFakeClient
{
    public ?array $apply_runtime_options = null;

    public function run_flat_document_root(array $options): void
    {
    }

    public function run_apply_runtime(array $options): void
    {
        $this->apply_runtime_options = $options;
    }
}

class PullPreflightFailureFakeClient extends PullFilterFakeClient
{
    public function run_preflight(): void
    {
        ++$this->preflight_calls;
        $this->call_order[] = 'preflight';
        $this->last_error_code = 'NOT_FOUND';
        $this->mutate_state(function (array $state) {
            $state["preflight"] = [
                "http_code" => 404,
                "error" => "Exporter not found",
                "data" => [
                    "ok" => false,
                ],
            ];
            return $state;
        });
    }
}

/**
 * Tests for pull-level file filtering.
 */
class PullFilterOptionTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pull-filter-test-' . uniqid();
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

    private function makeClient(bool $create_skipped_list): PullFilterFakeClient
    {
        return new PullFilterFakeClient($this->stateDir, $this->fs_root, $create_skipped_list);
    }

    private function makePreflightFailureClient(): PullPreflightFailureFakeClient
    {
        return new PullPreflightFailureFakeClient($this->stateDir, $this->fs_root, false);
    }

    private function lastProgressEvent(PullFilterFakeClient $client, string $status): array
    {
        for ($i = count($client->progress_events) - 1; $i >= 0; $i--) {
            if (($client->progress_events[$i]['status'] ?? null) === $status) {
                return $client->progress_events[$i];
            }
        }

        $this->fail("Missing {$status} progress event");
    }

    private function readState(): array
    {
        return json_decode(
            file_get_contents($this->stateDir . '/.import-state.json'),
            true,
        );
    }

    private function writePreflightState(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
                "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
            ]),
        );
    }

    private function writeTransferArtifacts(): void
    {
        foreach ([
            '.import-remote-index.jsonl',
            '.import-download-list.jsonl',
            '.import-download-list-skipped.jsonl',
            'db.sql',
            'db-tables.jsonl',
            '.import-domains.json',
        ] as $file) {
            file_put_contents($this->stateDir . '/' . $file, "stale\n");
        }
    }

    public function testPullRejectsSkippedEarlierFilterBeforePersistingIt(): void
    {
        $client = $this->makeClient(false);

        try {
            ob_start();
            $client->run([
                "command" => "pull",
                "filter" => "skipped-earlier",
                "runtime" => "none",
            ]);
            $this->fail('Expected pull --filter=skipped-earlier to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'Invalid --filter value for pull',
                $e->getMessage(),
            );
        } finally {
            ob_end_clean();
        }

        $this->assertFileDoesNotExist($this->stateDir . '/.import-state.json');
    }

    public function testPullWithEssentialFilesPersistsDeferredFilesState(): void
    {
        $client = $this->makeClient(true);

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('complete', $state["pull"]["stage"]);
        $this->assertSame('essential-files', $state["pull"]["files_filter"]);
        $this->assertTrue($state["pull"]["skipped_pending"]);
        $this->assertTrue($state["pull"]["has_completed_once"]);
        $this->assertSame('essential-files', $state["filter"]);
        $this->assertFileExists($this->stateDir . '/.import-download-list-skipped.jsonl');
    }

    public function testPullWithoutFilterRecordsFullDownloadMode(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('complete', $state["pull"]["stage"]);
        $this->assertSame('none', $state["pull"]["files_filter"]);
        $this->assertFalse($state["pull"]["skipped_pending"]);
        $this->assertTrue($state["pull"]["has_completed_once"]);
        $this->assertSame('none', $state["filter"]);
        $this->assertFileDoesNotExist($this->stateDir . '/.import-download-list-skipped.jsonl');
    }

    public function testPullStopsWhenPreflightFails(): void
    {
        $client = $this->makePreflightFailureClient();

        ob_start();
        $client->run([
            "command" => "pull",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(1, $client->exit_code);
        $this->assertSame(1, $client->preflight_calls);
        $this->assertSame(0, $client->prepare_files_download_options_calls);
        $this->assertSame(0, $client->files_sync_calls);
        $this->assertSame(0, $client->db_sync_calls);
        $this->assertSame(0, $client->db_apply_calls);
        $this->assertSame(array('preflight'), $client->call_order);
        $this->assertSame('pull', $this->lastProgressEvent($client, 'error')['command']);
        $this->assertNull($state["pull"]["stage"] ?? null);
    }

    public function testPullDerivesFlatDocumentRootFromFlattenTo(): void
    {
        $client = new PullBridgeFakeClient($this->stateDir, $this->fs_root, false);
        $flatten_to = $this->tempDir . '/flattened-site';

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "flatten_to" => $flatten_to,
            "runtime" => "playground-cli",
            "start_runtime" => "none",
        ]);
        ob_end_clean();

        // The pull pipeline must hand apply-runtime a flat_document_root
        // derived from --flatten-to, so the generated runtime targets the
        // flattened layout instead of the raw download tree.
        $this->assertIsArray($client->apply_runtime_options);
        $this->assertSame($flatten_to, $client->apply_runtime_options["flat_document_root"]);
    }

    public function testRepullAfterSkippedEarlierTailUsesCompletedFilesDownloadState(): void
    {
        // The deferred "skipped-earlier" tail belongs to a files-download that
        // has finished. Once that lifecycle state is truthful, a completed
        // pull can delta re-pull without bypassing the mid-flight guard.
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode([
                "command" => "files-download",
                "status" => "complete",
                "stage" => null,
                "filter" => "skipped-earlier",
                "pull" => [
                    "stage" => "complete",
                    "files_filter" => "essential-files",
                    "skipped_pending" => true,
                ],
                "preflight" => ["http_code" => 200, "data" => ["ok" => true]],
            ]),
        );

        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull",
            "filter" => "essential-files",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('complete', $state["pull"]["stage"]);
        $this->assertSame('essential-files', $state["filter"]);
    }

    public function testPullDbDefaultsToSqliteApplyTarget(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull-db",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(array('preflight', 'db-download', 'db-apply'), $client->call_order);
        $this->assertSame('complete', $state["pull_db"]["stage"]);
        $this->assertSame('sqlite', $client->db_apply_options["target_engine"] ?? null);
        $this->assertSame('file', $client->db_apply_options["sql_output"] ?? null);
    }

    public function testPullFilesOwnerTransitionResetsFilesDownloadState(): void
    {
        $client = $this->makeClient(false);
        $client->state = $client->default_state();
        $defaults = $client->default_state();
        $client->mutate_state(function (array $state) {
            $state['files_pipeline_owner'] = 'pull';
            $state['command'] = 'files-download';
            $state['status'] = 'complete';
            $state['cursor'] = 'stale-cursor';
            $state['stage'] = 'fetch';
            $state['current_file'] = '/remote/stale.txt';
            $state['current_file_bytes'] = 123;
            $state['diff'] = ['remote_offset' => 99, 'local_after' => 'stale'];
            $state['index'] = ['cursor' => 'stale-index-cursor'];
            $state['fetch'] = [
                'offset' => 10,
                'next_offset' => 20,
                'batch_file' => 'stale-fetch.jsonl',
                'cursor' => 'stale-fetch-cursor',
            ];
            $state['fetch_skipped'] = [
                'offset' => 30,
                'next_offset' => 40,
                'batch_file' => 'stale-skipped.jsonl',
                'cursor' => 'stale-skipped-cursor',
            ];
            return $state;
        });
        $this->writeTransferArtifacts();

        ob_start();
        $client->run([
            'command' => 'pull-files',
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('pull-files', $state['files_pipeline_owner']);
        $this->assertSame('complete', $state['pull_files']['stage']);
        $this->assertNull($state['current_file']);
        $this->assertNull($state['current_file_bytes']);
        $this->assertSame($defaults['diff'], $state['diff']);
        $this->assertSame($defaults['index'], $state['index']);
        $this->assertSame($defaults['fetch'], $state['fetch']);
        $this->assertSame($defaults['fetch_skipped'], $state['fetch_skipped']);
        $this->assertFileDoesNotExist($this->stateDir . '/.import-remote-index.jsonl');
        $this->assertFileDoesNotExist($this->stateDir . '/.import-download-list.jsonl');
        $this->assertFileDoesNotExist($this->stateDir . '/.import-download-list-skipped.jsonl');
    }

    public function testPullDbOwnerTransitionResetsDatabaseDownloadState(): void
    {
        $client = $this->makeClient(false);
        $client->state = $client->default_state();
        $defaults = $client->default_state();
        $client->mutate_state(function (array $state) {
            $state['db_pipeline_owner'] = 'pull';
            $state['command'] = 'db-download';
            $state['status'] = 'complete';
            $state['cursor'] = 'stale-cursor';
            $state['stage'] = 'stream';
            $state['consecutive_timeouts'] = 7;
            $state['sql_bytes'] = 12345;
            $state['db_index'] = [
                'file' => 'stale-db-index.jsonl',
                'tables' => 3,
                'rows_estimated' => 100,
                'bytes' => 1000,
                'updated_at' => '2026-06-27T00:00:00Z',
            ];
            $state['apply'] = [
                'statements_executed' => 4,
                'bytes_read' => 2048,
                'rewrite_url' => 'https://stale.example',
                'target_engine' => 'mysql',
                'target_db' => 'stale',
                'target_host' => '127.0.0.1',
                'target_port' => 3306,
                'target_user' => 'root',
                'target_pass' => 'secret',
                'target_sqlite_path' => null,
                'remote_paths_removed_from_local_site' => ['/old'],
            ];
            return $state;
        });
        $this->writeTransferArtifacts();

        ob_start();
        $client->run([
            'command' => 'pull-db',
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('pull-db', $state['db_pipeline_owner']);
        $this->assertSame('complete', $state['pull_db']['stage']);
        $this->assertSame(0, $state['consecutive_timeouts']);
        $this->assertNull($state['sql_bytes']);
        $this->assertSame($defaults['db_index'], $state['db_index']);
        $this->assertSame(42, $state['apply']['statements_executed']);
        $this->assertSame('sqlite', $client->db_apply_options['target_engine'] ?? null);
        $this->assertFileExists($this->stateDir . '/db.sql');
        $this->assertFileDoesNotExist($this->stateDir . '/db-tables.jsonl');
        $this->assertFileDoesNotExist($this->stateDir . '/.import-domains.json');
    }

    public function testCompletedPullRerunResetsFilesAndDatabaseTransferState(): void
    {
        $client = $this->makeClient(false);
        $client->state = $client->default_state();
        $defaults = $client->default_state();
        $client->mutate_state(function (array $state) {
            $state['pull'] = [
                'stage' => 'complete',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
                'has_completed_once' => true,
            ];
            $state['command'] = 'db-apply';
            $state['status'] = 'complete';
            $state['cursor'] = 'stale-cursor';
            $state['stage'] = 'apply';
            $state['current_file'] = '/remote/stale.txt';
            $state['current_file_bytes'] = 123;
            $state['diff'] = ['remote_offset' => 99, 'local_after' => 'stale'];
            $state['index'] = ['cursor' => 'stale-index-cursor'];
            $state['fetch'] = [
                'offset' => 10,
                'next_offset' => 20,
                'batch_file' => 'stale-fetch.jsonl',
                'cursor' => 'stale-fetch-cursor',
            ];
            $state['fetch_skipped'] = [
                'offset' => 30,
                'next_offset' => 40,
                'batch_file' => 'stale-skipped.jsonl',
                'cursor' => 'stale-skipped-cursor',
            ];
            $state['consecutive_timeouts'] = 7;
            $state['sql_bytes'] = 12345;
            $state['db_index'] = [
                'file' => 'stale-db-index.jsonl',
                'tables' => 3,
                'rows_estimated' => 100,
                'bytes' => 1000,
                'updated_at' => '2026-06-27T00:00:00Z',
            ];
            $state['apply'] = [
                'statements_executed' => 4,
                'bytes_read' => 2048,
                'rewrite_url' => 'https://stale.example',
                'target_engine' => 'mysql',
                'target_db' => 'stale',
                'target_host' => '127.0.0.1',
                'target_port' => 3306,
                'target_user' => 'root',
                'target_pass' => 'secret',
                'target_sqlite_path' => null,
                'remote_paths_removed_from_local_site' => ['/old'],
            ];
            return $state;
        });
        $this->writeTransferArtifacts();

        ob_start();
        $client->run([
            'command' => 'pull',
            'runtime' => 'none',
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('complete', $state['pull']['stage']);
        $this->assertSame('none', $state['pull']['files_filter']);
        $this->assertFalse($state['pull']['skipped_pending']);
        $this->assertTrue($state['pull']['has_completed_once']);
        $this->assertNull($state['current_file']);
        $this->assertNull($state['current_file_bytes']);
        $this->assertSame($defaults['diff'], $state['diff']);
        $this->assertSame($defaults['index'], $state['index']);
        $this->assertSame($defaults['fetch'], $state['fetch']);
        $this->assertSame($defaults['fetch_skipped'], $state['fetch_skipped']);
        $this->assertSame(0, $state['consecutive_timeouts']);
        $this->assertNull($state['sql_bytes']);
        $this->assertSame($defaults['db_index'], $state['db_index']);
        $this->assertSame(42, $state['apply']['statements_executed']);
        $this->assertFileDoesNotExist($this->stateDir . '/.import-remote-index.jsonl');
        $this->assertFileDoesNotExist($this->stateDir . '/.import-download-list.jsonl');
        $this->assertFileDoesNotExist($this->stateDir . '/.import-download-list-skipped.jsonl');
        $this->assertFileExists($this->stateDir . '/db.sql');
        $this->assertFileDoesNotExist($this->stateDir . '/db-tables.jsonl');
        $this->assertFileDoesNotExist($this->stateDir . '/.import-domains.json');
    }

    public function testPullDbRejectsStdoutSqlOutputBeforeDownloading(): void
    {
        $client = $this->makeClient(false);

        try {
            ob_start();
            $client->run([
                "command" => "pull-db",
                "sql_output" => "stdout",
            ]);
            $this->fail('Expected pull-db --sql-output=stdout to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'Use db-download directly for --sql-output=stdout',
                $e->getMessage(),
            );
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, $client->preflight_calls);
        $this->assertSame(0, $client->db_sync_calls);
        $this->assertFileDoesNotExist($this->stateDir . '/db.sql');
    }
}
