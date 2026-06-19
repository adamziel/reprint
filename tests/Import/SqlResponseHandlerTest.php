<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\QueryStream\WP_MySQL_Naive_Query_Stream;
use Reprint\Importer\Sql\SqlDomainScanner;
use Reprint\Importer\Sql\SqlResponseHandler;
use Reprint\Importer\UrlRewrite\DomainCollector;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class SqlResponseHandlerTest extends TestCase
{
    private string $sql_file;
    private string $buffer_file;
    private array $saved = [];
    private array $persisted_domains = [];
    private array $progress = [];
    private array $errors = [];
    private array $completion_progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sql_file = tempnam(sys_get_temp_dir(), 'sql-response-handler-sql-');
        $this->buffer_file = tempnam(sys_get_temp_dir(), 'sql-response-handler-buffer-');
        $this->saved = [];
        $this->persisted_domains = [];
        $this->progress = [];
        $this->errors = [];
        $this->completion_progress = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->sql_file);
        @unlink($this->buffer_file);
        parent::tearDown();
    }

    public function testFileSqlChunkWritesDataAndTracksCounters(): void
    {
        $handle = fopen($this->sql_file, 'w+');
        $context = new StreamingContext();
        $query_stream = new WP_MySQL_Naive_Query_Stream();
        $domain_collector = new DomainCollector();
        $handler = $this->make_handler([
            'mode' => 'file',
            'cursor' => 'cursor-0',
            'context' => $context,
            'sql_handle' => $handle,
            'sql_bytes_written' => 4,
            'query_stream' => $query_stream,
            'domain_collector' => $domain_collector,
            'domain_scanner' => new SqlDomainScanner(),
            'sql_statements_counted' => 2,
        ]);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'sql',
                'x-cursor' => 'cursor-1',
            ],
            'body' => 'SELECT 1;',
        ]);

        fflush($handle);
        fclose($handle);

        $this->assertSame('SELECT 1;', file_get_contents($this->sql_file));
        $this->assertSame('cursor-1', $handler->cursor());
        $this->assertSame(13, $handler->sql_bytes_written());
        $this->assertSame(3, $handler->sql_statements_counted());
        $this->assertSame([], $domain_collector->get_domains());
        $this->assertSame([13], $this->progress);
    }

    public function testCheckpointPersistsCurrentCursorAndDomains(): void
    {
        $handle = fopen($this->sql_file, 'w+');
        $context = new StreamingContext();
        $domain_collector = new DomainCollector();
        $domain_collector->merge(['https://example.com']);
        $handler = $this->make_handler([
            'mode' => 'file',
            'cursor' => 'cursor-0',
            'context' => $context,
            'sql_handle' => $handle,
            'sql_bytes_written' => 12,
            'domain_collector' => $domain_collector,
            'sql_statements_counted' => 5,
            'save_every' => 1,
        ]);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'progress',
                'x-cursor' => 'cursor-1',
            ],
            'body' => '',
        ]);
        fclose($handle);

        $this->assertSame([
            [
                'cursor' => 'cursor-1',
                'sql_bytes_written' => 12,
                'sql_statements_counted' => 5,
            ],
        ], $this->saved);
        $this->assertSame([['https://example.com']], $this->persisted_domains);
        $this->assertSame(0, $handler->chunks_since_save());
        $this->assertSame([
            [
                'chunk' => [
                    'headers' => [
                        'x-chunk-type' => 'progress',
                        'x-cursor' => 'cursor-1',
                    ],
                    'body' => '',
                ],
                'phase' => 'sql',
            ],
        ], $this->progress);
    }

    public function testCompletionUpdatesStatsAndProgress(): void
    {
        $context = new StreamingContext();
        $handler = $this->make_handler([
            'context' => $context,
        ]);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'completion',
                'x-status' => 'complete',
                'x-sql-bytes' => '123',
                'x-time-elapsed' => '2.5',
                'x-memory-used' => '64',
                'x-memory-limit' => '128',
                'x-batches-processed' => '7',
            ],
            'body' => '',
        ]);

        $this->assertTrue($handler->complete());
        $this->assertTrue($context->saw_completion);
        $this->assertSame([
            'status' => 'complete',
            'sql_bytes' => 123,
            'server_time' => 2.5,
            'memory_used' => 64,
            'memory_limit' => 128,
        ], $context->response_stats);
        $this->assertSame([
            'phase' => 'sql',
            'status' => 'complete',
            'batches_processed' => 7,
        ], $this->completion_progress[0]);
    }

    public function testProgressAndErrorAreDelegatedWithExistingPhases(): void
    {
        $context = new StreamingContext();
        $handler = $this->make_handler([
            'context' => $context,
        ]);

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

        $this->assertSame([
            ['chunk' => $progress_chunk, 'phase' => 'sql'],
        ], $this->progress);
        $this->assertSame([
            ['chunk' => $error_chunk, 'phase' => 'db-index', 'context' => $context],
        ], $this->errors);
    }

    public function testMysqlModeBuffersUntilQueryIsComplete(): void
    {
        $buffer_handle = fopen($this->buffer_file, 'w+');
        $mysql_conn = new FakeSqlMysqlConnection();
        $handler = $this->make_handler([
            'mode' => 'mysql',
            'mysql_conn' => $mysql_conn,
            'buffer_handle' => $buffer_handle,
        ]);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'sql',
                'x-query-complete' => '0',
            ],
            'body' => 'INSERT INTO t ',
        ]);

        fflush($buffer_handle);
        $this->assertSame('INSERT INTO t ', file_get_contents($this->buffer_file));
        $this->assertSame('INSERT INTO t ', $handler->sql_buffer());
        $this->assertSame([], $mysql_conn->queries);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'sql',
                'x-query-complete' => '1',
            ],
            'body' => 'VALUES (1);',
        ]);

        fflush($buffer_handle);
        rewind($buffer_handle);
        $buffer_contents = stream_get_contents($buffer_handle);
        fclose($buffer_handle);

        $this->assertSame(['INSERT INTO t VALUES (1);'], $mysql_conn->queries);
        $this->assertSame('', $handler->sql_buffer());
        $this->assertSame('', $buffer_contents);
    }

    private function make_handler(array $overrides = []): SqlResponseHandler
    {
        $context = $overrides['context'] ?? new StreamingContext();

        return new SqlResponseHandler(
            $overrides['mode'] ?? 'file',
            $overrides['cursor'] ?? null,
            $context,
            $overrides['sql_handle'] ?? null,
            $overrides['mysql_conn'] ?? null,
            $overrides['buffer_handle'] ?? null,
            $overrides['sql_buffer'] ?? '',
            $overrides['sql_bytes_written'] ?? 0,
            $overrides['query_stream'] ?? null,
            $overrides['domain_collector'] ?? null,
            $overrides['domain_scanner'] ?? null,
            $overrides['sql_statements_counted'] ?? 0,
            $overrides['chunks_since_save'] ?? 0,
            $overrides['save_every'] ?? 50,
            fn(): bool => false,
            function (
                ?string $cursor,
                int $sql_bytes_written,
                int $sql_statements_counted
            ): void {
                $this->saved[] = compact(
                    'cursor',
                    'sql_bytes_written',
                    'sql_statements_counted',
                );
            },
            function (array $domains): void {
                $this->persisted_domains[] = $domains;
            },
            function (int $sql_bytes_written): void {
                $this->progress[] = $sql_bytes_written;
            },
            function (array $chunk, string $phase): void {
                $this->progress[] = compact('chunk', 'phase');
            },
            function (
                array $chunk,
                string $phase,
                StreamingContext $context
            ): void {
                $this->errors[] = compact('chunk', 'phase', 'context');
            },
            function (array $progress): void {
                $this->completion_progress[] = $progress;
            },
            function (): void {
            },
        );
    }
}

final class FakeSqlMysqlConnection
{
    public string $error = '';
    public int $errno = 0;
    public array $queries = [];

    public function multi_query(string $sql): bool
    {
        $this->queries[] = $sql;
        return true;
    }

    public function store_result()
    {
        return null;
    }

    public function more_results(): bool
    {
        return false;
    }

    public function next_result(): bool
    {
        return false;
    }
}
