<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FileFetchDownloader;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../importer/import.php';

final class FileFetchDownloaderTest extends TestCase
{
    public function testDownloadsFileFetchAndPersistsState(): void
    {
        $state = [
            'fetch' => [
                'cursor' => null,
            ],
        ];
        $saved_states = [];
        $finalized = [];
        $completion_progress = [];

        $downloader = new FileFetchDownloader(
            function (string $endpoint, ?string $cursor, array $params): string {
                $this->assertSame('file_fetch', $endpoint);
                $this->assertNull($cursor);
                $this->assertSame(['/srv/htdocs'], $params['directory']);

                return 'https://example.test/export.php?endpoint=file_fetch';
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ): void {
                $this->assertSame('https://example.test/export.php?endpoint=file_fetch', $url);
                $this->assertNull($cursor);
                $this->assertSame(['file_list' => 'batch'], $post_data);
                $this->assertSame('file_fetch', $phase);

                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'completion',
                        'x-status' => 'complete',
                        'x-cursor' => 'done-cursor',
                        'x-files-completed' => '2',
                        'x-bytes-processed' => '100',
                    ],
                ]);
            },
            fn(string $endpoint): array => [],
            fn(): bool => false,
            function (array $state) use (&$saved_states): void {
                $saved_states[] = $state;
            },
            function (): void {
            },
            function (): void {
            },
            function (): void {
            },
            function (): void {
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
            function (string $endpoint, float $wall_time, array $stats) use (&$finalized): void {
                $finalized[] = compact('endpoint', 'wall_time', 'stats');
            },
            function (): void {
            },
            function (): void {
            },
        );

        $complete = $downloader->download(
            $state,
            [
                'post_data' => ['file_list' => 'batch'],
                'cursor' => null,
                'state_key' => 'fetch',
                'export_dirs' => ['/srv/htdocs'],
                'save_every' => 50,
            ],
        );

        $this->assertTrue($complete);
        $this->assertSame('done-cursor', $state['fetch']['cursor']);
        $this->assertSame(0, $state['consecutive_timeouts']);
        $this->assertNull($state['current_file']);
        $this->assertNull($state['current_file_bytes']);
        $this->assertNotEmpty($saved_states);
        $this->assertSame('file_fetch', $finalized[0]['endpoint']);
        $this->assertSame('complete', $finalized[0]['stats']['status']);
        $this->assertSame(2, $completion_progress[0]['files_completed']);
    }
}
