<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbIndexDownloader;

require_once __DIR__ . '/../../importer/import.php';

final class DbIndexDownloaderTest extends TestCase
{
    private string $tables_file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tables_file = tempnam(sys_get_temp_dir(), 'db-index-downloader-');
        if ($this->tables_file === false) {
            $this->fail('Failed to allocate temp file');
        }
        unlink($this->tables_file);
    }

    protected function tearDown(): void
    {
        @unlink($this->tables_file);
        parent::tearDown();
    }

    public function testDownloadsTableStatsAndPersistsState(): void
    {
        $state = [
            'cursor' => null,
            'db_index' => [],
        ];
        $saved_states = [];
        $finalized = [];

        $downloader = new DbIndexDownloader(
            function (string $endpoint, ?string $cursor, array $params): string {
                $this->assertSame('db_index', $endpoint);
                $this->assertNull($cursor);
                $this->assertSame(['tables_per_batch' => 1000], $params);

                return 'https://example.test/export.php?endpoint=db_index';
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ): void {
                $this->assertSame('https://example.test/export.php?endpoint=db_index', $url);
                $this->assertNull($cursor);
                $this->assertNull($post_data);
                $this->assertSame('db_index', $phase);

                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'table_stats',
                        'x-cursor' => 'next-cursor',
                    ],
                    'body' => json_encode([
                        ['name' => 'wp_posts', 'rows' => 5, 'bytes' => 100],
                    ]),
                ]);
                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'completion',
                        'x-status' => 'complete',
                        'x-tables-processed' => '1',
                        'x-rows-estimated' => '5',
                    ],
                ]);
            },
            fn(): bool => false,
            function (): void {
            },
            function (): void {
            },
            function (): void {
            },
            function (): void {
            },
            function (string $endpoint, float $wall_time, array $stats) use (&$finalized): void {
                $finalized[] = compact('endpoint', 'wall_time', 'stats');
            },
            function (array $state) use (&$saved_states): void {
                $saved_states[] = $state;
            },
            function (): void {
            },
        );

        $downloader->download($state, $this->tables_file);

        $this->assertFileExists($this->tables_file);
        $this->assertSame(
            json_encode(['name' => 'wp_posts', 'rows' => 5, 'bytes' => 100]) . "\n",
            file_get_contents($this->tables_file),
        );
        $this->assertSame('next-cursor', $state['cursor']);
        $this->assertSame($this->tables_file, $state['db_index']['file']);
        $this->assertSame(1, $state['db_index']['tables']);
        $this->assertSame(5, $state['db_index']['rows_estimated']);
        $this->assertSame(filesize($this->tables_file), $state['db_index']['bytes']);
        $this->assertSame(0, $state['consecutive_timeouts']);
        $this->assertNotEmpty($saved_states);
        $this->assertSame('db_index', $finalized[0]['endpoint']);
    }
}
