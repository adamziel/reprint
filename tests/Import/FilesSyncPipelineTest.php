<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FilesSyncPipeline;
use Reprint\Importer\Filesystem\LocalImportFilesystem;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class FilesSyncPipelineTest extends TestCase
{
    private string $temp_dir;
    private string $remote_index_file;
    private string $download_list_file;
    private string $skipped_download_list_file;
    private array $saved_states = [];
    private array $audit = [];
    private array $downloads = [];
    private array $notifications = [];
    private int $status_writes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/files-sync-pipeline-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->remote_index_file = $this->temp_dir . '/remote-index.jsonl';
        $this->download_list_file = $this->temp_dir . '/download-list.jsonl';
        $this->skipped_download_list_file = $this->temp_dir . '/download-list-skipped.jsonl';
        $this->saved_states = [];
        $this->audit = [];
        $this->downloads = [];
        $this->notifications = [];
        $this->status_writes = 0;
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testDiffStageDownloadsFilesAndCompletes(): void
    {
        $state = [
            'stage' => 'diff',
            'status' => 'in_progress',
            'diff' => [],
            'fetch' => ['offset' => 123],
            'fetch_skipped' => [],
        ];

        $this->make_pipeline([
            'diff' => function (): bool {
                $this->append_download($this->download_list_file, '/wp-login.php');
                return true;
            },
        ])->run($state);

        $this->assertNull($state['stage']);
        $this->assertSame(['offset' => 0], $state['fetch']);
        $this->assertSame([[$this->download_list_file, 'fetch']], $this->downloads);
        $this->assertSame([[42, $this->download_list_file]], $this->notifications);
        $this->assertFileDoesNotExist($this->download_list_file);
    }

    public function testEssentialFilesFilterLeavesSkippedListForLater(): void
    {
        $this->append_download($this->download_list_file, '/index.php');
        $this->append_download($this->skipped_download_list_file, '/wp-content/uploads/image.jpg');

        $state = [
            'stage' => 'fetch',
            'status' => 'in_progress',
            'fetch' => ['offset' => 99],
            'fetch_skipped' => ['offset' => 88],
        ];

        $this->make_pipeline([], 'essential-files')->run($state);

        $this->assertNull($state['stage']);
        $this->assertSame(['offset' => 0], $state['fetch']);
        $this->assertSame([[$this->download_list_file, 'fetch']], $this->downloads);
        $this->assertFileDoesNotExist($this->download_list_file);
        $this->assertFileExists($this->skipped_download_list_file);
        $this->assertSame(0, $this->status_writes);
        $this->assertStringContainsString(
            'run with --filter=skipped-earlier',
            implode("\n", array_column($this->audit, 0)),
        );
    }

    public function testIncompleteIndexMarksStatePartial(): void
    {
        $state = ['stage' => 'index', 'status' => 'in_progress'];

        $this->make_pipeline([
            'download_remote_index' => fn(): bool => false,
        ])->run($state);

        $this->assertSame('partial', $state['status']);
        $this->assertSame('index', $state['stage']);
        $this->assertSame([], $this->downloads);
    }

    /**
     * @param array<string, callable> $callbacks
     */
    private function make_pipeline(
        array $callbacks = [],
        string $filter = 'none'
    ): FilesSyncPipeline {
        return new FilesSyncPipeline(
            $this->remote_index_file,
            $this->download_list_file,
            $this->skipped_download_list_file,
            false,
            $filter,
            new LocalImportFilesystem(
                $this->temp_dir . '/fs-root',
                'error',
                function (string $message, bool $to_console): void {
                    $this->audit[] = [$message, $to_console];
                },
            ),
            fn(): array => $this->default_state(),
            $callbacks['download_remote_index'] ?? fn(): bool => true,
            $callbacks['discover_symlink_targets'] ?? function (array &$state): void {
            },
            $callbacks['should_stop'] ?? fn(): bool => false,
            $callbacks['sort_index_file'] ?? function (string $path): void {
            },
            $callbacks['diff'] ?? fn(): bool => true,
            $callbacks['download_files'] ?? function (string $list_file, string $state_key): bool {
                $this->downloads[] = [$list_file, $state_key];
                return true;
            },
            function (array $state): void {
                $this->saved_states[] = $state;
            },
            function (string $message, bool $to_console = true): void {
                $this->audit[] = [$message, $to_console];
            },
            function (): void {
                $this->status_writes++;
            },
            fn(): int => 42,
            function (int $scanned, string $download_list_file): void {
                $this->notifications[] = [$scanned, $download_list_file];
            },
        );
    }

    private function default_state(): array
    {
        return [
            'diff' => ['remote_offset' => 0, 'local_after' => null],
            'fetch' => ['offset' => 0],
            'fetch_skipped' => ['offset' => 0],
        ];
    }

    private function append_download(string $file, string $path): void
    {
        file_put_contents(
            $file,
            json_encode(['path' => base64_encode($path)], JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND,
        );
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
