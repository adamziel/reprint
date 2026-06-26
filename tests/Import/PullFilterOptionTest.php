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

class PullRetryFakeClient extends PullFilterFakeClient
{
    private bool $partial_files_once;
    private bool $partial_db_once;

    public function __construct(
        string $state_dir,
        string $fs_root,
        bool $partial_files_once,
        bool $partial_db_once
    ) {
        $this->partial_files_once = $partial_files_once;
        $this->partial_db_once = $partial_db_once;
        parent::__construct($state_dir, $fs_root, false);
    }

    public function run_files_sync(): void
    {
        ++$this->files_sync_calls;
        $this->call_order[] = 'files-download';
        if ($this->partial_files_once && $this->files_sync_calls === 1) {
            $this->mutate_state(function (array $state) {
                $state["command"] = "files-download";
                $state["status"] = "partial";
                $state["stage"] = "fetch";
                return $state;
            });
            return;
        }

        @unlink($this->state_dir . '/.import-download-list-skipped.jsonl');
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
        if ($this->partial_db_once && $this->db_sync_calls === 1) {
            $this->mutate_state(function (array $state) {
                $state["command"] = "db-download";
                $state["status"] = "partial";
                $state["stage"] = "sql";
                return $state;
            });
            return;
        }

        file_put_contents($this->state_dir . '/db.sql', "SELECT 1;\n");
        $this->mutate_state(function (array $state) {
            $state["command"] = "db-download";
            $state["status"] = "complete";
            $state["stage"] = null;
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

    public function testPullFilesCommandUsesPullFileStage(): void
    {
        $client = $this->makeClient(true);

        ob_start();
        $client->run([
            "command" => "pull-files",
            "filter" => "essential-files",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame('essential-files', $state["pull"]["files_filter"]);
        $this->assertTrue($state["pull"]["skipped_pending"]);
        $this->assertSame('essential-files', $state["filter"]);
        $this->assertSame(1, $client->preflight_calls);
    }

    public function testPullFilesRunsOnlyPreflightAndFilesStages(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull-files",
        ]);
        ob_end_clean();

        $this->assertSame(1, $client->preflight_calls);
        $this->assertSame(1, $client->prepare_files_download_options_calls);
        $this->assertSame(1, $client->files_sync_calls);
        $this->assertSame(0, $client->db_sync_calls);
        $this->assertSame(0, $client->db_apply_calls);
        $this->assertSame(array('preflight', 'prepare-files', 'files-download'), $client->call_order);
    }

    public function testPullDbRunsOnlyPreflightAndDatabaseDownloadStages(): void
    {
        $client = $this->makeClient(false);

        ob_start();
        $client->run([
            "command" => "pull-db",
        ]);
        ob_end_clean();

        $this->assertSame(1, $client->preflight_calls);
        $this->assertSame(0, $client->prepare_files_download_options_calls);
        $this->assertSame(0, $client->files_sync_calls);
        $this->assertSame(1, $client->db_sync_calls);
        $this->assertSame(0, $client->db_apply_calls);
        $this->assertSame(array('preflight', 'db-download'), $client->call_order);
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

    public function testPullFilesStopsWhenPreflightFails(): void
    {
        $client = $this->makePreflightFailureClient();

        ob_start();
        $client->run([
            "command" => "pull-files",
        ]);
        ob_end_clean();

        $this->assertSame(1, $client->exit_code);
        $this->assertSame(1, $client->preflight_calls);
        $this->assertSame(0, $client->prepare_files_download_options_calls);
        $this->assertSame(0, $client->files_sync_calls);
        $this->assertSame(0, $client->db_sync_calls);
        $this->assertSame(array('preflight'), $client->call_order);
        $this->assertSame('pull-files', $this->lastProgressEvent($client, 'error')['command']);
    }

    public function testPullDbStopsWhenPreflightFails(): void
    {
        $client = $this->makePreflightFailureClient();

        ob_start();
        $client->run([
            "command" => "pull-db",
        ]);
        ob_end_clean();

        $this->assertSame(1, $client->exit_code);
        $this->assertSame(1, $client->preflight_calls);
        $this->assertSame(0, $client->files_sync_calls);
        $this->assertSame(0, $client->db_sync_calls);
        $this->assertSame(array('preflight'), $client->call_order);
        $this->assertSame('pull-db', $this->lastProgressEvent($client, 'error')['command']);
    }

    public function testPullFilesCommandRetriesPartialFilesDownload(): void
    {
        $this->writePreflightState();
        $client = new PullRetryFakeClient($this->stateDir, $this->fs_root, true, false);

        ob_start();
        $client->run([
            "command" => "pull-files",
            "filter" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(2, $client->files_sync_calls);
        $this->assertSame('complete', $state["status"]);
        $this->assertSame('none', $state["pull"]["files_filter"]);
        $this->assertSame(0, $client->exit_code);
    }

    public function testPullDbCommandRetriesPartialDbDownload(): void
    {
        $client = new PullRetryFakeClient($this->stateDir, $this->fs_root, false, true);

        ob_start();
        $client->run([
            "command" => "pull-db",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $this->assertSame(2, $client->db_sync_calls);
        $this->assertSame('complete', $state["status"]);
        $this->assertFileExists($this->stateDir . '/db.sql');
        $this->assertSame(0, $client->exit_code);
        $this->assertSame(1, $client->preflight_calls);
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
}
