<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbIndexResponseHandler;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class DbIndexResponseHandlerTest extends TestCase
{
    private string $tables_file;
    private array $progress = [];
    private array $errors = [];
    private array $completion_progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tables_file = tempnam(sys_get_temp_dir(), 'db-index-response-handler-');
        $this->progress = [];
        $this->errors = [];
        $this->completion_progress = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->tables_file);
        parent::tearDown();
    }

    public function testTableStatsWritesJsonLinesAndTracksStats(): void
    {
        $handle = fopen($this->tables_file, 'w+');
        $context = new StreamingContext();
        $rows = [
            ['name' => 'wp_posts', 'rows' => 7],
            ['name' => 'wp_options', 'rows' => '3'],
            ['name' => 'wp_commentmeta'],
        ];
        $expected_body = '';
        foreach ($rows as $row) {
            $expected_body .= json_encode($row) . "\n";
        }

        $handler = $this->make_handler($handle, 'cursor-0', $context, 2, 5, 11);
        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'table_stats',
                'x-cursor' => 'cursor-1',
            ],
            'body' => json_encode($rows),
        ]);

        fflush($handle);
        fclose($handle);

        $this->assertSame($expected_body, file_get_contents($this->tables_file));
        $this->assertSame('cursor-1', $handler->cursor());
        $this->assertSame(5, $handler->tables_written());
        $this->assertSame(15, $handler->rows_estimated());
        $this->assertSame(11 + strlen($expected_body), $handler->bytes_written());
    }

    public function testCompletionUpdatesStatsAndProgress(): void
    {
        $handle = fopen($this->tables_file, 'w+');
        $context = new StreamingContext();
        $handler = $this->make_handler($handle, null, $context);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'completion',
                'x-status' => 'complete',
                'x-tables-processed' => '4',
                'x-rows-estimated' => '25',
                'x-time-elapsed' => '1.25',
                'x-memory-used' => '1024',
                'x-memory-limit' => '2048',
            ],
            'body' => '',
        ]);
        fclose($handle);

        $this->assertTrue($handler->complete());
        $this->assertTrue($context->saw_completion);
        $this->assertSame([
            'status' => 'complete',
            'tables_processed' => 4,
            'rows_estimated' => 25,
            'server_time' => 1.25,
            'memory_used' => 1024,
            'memory_limit' => 2048,
        ], $context->response_stats);
        $this->assertSame([
            'phase' => 'db-index',
            'status' => 'complete',
            'tables_processed' => 4,
        ], $this->completion_progress[0]);
    }

    public function testProgressAndErrorAreDelegatedWithExistingPhases(): void
    {
        $handle = fopen($this->tables_file, 'w+');
        $context = new StreamingContext();
        $handler = $this->make_handler($handle, null, $context);

        $progress_chunk = [
            'headers' => ['x-chunk-type' => 'progress'],
            'body' => '',
        ];
        $handler->handle($progress_chunk);

        $error_chunk = [
            'headers' => ['x-chunk-type' => 'error'],
            'body' => 'failed',
        ];
        $handler->handle($error_chunk);
        fclose($handle);

        $this->assertSame([
            ['chunk' => $progress_chunk, 'phase' => 'db-index'],
        ], $this->progress);
        $this->assertSame([
            ['chunk' => $error_chunk, 'phase' => 'sql', 'context' => $context],
        ], $this->errors);
    }

    private function make_handler(
        $handle,
        ?string $cursor,
        StreamingContext $context,
        int $tables_written = 0,
        int $rows_estimated = 0,
        int $bytes_written = 0
    ): DbIndexResponseHandler {
        return new DbIndexResponseHandler(
            $handle,
            $cursor,
            $context,
            $tables_written,
            $rows_estimated,
            $bytes_written,
            fn(): bool => false,
            function (array $chunk, string $phase): void {
                $this->progress[] = compact('chunk', 'phase');
            },
            function (array $chunk, string $phase, StreamingContext $context): void {
                $this->errors[] = compact('chunk', 'phase', 'context');
            },
            function (array $progress): void {
                $this->completion_progress[] = $progress;
            },
        );
    }
}
