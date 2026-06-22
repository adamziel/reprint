<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FileFetchResponseHandler;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class FileFetchResponseHandlerTest extends TestCase
{
    private array $saved = [];
    private array $files = [];
    private array $missing = [];
    private array $completion_progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = [];
        $this->files = [];
        $this->missing = [];
        $this->completion_progress = [];
    }

    public function testStreamingCloseForcesCheckpointBeforeCursorUpdate(): void
    {
        $context = new StreamingContext();
        $handler = $this->make_handler('cursor-1', $context);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'file',
                'x-cursor' => 'cursor-2',
            ],
            'body' => 'abc',
            'is_streaming_body' => true,
        ]);
        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'file',
                'x-cursor' => 'cursor-3',
            ],
            'body' => '',
            'is_streaming_close' => true,
        ]);

        $this->assertSame('cursor-2', $this->saved[0]['cursor']);
        $this->assertSame('cursor-3', $handler->cursor());
        $this->assertCount(2, $this->files);
    }

    public function testCompletionUpdatesStatsAndProgress(): void
    {
        $context = new StreamingContext();
        $handler = $this->make_handler(null, $context);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'completion',
                'x-status' => 'complete',
                'x-files-completed' => '7',
                'x-bytes-processed' => '123',
                'x-time-elapsed' => '1.5',
                'x-memory-used' => '42',
                'x-memory-limit' => '100',
            ],
            'body' => '',
        ]);

        $this->assertTrue($handler->complete());
        $this->assertTrue($context->saw_completion);
        $this->assertSame(123, $context->response_stats['bytes_processed']);
        $this->assertSame([
            'phase' => 'files',
            'status' => 'complete',
            'files_completed' => 7,
            'bytes_processed' => 123,
        ], $this->completion_progress[0]);
    }

    public function testMissingChunkReportsDecodedPath(): void
    {
        $handler = $this->make_handler(null, new StreamingContext());

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'missing',
                'x-file-path' => base64_encode('/missing.txt'),
            ],
            'body' => '',
        ]);

        $this->assertSame(['/missing.txt'], $this->missing);
    }

    private function make_handler(
        ?string $cursor,
        StreamingContext $context,
        int $save_every = 50
    ): FileFetchResponseHandler {
        $checkpoint = FilesPullCheckpoint::fresh();
        $saved = &$this->saved;
        $files = &$this->files;
        $missing = &$this->missing;
        $completion_progress = &$this->completion_progress;

        return new FileFetchResponseHandler(
            $cursor,
            'fetch',
            $context,
            $save_every,
            $checkpoint,
            new class implements ShutdownToken {
                public function is_shutdown_requested(): bool
                {
                    return false;
                }
            },
            new class($saved) implements FilesPullCheckpointStore {
                private array $saved;

                public function __construct(array &$saved)
                {
                    $this->saved = &$saved;
                }

                public function get(): FilesPullCheckpoint
                {
                    return FilesPullCheckpoint::fresh();
                }

                public function save(FilesPullCheckpoint $checkpoint): void
                {
                    $this->saved[] = [
                        'state_key' => 'fetch',
                        'cursor' => $checkpoint->fetch->cursor,
                    ];
                }
            },
            new class($files, $missing, $completion_progress) implements FileSyncStreamObserver {
                private array $files;
                private array $missing;
                private array $completion_progress;

                public function __construct(
                    array &$files,
                    array &$missing,
                    array &$completion_progress
                ) {
                    $this->files = &$files;
                    $this->missing = &$missing;
                    $this->completion_progress = &$completion_progress;
                }

                public function on_metadata_chunk(array $chunk, StreamingContext $context): void {}

                public function on_file_chunk(array $chunk, StreamingContext $context): void
                {
                    $this->files[] = $chunk;
                }

                public function on_directory_chunk(array $chunk): void {}
                public function on_symlink_chunk(array $chunk): void {}

                public function on_missing_path(string $path): void
                {
                    $this->missing[] = $path;
                }

                public function on_error_chunk(array $chunk, string $phase, StreamingContext $context): void {}
                public function on_progress_chunk(array $chunk, string $phase): void {}
                public function on_index_progress(int $entries_counted): void {}

                public function on_completion_progress(array $progress): void
                {
                    $this->completion_progress[] = $progress;
                }
            },
        );
    }
}
