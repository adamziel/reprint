<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbIndexResponseHandler;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class DbIndexResponseHandlerTest extends TestCase
{
    private string $tables_file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tables_file = tempnam(sys_get_temp_dir(), 'db-index-response-handler-');
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
    }

    public function testProgressChunksAreIgnored(): void
    {
        $handle = fopen($this->tables_file, 'w+');
        $context = new StreamingContext();
        $handler = $this->make_handler($handle, null, $context);

        $progress_chunk = [
            'headers' => ['x-chunk-type' => 'progress'],
            'body' => '',
        ];
        $handler->handle($progress_chunk);
        fclose($handle);

        $this->assertNull($handler->cursor());
        $this->assertFalse($handler->complete());
    }

    public function testErrorChunkThrowsRemoteMessage(): void
    {
        $handle = fopen($this->tables_file, 'w+');
        $context = new StreamingContext();
        $handler = $this->make_handler($handle, null, $context);
        $error_chunk = [
            'headers' => ['x-chunk-type' => 'error'],
            'body' => json_encode(['message' => 'remote failed']),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remote failed');

        $handler->handle($error_chunk);
        fclose($handle);
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
        );
    }
}
