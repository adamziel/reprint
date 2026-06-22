<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\RemoteIndexDownloader;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../importer/import.php';

final class RemoteIndexDownloaderTest extends TestCase
{
    private string $remote_index_file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remote_index_file = tempnam(sys_get_temp_dir(), 'remote-index-downloader-');
        if ($this->remote_index_file === false) {
            $this->fail('Failed to allocate temp file');
        }
        unlink($this->remote_index_file);
    }

    protected function tearDown(): void
    {
        @unlink($this->remote_index_file);
        parent::tearDown();
    }

    public function testDownloadsRemoteIndexAndPersistsState(): void
    {
        $checkpoint = FilesPullCheckpoint::fresh();
        $entries_counted = 0;
        $saved_checkpoints = [];
        $finalized = [];
        $progress_counts = [];

        $downloader = new RemoteIndexDownloader(
            function (string $endpoint, ?string $cursor, array $params): string {
                $this->assertSame('file_index', $endpoint);
                $this->assertNull($cursor);
                $this->assertSame('/srv/htdocs', $params['list_dir']);
                $this->assertSame('1', $params['follow_symlinks']);
                $this->assertSame('1', $params['include_caches']);
                $this->assertSame(['/srv/htdocs'], $params['directory']);

                return 'https://example.test/export.php?endpoint=file_index';
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ): void {
                $this->assertSame('https://example.test/export.php?endpoint=file_index', $url);
                $this->assertNull($cursor);
                $this->assertNull($post_data);
                $this->assertSame('file_index', $phase);

                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'index_batch',
                        'x-cursor' => 'next-cursor',
                    ],
                    'body' => json_encode([
                        [
                            'path' => base64_encode('/srv/htdocs/wp-content/index.php'),
                            'ctime' => 123,
                            'size' => 456,
                            'type' => 'file',
                        ],
                    ]),
                ]);
                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'completion',
                        'x-status' => 'complete',
                        'x-total-entries' => '1',
                    ],
                ]);
            },
            fn(string $endpoint): array => [],
            fn(): bool => false,
            function (FilesPullCheckpoint $checkpoint) use (&$saved_checkpoints): void {
                $saved_checkpoints[] = clone $checkpoint;
            },
            function (): void {
            },
            function (): void {
            },
            function (): void {
            },
            function (int $entries_counted) use (&$progress_counts): void {
                $progress_counts[] = $entries_counted;
            },
            function (): void {
            },
            function (string $endpoint, float $wall_time, array $stats) use (&$finalized): void {
                $finalized[] = compact('endpoint', 'wall_time', 'stats');
            },
            function (string $path): int {
                return substr_count((string) file_get_contents($path), "\n");
            },
            function (): void {
            },
        );

        $complete = $downloader->download(
            $checkpoint,
            [
                'remote_index_file' => $this->remote_index_file,
                'roots' => ['/srv/htdocs'],
                'export_dirs' => ['/srv/htdocs'],
                'list_dir_override' => null,
                'follow_symlinks' => true,
                'include_caches' => true,
                'save_every' => 50,
            ],
            $entries_counted,
        );

        $this->assertTrue($complete);
        $this->assertSame(1, $entries_counted);
        $this->assertSame(
            json_encode([
                'path' => base64_encode('/srv/htdocs/wp-content/index.php'),
                'ctime' => 123,
                'size' => 456,
                'type' => 'file',
            ]) . "\n",
            file_get_contents($this->remote_index_file),
        );
        $this->assertNull($checkpoint->index_cursor);
        $this->assertSame(0, $checkpoint->consecutive_timeouts);
        $this->assertSame([1], $progress_counts);
        $this->assertNotEmpty($saved_checkpoints);
        $this->assertSame('file_index', $finalized[0]['endpoint']);
        $this->assertSame('complete', $finalized[0]['stats']['status']);
    }
}
