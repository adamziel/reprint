<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Pull\Pull;
use Reprint\Importer\Pull\PullCheckpoint;
use Reprint\Importer\Pull\PullRuntime;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\TerminalProgress\TerminalProgress;

require_once __DIR__ . '/../../importer/import.php';

class PullFilterFakeRuntime implements PullRuntime
{
    private bool $create_skipped_list;
    private ImportPaths $paths;
    private string $fsRoot;
    private ?PullCheckpoint $checkpoint = null;
    private ?string $status = null;
    private string $filter = 'none';
    private ?array $preflight = null;

    public function __construct(string $state_dir, string $fs_root, bool $create_skipped_list)
    {
        $this->create_skipped_list = $create_skipped_list;
        $this->paths = new ImportPaths($state_dir);
        $this->fsRoot = $fs_root;
    }

    public function ensure_site_export_api_url(): void
    {
    }

    public function remote_host(): string
    {
        return 'fake.invalid';
    }

    public function output_progress(array $data, bool $force = false): void
    {
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
    }

    public function paths(): ImportPaths
    {
        return $this->paths;
    }

    public function prepare_repull_run_state(): void
    {
        $this->status = 'in_progress';
        $this->write_state();
    }

    public function delete_pull_checkpoint(): void
    {
        $this->checkpoint = null;
        @unlink($this->paths->pull_checkpoint_file());
    }

    public function pull_checkpoint(): PullCheckpoint
    {
        if ($this->checkpoint instanceof PullCheckpoint) {
            return $this->checkpoint;
        }
        $data = file_exists($this->paths->pull_checkpoint_file())
            ? json_decode(file_get_contents($this->paths->pull_checkpoint_file()), true)
            : [];
        $this->checkpoint = PullCheckpoint::from_array(is_array($data) ? $data : []);
        return $this->checkpoint;
    }

    public function save_pull_checkpoint(PullCheckpoint $checkpoint): void
    {
        $this->checkpoint = $checkpoint;
        if ($checkpoint->files_filter !== null) {
            $this->filter = $checkpoint->files_filter;
        }
        if (!is_dir(dirname($this->paths->pull_checkpoint_file()))) {
            mkdir(dirname($this->paths->pull_checkpoint_file()), 0755, true);
        }
        file_put_contents(
            $this->paths->pull_checkpoint_file(),
            json_encode($checkpoint->to_array(), JSON_PRETTY_PRINT),
        );
        $this->write_state();
    }

    public function current_run_status(): ?string
    {
        return $this->status;
    }

    public function set_run_status(?string $status): void
    {
        $this->status = $status;
        $this->write_state();
    }

    public function set_exit_code(int $exit_code): void
    {
    }

    public function last_error_code(): ?string
    {
        return null;
    }

    public function preflight_entry(): ?array
    {
        return $this->preflight;
    }

    public function preflight_data(): ?array
    {
        return $this->preflight['data'] ?? null;
    }

    public function default_runtime_output_dir(): string
    {
        return $this->paths->state_dir() . '/runtime';
    }

    public function current_filter(): string
    {
        return $this->filter;
    }

    public function has_skipped_files_pending(): bool
    {
        return file_exists($this->paths->skipped_download_list_file()) &&
            filesize($this->paths->skipped_download_list_file()) > 0;
    }

    public function index_count(): int
    {
        return 12;
    }

    public function db_apply_checkpoint(): DbApplyCheckpoint
    {
        return DbApplyCheckpoint::fresh();
    }

    public function run_preflight(): void
    {
        $this->preflight = [
            'http_code' => 200,
            'data' => [
                'ok' => true,
                'database' => ['wp' => ['wp_version' => '6.8']],
                'runtime' => ['phpversion' => '8.2'],
            ],
        ];
        $this->set_run_status('complete');
    }

    public function run_files_sync(): void
    {
        if ($this->create_skipped_list) {
            file_put_contents(
                $this->paths->skipped_download_list_file(),
                "{\"path\":\"" . base64_encode('/wp-content/uploads/2024/01/photo.jpg') . "\"}\n",
            );
        } else {
            @unlink($this->paths->skipped_download_list_file());
        }
        $this->set_run_status('complete');
    }

    public function run_db_sync(): void
    {
        file_put_contents($this->paths->sql_file(), "SELECT 1;\n");
        $this->set_run_status('complete');
    }

    public function run_db_apply(array $options): void
    {
        $this->set_run_status('complete');
    }

    public function run_flat_document_root(array $options): void
    {
    }

    public function run_apply_runtime(array $options): void
    {
    }

    public function fs_root(): string
    {
        return $this->fsRoot;
    }

    private function write_state(): void
    {
        $file = $this->paths->state_file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents(
            $file,
            json_encode([
                'command' => 'pull',
                'status' => $this->status,
                'filter' => $this->filter,
            ], JSON_PRETTY_PRINT),
        );
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

    private function makePull(bool $create_skipped_list): Pull
    {
        $stream = fopen('php://temp', 'w+');
        return new Pull(
            new PullFilterFakeRuntime($this->stateDir, $this->fs_root, $create_skipped_list),
            new TerminalProgress(false, $stream),
        );
    }

    private function readState(): array
    {
        return json_decode(
            file_get_contents($this->stateDir . '/.reprint/run.json'),
            true,
        );
    }

    private function readPullCheckpoint(): array
    {
        return json_decode(
            file_get_contents($this->stateDir . '/.reprint/pull/checkpoint.json'),
            true,
        );
    }

    public function testPullRejectsSkippedEarlierFilterBeforePersistingIt(): void
    {
        $pull = $this->makePull(false);

        try {
            ob_start();
            $pull->run([
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

        $this->assertFileDoesNotExist($this->stateDir . '/.reprint/run.json');
    }

    public function testPullWithEssentialFilesPersistsDeferredFilesState(): void
    {
        $pull = $this->makePull(true);

        ob_start();
        $pull->run([
            "filter" => "essential-files",
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $checkpoint = $this->readPullCheckpoint();
        $this->assertArrayNotHasKey('pull', $state);
        $this->assertSame('complete', $checkpoint["stage"]);
        $this->assertSame('essential-files', $checkpoint["files_filter"]);
        $this->assertTrue($checkpoint["skipped_pending"]);
        $this->assertSame('essential-files', $state["filter"]);
        $this->assertFileExists($this->stateDir . '/.import-download-list-skipped.jsonl');
    }

    public function testPullWithoutFilterRecordsFullDownloadMode(): void
    {
        $pull = $this->makePull(false);

        ob_start();
        $pull->run([
            "runtime" => "none",
        ]);
        ob_end_clean();

        $state = $this->readState();
        $checkpoint = $this->readPullCheckpoint();
        $this->assertArrayNotHasKey('pull', $state);
        $this->assertSame('complete', $checkpoint["stage"]);
        $this->assertSame('none', $checkpoint["files_filter"]);
        $this->assertFalse($checkpoint["skipped_pending"]);
        $this->assertSame('none', $state["filter"]);
        $this->assertFileDoesNotExist($this->stateDir . '/.import-download-list-skipped.jsonl');
    }
}
