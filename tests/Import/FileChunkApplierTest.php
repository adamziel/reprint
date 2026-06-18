<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FileChunkApplier;
use Reprint\Importer\Protocol\PreserveLocalSkipException;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class FileChunkApplierTest extends TestCase
{
    private string $temp_dir;
    private array $audit = [];
    private array $started = [];
    private array $skipped = [];
    private array $index = [];
    private array $volatile = [];
    private array $current_file = ['path' => 'pending', 'bytes' => 10];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/file-chunk-applier-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->audit = [];
        $this->started = [];
        $this->skipped = [];
        $this->index = [];
        $this->volatile = [];
        $this->current_file = ['path' => 'pending', 'bytes' => 10];
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testStreamsFileAndUpdatesIndex(): void
    {
        $context = new StreamingContext();
        $applier = $this->make_applier();
        $ctime = time() - 60;

        $applier->handle(
            $this->file_chunk('/wp-content/test.txt', 'hello', $ctime, false, true),
            $context,
        );
        $applier->handle(
            $this->file_chunk('/wp-content/test.txt', 'world', $ctime, true, false),
            $context,
        );

        $local_path = $this->temp_dir . '/wp-content/test.txt';
        $this->assertSame('helloworld', file_get_contents($local_path));
        $this->assertSame(1, $applier->files_imported());
        $this->assertSame([
            'path' => '/wp-content/test.txt',
            'ctime' => $ctime,
            'size' => 10,
            'type' => 'file',
        ], $this->index[0]);
        $this->assertSame(['/wp-content/test.txt'], $this->volatile);
        $this->assertSame(['path' => null, 'bytes' => null], $this->current_file);
        $this->assertSame([['path' => '/wp-content/test.txt', 'size' => 10]], $this->started);
    }

    public function testPreserveLocalSkipLeavesFileUnwritten(): void
    {
        $context = new StreamingContext();
        $applier = $this->make_applier(
            ensure_directory: function (string $dir): void {
                throw new PreserveLocalSkipException("skip {$dir}");
            },
        );

        $applier->handle(
            $this->file_chunk('/blocked/test.txt', 'body', time(), true, true),
            $context,
        );

        $this->assertTrue($context->skip_current_file);
        $this->assertFileDoesNotExist($this->temp_dir . '/blocked/test.txt');
        $this->assertSame(['/blocked/test.txt'], $this->skipped);
        $this->assertSame(0, $applier->files_imported());
    }

    public function testFileChunkReplacesExistingSymlink(): void
    {
        mkdir($this->temp_dir . '/wp-content', 0755, true);
        symlink('target', $this->temp_dir . '/wp-content/test.txt');

        $context = new StreamingContext();
        $applier = $this->make_applier();

        $applier->handle(
            $this->file_chunk('/wp-content/test.txt', 'body', time(), true, true),
            $context,
        );

        $local_path = $this->temp_dir . '/wp-content/test.txt';
        $this->assertFalse(is_link($local_path));
        $this->assertSame('body', file_get_contents($local_path));
    }

    private function make_applier(?callable $ensure_directory = null): FileChunkApplier
    {
        return new FileChunkApplier(
            0,
            fn(string $path): string => $this->temp_dir . $path,
            fn(string $path): bool => $this->remove_path($path),
            $ensure_directory ?? function (string $dir): void {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            },
            function (string $message, bool $to_console): void {
                $this->audit[] = [$message, $to_console];
            },
            function (string $path, int $file_size): void {
                $this->started[] = ['path' => $path, 'size' => $file_size];
            },
            function (string $path): void {
                $this->skipped[] = $path;
            },
            function (string $path, int $ctime, int $size, string $type): void {
                $this->index[] = compact('path', 'ctime', 'size', 'type');
            },
            function (string $path): void {
                $this->volatile[] = $path;
            },
            function (?string $path, ?int $bytes): void {
                $this->current_file = ['path' => $path, 'bytes' => $bytes];
            },
        );
    }

    private function file_chunk(
        string $path,
        string $body,
        int $ctime,
        bool $last,
        bool $first
    ): array {
        return [
            'headers' => [
                'x-file-path' => base64_encode($path),
                'x-file-size' => '10',
                'x-file-ctime' => (string) $ctime,
                'x-first-chunk' => $first ? '1' : '0',
                'x-last-chunk' => $last ? '1' : '0',
            ],
            'body' => $body,
        ];
    }

    private function remove_path(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_link($path) || is_file($path)) {
            return unlink($path);
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->remove_path($path . '/' . $entry)) {
                return false;
            }
        }
        return rmdir($path);
    }
}
