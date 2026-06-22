<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\RemoteIndexDownloader;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FileSyncWorkspace;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\FilesPullTimeoutPolicy;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Observability\NullAuditLogger;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../importer/import.php';

final class RemoteIndexDownloaderTest extends TestCase
{
    private string $remote_index_file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remote_index_file = tempnam(sys_get_temp_dir(), 'remote-index-downloader-');
        if ($this->remote_index_file === false) {
            $this->fail('Failed to allocate temp file');
        }
        unlink($this->remote_index_file);
    }

    protected function tearDown(): void
    {
        @unlink($this->remote_index_file);
        parent::tearDown();
    }

    public function testDownloadsRemoteIndexAndPersistsState(): void
    {
        $checkpoint = FilesPullCheckpoint::fresh();
        $entries_counted = 0;
        $saved_checkpoints = [];
        $finalized = [];
        $progress_counts = [];

        $stream = new class($this, $finalized) implements FileSyncStreamClient {
            private TestCase $test;
            private array $finalized;

            public function __construct(TestCase $test, array &$finalized)
            {
                $this->test = $test;
                $this->finalized = &$finalized;
            }

            public function build_url(string $endpoint, ?string $cursor, array $params): string
            {
                $this->test->assertSame('file_index', $endpoint);
                $this->test->assertNull($cursor);
                $this->test->assertSame('/srv/htdocs', $params['list_dir']);
                $this->test->assertSame('1', $params['follow_symlinks']);
                $this->test->assertSame('1', $params['include_caches']);
                $this->test->assertSame(['/srv/htdocs'], $params['directory']);

                return 'https://example.test/export.php?endpoint=file_index';
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
                $this->test->assertSame('https://example.test/export.php?endpoint=file_index', $url);
                $this->test->assertNull($cursor);
                $this->test->assertNull($post_data);
                $this->test->assertSame('file_index', $phase);

                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'index_batch',
                        'x-cursor' => 'next-cursor',
                    ],
                    'body' => json_encode([
                        [
                            'path' => base64_encode('/srv/htdocs/wp-content/index.php'),
                            'ctime' => 123,
                            'size' => 456,
                            'type' => 'file',
                        ],
                    ]),
                ]);
                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'completion',
                        'x-status' => 'complete',
                        'x-total-entries' => '1',
                    ],
                ]);
            }

            public function finalize_request(
                string $endpoint,
                float $wall_time,
                array $response_stats
            ): void {
                $this->finalized[] = compact('endpoint', 'wall_time', 'response_stats');
            }
        };

        $checkpoints = new class($saved_checkpoints) implements FilesPullCheckpointStore {
            private array $saved_checkpoints;

            public function __construct(array &$saved_checkpoints)
            {
                $this->saved_checkpoints = &$saved_checkpoints;
            }

            public function get(): FilesPullCheckpoint
            {
                return FilesPullCheckpoint::fresh();
            }

            public function save(FilesPullCheckpoint $checkpoint): void
            {
                $this->saved_checkpoints[] = clone $checkpoint;
            }
        };

        $observer = new class($progress_counts) implements FileSyncStreamObserver {
            private array $progress_counts;

            public function __construct(array &$progress_counts)
            {
                $this->progress_counts = &$progress_counts;
            }

            public function on_metadata_chunk(array $chunk, StreamingContext $context): void {}
            public function on_file_chunk(array $chunk, StreamingContext $context): void {}
            public function on_directory_chunk(array $chunk): void {}
            public function on_symlink_chunk(array $chunk): void {}
            public function on_missing_path(string $path): void {}
            public function on_error_chunk(array $chunk, string $phase, StreamingContext $context): void {}
            public function on_progress_chunk(array $chunk, string $phase): void {}
            public function on_completion_progress(array $progress): void {}

            public function on_index_progress(int $entries_counted): void
            {
                $this->progress_counts[] = $entries_counted;
            }
        };

        $workspace = new class implements FileSyncWorkspace {
            public function fs_root(): string { return ''; }
            public function index_file(): string { return ''; }
            public function remote_index_file(): string { return ''; }
            public function download_list_file(): string { return ''; }
            public function skipped_download_list_file(): string { return ''; }
            public function audit_log_file(): string { return ''; }
            public function file_has_entries(string $file): bool { return file_exists($file) && filesize($file) > 0; }
            public function is_fs_root_empty(): bool { return true; }
            public function delete_file_if_exists(string $file): bool { return false; }
            public function count_lines(string $file): int
            {
                return substr_count((string) file_get_contents($file), "\n");
            }
        };

        $downloader = new RemoteIndexDownloader(
            $stream,
            new class implements ShutdownToken {
                public function is_shutdown_requested(): bool { return false; }
            },
            $checkpoints,
            $observer,
            new class implements FilesPullTimeoutPolicy {
                public function assert_can_retry(
                    FilesPullCheckpoint $checkpoint,
                    string $phase,
                    ?string $cursor_before,
                    ?string $cursor_after
                ): void {
                }
            },
            $workspace,
            new NullAuditLogger(),
        );

        $complete = $downloader->download(
            $checkpoint,
            [
                'remote_index_file' => $this->remote_index_file,
                'roots' => ['/srv/htdocs'],
                'export_dirs' => ['/srv/htdocs'],
                'list_dir_override' => null,
                'follow_symlinks' => true,
                'include_caches' => true,
                'save_every' => 50,
            ],
            $entries_counted,
        );

        $this->assertTrue($complete);
        $this->assertSame(1, $entries_counted);
        $this->assertSame(
            json_encode([
                'path' => base64_encode('/srv/htdocs/wp-content/index.php'),
                'ctime' => 123,
                'size' => 456,
                'type' => 'file',
            ]) . "\n",
            file_get_contents($this->remote_index_file),
        );
        $this->assertNull($checkpoint->index_cursor);
        $this->assertSame(0, $checkpoint->consecutive_timeouts);
        $this->assertSame([1], $progress_counts);
        $this->assertNotEmpty($saved_checkpoints);
        $this->assertSame('file_index', $finalized[0]['endpoint']);
        $this->assertSame('complete', $finalized[0]['response_stats']['status']);
    }
}
