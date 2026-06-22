<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FileFetchDownloader;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FileIndexGateway;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\FilesPullTimeoutPolicy;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Observability\NullAuditLogger;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../importer/import.php';

final class FileFetchDownloaderTest extends TestCase
{
    public function testDownloadsFileFetchAndPersistsState(): void
    {
        $checkpoint = FilesPullCheckpoint::fresh();
        $saved_checkpoints = [];
        $finalized = [];
        $completion_progress = [];

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
                $this->test->assertSame('file_fetch', $endpoint);
                $this->test->assertNull($cursor);
                $this->test->assertSame(['/srv/htdocs'], $params['directory']);

                return 'https://example.test/export.php?endpoint=file_fetch';
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
                $this->test->assertSame('https://example.test/export.php?endpoint=file_fetch', $url);
                $this->test->assertNull($cursor);
                $this->test->assertSame(['file_list' => 'batch'], $post_data);
                $this->test->assertSame('file_fetch', $phase);

                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'completion',
                        'x-status' => 'complete',
                        'x-cursor' => 'done-cursor',
                        'x-files-completed' => '2',
                        'x-bytes-processed' => '100',
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

        $observer = new class($completion_progress) implements FileSyncStreamObserver {
            private array $completion_progress;

            public function __construct(array &$completion_progress)
            {
                $this->completion_progress = &$completion_progress;
            }

            public function on_metadata_chunk(array $chunk, StreamingContext $context): void {}
            public function on_file_chunk(array $chunk, StreamingContext $context): void {}
            public function on_directory_chunk(array $chunk): void {}
            public function on_symlink_chunk(array $chunk): void {}
            public function on_missing_path(string $path): void {}
            public function on_error_chunk(array $chunk, string $phase, StreamingContext $context): void {}
            public function on_progress_chunk(array $chunk, string $phase): void {}
            public function on_index_progress(int $entries_counted): void {}

            public function on_completion_progress(array $progress): void
            {
                $this->completion_progress[] = $progress;
            }
        };

        $index = new class implements FileIndexGateway {
            public function recover_updates(): void {}
            public function local_index_has_entries(): bool { return false; }
            public function count_local_index(): int { return 0; }
            public function count_remote_index(): int { return 0; }
            public function sort_remote_index(): void {}
            public function index_entries_counted(): int { return 0; }
            public function reset_transfer_progress(): void {}
            public function finalize_updates(): void {}
        };

        $downloader = new FileFetchDownloader(
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
            $index,
            new NullAuditLogger(),
        );

        $complete = $downloader->download(
            $checkpoint,
            [
                'post_data' => ['file_list' => 'batch'],
                'cursor' => null,
                'state_key' => 'fetch',
                'export_dirs' => ['/srv/htdocs'],
                'save_every' => 50,
            ],
        );

        $this->assertTrue($complete);
        $this->assertSame('done-cursor', $checkpoint->fetch->cursor);
        $this->assertSame(0, $checkpoint->consecutive_timeouts);
        $this->assertNull($checkpoint->current_file);
        $this->assertNull($checkpoint->current_file_bytes);
        $this->assertNotEmpty($saved_checkpoints);
        $this->assertSame('file_fetch', $finalized[0]['endpoint']);
        $this->assertSame('complete', $finalized[0]['response_stats']['status']);
        $this->assertSame(2, $completion_progress[0]['files_completed']);
    }
}
