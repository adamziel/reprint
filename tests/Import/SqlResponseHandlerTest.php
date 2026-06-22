<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;
use Reprint\Importer\Sql\Port\SqlDomainStore;
use Reprint\Importer\Sql\Port\SqlOutputSink;
use Reprint\Importer\Sql\Port\SqlShutdownToken;
use Reprint\Importer\Sql\Port\SqlStreamObserver;
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
        $context = new StreamingContext();
        $query_stream = new WP_MySQL_Naive_Query_Stream();
        $domain_collector = new DomainCollector();
        $output = new RecordingSqlOutputSink(4);
        $handler = $this->make_handler([
            'cursor' => 'cursor-0',
            'context' => $context,
            'output' => $output,
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

        $this->assertSame([['sql' => 'SELECT 1;', 'query_complete' => true]], $output->writes);
        $this->assertSame('cursor-1', $handler->cursor());
        $this->assertSame(13, $handler->sql_bytes_written());
        $this->assertSame(3, $handler->sql_statements_counted());
        $this->assertSame([], $domain_collector->get_domains());
        $this->assertSame([13], $this->progress);
    }

    public function testCheckpointPersistsCurrentCursorAndDomains(): void
    {
        $context = new StreamingContext();
        $domain_collector = new DomainCollector();
        $domain_collector->merge(['https://example.com']);
        $output = new RecordingSqlOutputSink(12);
        $handler = $this->make_handler([
            'cursor' => 'cursor-0',
            'context' => $context,
            'output' => $output,
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
        $output = new BufferedQuerySqlOutputSink();
        $handler = $this->make_handler([
            'output' => $output,
        ]);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'sql',
                'x-query-complete' => '0',
            ],
            'body' => 'INSERT INTO t ',
        ]);

        $this->assertSame('INSERT INTO t ', $output->pending_buffer());
        $this->assertSame('INSERT INTO t ', $handler->sql_buffer());
        $this->assertSame([], $output->queries);

        $handler->handle([
            'headers' => [
                'x-chunk-type' => 'sql',
                'x-query-complete' => '1',
            ],
            'body' => 'VALUES (1);',
        ]);

        $this->assertSame(['INSERT INTO t VALUES (1);'], $output->queries);
        $this->assertSame('', $handler->sql_buffer());
        $this->assertSame('', $output->pending_buffer());
    }

    private function make_handler(array $overrides = []): SqlResponseHandler
    {
        $context = $overrides['context'] ?? new StreamingContext();
        $checkpoint = DbPullCheckpoint::fresh();

        return new SqlResponseHandler(
            $overrides['cursor'] ?? null,
            $context,
            $overrides['output'] ?? new RecordingSqlOutputSink($overrides['sql_bytes_written'] ?? 0),
            $overrides['query_stream'] ?? null,
            $overrides['domain_collector'] ?? null,
            $overrides['domain_scanner'] ?? null,
            $overrides['sql_statements_counted'] ?? 0,
            $overrides['chunks_since_save'] ?? 0,
            $overrides['save_every'] ?? 50,
            $checkpoint,
            new TestSqlShutdownToken(),
            new RecordingDbPullCheckpointStore($this->saved),
            new RecordingSqlDomainStore($this->persisted_domains),
            new RecordingSqlStreamObserver(
                $this->progress,
                $this->errors,
                $this->completion_progress,
            ),
        );
    }
}

class RecordingSqlOutputSink implements SqlOutputSink
{
    private int $bytes_written;
    private string $buffer = '';
    public array $writes = [];

    public function __construct(int $bytes_written = 0)
    {
        $this->bytes_written = $bytes_written;
    }

    public function bytes_written(): int
    {
        return $this->bytes_written;
    }

    public function pending_buffer(): string
    {
        return $this->buffer;
    }

    public function write(string $sql, bool $query_complete): void
    {
        $this->writes[] = compact('sql', 'query_complete');
        $this->bytes_written += strlen($sql);
    }

    public function flush(): void
    {
    }

    public function close(): void
    {
    }
}

final class BufferedQuerySqlOutputSink extends RecordingSqlOutputSink
{
    private string $buffer = '';
    public array $queries = [];

    public function pending_buffer(): string
    {
        return $this->buffer;
    }

    public function write(string $sql, bool $query_complete): void
    {
        parent::write($sql, $query_complete);
        $this->buffer .= $sql;

        if ($query_complete) {
            $this->queries[] = $this->buffer;
            $this->buffer = '';
        }
    }
}

final class TestSqlShutdownToken implements SqlShutdownToken
{
    public function is_shutdown_requested(): bool
    {
        return false;
    }
}

final class RecordingDbPullCheckpointStore implements DbPullCheckpointStore
{
    /** @var array<int, array<string, mixed>> */
    private $saved;

    public function __construct(array &$saved)
    {
        $this->saved =& $saved;
    }

    public function get(): DbPullCheckpoint
    {
        return DbPullCheckpoint::fresh();
    }

    public function save(DbPullCheckpoint $checkpoint): void
    {
        $this->saved[] = [
            'cursor' => $checkpoint->cursor,
            'sql_bytes_written' => $checkpoint->sql_bytes,
            'sql_statements_counted' => $checkpoint->sql_statements_counted,
        ];
    }
}

final class RecordingSqlDomainStore implements SqlDomainStore
{
    /** @var array<int, array<int, string>> */
    private $persisted;

    public function __construct(array &$persisted)
    {
        $this->persisted =& $persisted;
    }

    public function load(): array
    {
        return [];
    }

    public function persist(array $domains): void
    {
        $this->persisted[] = $domains;
    }
}

final class RecordingSqlStreamObserver implements SqlStreamObserver
{
    private $progress;
    private $errors;
    private $completion_progress;

    public function __construct(array &$progress, array &$errors, array &$completion_progress)
    {
        $this->progress =& $progress;
        $this->errors =& $errors;
        $this->completion_progress =& $completion_progress;
    }

    public function on_sql_progress(int $sql_bytes_written): void
    {
        $this->progress[] = $sql_bytes_written;
    }

    public function on_progress_chunk(array $chunk, string $phase): void
    {
        $this->progress[] = compact('chunk', 'phase');
    }

    public function on_error_chunk(array $chunk, string $phase, StreamingContext $context): void
    {
        $this->errors[] = compact('chunk', 'phase', 'context');
    }

    public function on_completion_progress(array $progress): void
    {
        $this->completion_progress[] = $progress;
    }

    public function on_stdout_write_failed(): void
    {
    }
}
