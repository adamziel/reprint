<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\DownloadList;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class ImportDownloadListTest extends TestCase
{
    private string $temp_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/import-download-list-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp_dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->temp_dir);
        parent::tearDown();
    }

    public function testAppendAndReadPath(): void
    {
        $path = $this->temp_dir . '/download-list.jsonl';
        $handle = fopen($path, 'w');
        DownloadList::append_path($handle, '/wp-content/uploads/image.jpg');
        fclose($handle);

        $line = file_get_contents($path);
        $this->assertSame('/wp-content/uploads/image.jpg', DownloadList::read_path($line));
    }

    public function testReadPathSupportsLegacyStringLines(): void
    {
        $this->assertSame('/legacy.txt', DownloadList::read_path('"/legacy.txt"'));
        $this->assertNull(DownloadList::read_path('{"path":"not-base64!"}'));
        $this->assertNull(DownloadList::read_path(''));
    }

    public function testCountLinesCanStopAtByteOffset(): void
    {
        $path = $this->write_list(['/a.txt', '/b.txt', '/c.txt']);
        $handle = fopen($path, 'r');
        fgets($handle);
        fgets($handle);
        $offset = ftell($handle);
        fclose($handle);

        $this->assertSame(3, DownloadList::count_lines($path));
        $this->assertSame(2, DownloadList::count_lines($path, $offset));
    }

    public function testPrepareBatchWritesJsonArray(): void
    {
        $path = $this->write_list(['/a.txt', '/b.txt']);
        $batch = DownloadList::prepare_batch($path, 0, 4 * 1024 * 1024);

        $this->assertNotNull($batch);
        $this->assertSame(0, $batch['offset']);
        $this->assertSame(2, $batch['entries']);
        $this->assertGreaterThan(0, $batch['next_offset']);
        $this->assertSame(['/a.txt', '/b.txt'], json_decode(file_get_contents($batch['file']), true));

        @unlink($batch['file']);
    }

    private function write_list(array $paths): string
    {
        $path = $this->temp_dir . '/download-list.jsonl';
        $handle = fopen($path, 'w');
        foreach ($paths as $entry) {
            DownloadList::append_path($handle, $entry);
        }
        fclose($handle);
        return $path;
    }
}
