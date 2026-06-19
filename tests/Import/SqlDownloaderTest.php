<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\SqlDownloader;

require_once __DIR__ . '/../../importer/import.php';

final class SqlDownloaderTest extends TestCase
{
    private string $state_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state_dir = sys_get_temp_dir() . '/sql-downloader-' . bin2hex(random_bytes(6));
        mkdir($this->state_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->state_dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->state_dir);
        parent::tearDown();
    }

    public function testDownloadsSqlToFileAndPersistsState(): void
    {
        $state = [
            'cursor' => null,
            'db_index' => [
                'bytes' => 100,
            ],
        ];
        $saved_states = [];
        $finalized = [];
        $progress_bytes = [];
        $completion_progress = [];
        $sql = "CREATE TABLE wp_posts (ID int);\n";

        $downloader = new SqlDownloader(
            function (string $endpoint, ?string $cursor, array $params): string {
                $this->assertSame('sql_chunk', $endpoint);
                $this->assertNull($cursor);
                $this->assertSame(['chunk_rows' => 100], $params);

                return 'https://example.test/export.php?endpoint=sql_chunk';
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ) use ($sql): void {
                $this->assertSame('https://example.test/export.php?endpoint=sql_chunk', $url);
                $this->assertNull($cursor);
                $this->assertNull($post_data);
                $this->assertSame('sql_chunk', $phase);

                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'sql',
                        'x-cursor' => 'done-cursor',
                        'x-query-complete' => '1',
                    ],
                    'body' => $sql,
                ]);
                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'completion',
                        'x-status' => 'complete',
                        'x-sql-bytes' => (string) strlen($sql),
                    ],
                ]);
            },
            fn(string $endpoint): array => ['chunk_rows' => 100],
            fn(): bool => false,
            function (array $state) use (&$saved_states): void {
                $saved_states[] = $state;
            },
            function (int $sql_bytes_written) use (&$progress_bytes): void {
                $progress_bytes[] = $sql_bytes_written;
            },
            function (): void {
            },
            function (): void {
            },
            function (array $progress) use (&$completion_progress): void {
                $completion_progress[] = $progress;
            },
            function (): void {
            },
            function (): void {
            },
            function (string $endpoint, float $wall_time, array $stats) use (&$finalized): void {
                $finalized[] = compact('endpoint', 'wall_time', 'stats');
            },
            function (): void {
            },
        );

        $downloader->download(
            $state,
            [
                'mode' => 'file',
                'state_dir' => $this->state_dir,
                'remote_url' => 'https://source.example/export.php',
                'save_every' => 50,
            ],
        );

        $this->assertSame($sql, file_get_contents($this->state_dir . '/db.sql'));
        $this->assertSame('done-cursor', $state['cursor']);
        $this->assertNull($state['sql_bytes']);
        $this->assertSame(0, $state['consecutive_timeouts']);
        $this->assertSame([strlen($sql)], $progress_bytes);
        $this->assertNotEmpty($saved_states);
        $this->assertSame('sql_chunk', $finalized[0]['endpoint']);
        $this->assertSame('complete', $finalized[0]['stats']['status']);
        $this->assertSame('complete', $completion_progress[0]['status']);
        $this->assertStringContainsString(
            'https://source.example',
            (string) file_get_contents($this->state_dir . '/.import-domains.json'),
        );
    }
}
