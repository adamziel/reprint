<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\IndexResponseHandler;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class IndexResponseHandlerTest extends TestCase
{
    private string $index_file;
    private array $saved = [];
    private array $progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->index_file = tempnam(sys_get_temp_dir(), 'index-response-handler-');
        $this->saved = [];
        $this->progress = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->index_file);
        parent::tearDown();
    }

    public function testIndexBatchWritesCanonicalJsonLines(): void
    {
        $handle = fopen($this->index_file, 'w+');
        $context = new StreamingContext();
        $handler = $this->make_handler($handle, null, $context);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'index_batch',
                'x-cursor' => 'cursor-1',
            ],
            'body' => json_encode([
                [
                    'path' => base64_encode('/wp-content/file.txt'),
                    'ctime' => 123,
                    'size' => 456,
                    'type' => 'file',
                ],
            ]),
        ]);

        fflush($handle);
        fclose($handle);

        $line = trim(file_get_contents($this->index_file));
        $this->assertSame([
            'path' => base64_encode('/wp-content/file.txt'),
            'ctime' => 123,
            'size' => 456,
            'type' => 'file',
        ], json_decode($line, true));
        $this->assertSame('cursor-1', $handler->cursor());
        $this->assertSame(1, $handler->entries_counted());
        $this->assertSame([1], $this->progress);
    }

    public function testCheckpointUsesCursorBeforeCurrentChunkUpdate(): void
    {
        $handle = fopen($this->index_file, 'w+');
        $handler = $this->make_handler($handle, 'cursor-0', new StreamingContext(), 1);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'progress',
                'x-cursor' => 'cursor-1',
            ],
            'body' => '',
        ]);
        fclose($handle);

        $this->assertSame('cursor-0', $this->saved[0]);
        $this->assertSame('cursor-1', $handler->cursor());
    }

    public function testCompletionUpdatesStats(): void
    {
        $handle = fopen($this->index_file, 'w+');
        $context = new StreamingContext();
        $handler = $this->make_handler($handle, null, $context);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'completion',
                'x-status' => 'complete',
                'x-total-entries' => '9',
                'x-time-elapsed' => '2.5',
                'x-memory-used' => '64',
                'x-memory-limit' => '128',
            ],
            'body' => '',
        ]);
        fclose($handle);

        $this->assertTrue($handler->complete());
        $this->assertTrue($context->saw_completion);
        $this->assertSame(9, $context->response_stats['entries_processed']);
    }

    private function make_handler(
        $handle,
        ?string $cursor,
        StreamingContext $context,
        int $save_every = 50
    ): IndexResponseHandler {
        $checkpoint = FilesPullCheckpoint::fresh();
        $checkpoint->index_cursor = $cursor;
        $saved = &$this->saved;
        $progress = &$this->progress;

        return new IndexResponseHandler(
            $handle,
            $checkpoint,
            $cursor,
            $context,
            0,
            $save_every,
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
                    $this->saved[] = $checkpoint->index_cursor;
                }
            },
            new class($progress) implements FileSyncStreamObserver {
                private array $progress;

                public function __construct(array &$progress)
                {
                    $this->progress = &$progress;
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
                    $this->progress[] = $entries_counted;
                }
            },
        );
    }
}
