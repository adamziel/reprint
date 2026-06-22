<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbIndexResponseHandler;
use Reprint\Importer\Sql\Port\DbIndexTableSink;
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
        $context = new StreamingContext();
        $sink = new RecordingDbIndexTableSink(2, 5, 11);
        $rows = [
            ['name' => 'wp_posts', 'rows' => 7],
            ['name' => 'wp_options', 'rows' => '3'],
            ['name' => 'wp_commentmeta'],
        ];
        $expected_body = '';
        foreach ($rows as $row) {
            $expected_body .= json_encode($row) . "\n";
        }

        $handler = $this->make_handler($sink, 'cursor-0', $context);
        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'table_stats',
                'x-cursor' => 'cursor-1',
            ],
            'body' => json_encode($rows),
        ]);

        $this->assertSame([$rows], $sink->writes);
        $this->assertSame('cursor-1', $handler->cursor());
        $this->assertSame(5, $handler->tables_written());
        $this->assertSame(15, $handler->rows_estimated());
        $this->assertSame(11 + strlen($expected_body), $handler->bytes_written());
    }

    public function testCompletionUpdatesStatsAndProgress(): void
    {
        $sink = new RecordingDbIndexTableSink();
        $context = new StreamingContext();
        $handler = $this->make_handler($sink, null, $context);

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
        $sink = new RecordingDbIndexTableSink();
        $context = new StreamingContext();
        $handler = $this->make_handler($sink, null, $context);

        $progress_chunk = [
            'headers' => ['x-chunk-type' => 'progress'],
            'body' => '',
        ];
        $handler->handle($progress_chunk);

        $this->assertNull($handler->cursor());
        $this->assertFalse($handler->complete());
    }

    public function testErrorChunkThrowsRemoteMessage(): void
    {
        $sink = new RecordingDbIndexTableSink();
        $context = new StreamingContext();
        $handler = $this->make_handler($sink, null, $context);
        $error_chunk = [
            'headers' => ['x-chunk-type' => 'error'],
            'body' => json_encode(['message' => 'remote failed']),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remote failed');

        $handler->handle($error_chunk);
    }

    private function make_handler(
        DbIndexTableSink $sink,
        ?string $cursor,
        StreamingContext $context
    ): DbIndexResponseHandler {
        return new DbIndexResponseHandler(
            $sink,
            $cursor,
            $context
        );
    }
}

final class RecordingDbIndexTableSink implements DbIndexTableSink
{
    private int $tables_written;
    private int $rows_estimated;
    private int $bytes_written;
    public array $writes = [];

    public function __construct(
        int $tables_written = 0,
        int $rows_estimated = 0,
        int $bytes_written = 0
    ) {
        $this->tables_written = $tables_written;
        $this->rows_estimated = $rows_estimated;
        $this->bytes_written = $bytes_written;
    }

    public function write_rows(array $rows): void
    {
        $this->writes[] = $rows;
        $this->tables_written += count($rows);
        $bytes = 0;
        foreach ($rows as $row) {
            if (isset($row['rows']) && is_numeric($row['rows'])) {
                $this->rows_estimated += (int) $row['rows'];
            }
            $bytes += strlen(json_encode($row) . "\n");
        }
        $this->bytes_written += $bytes;
    }

    public function tables_written(): int
    {
        return $this->tables_written;
    }

    public function rows_estimated(): int
    {
        return $this->rows_estimated;
    }

    public function bytes_written(): int
    {
        return $this->bytes_written;
    }

    public function flush(): void
    {
    }

    public function close(): void
    {
    }
}
