<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FileChunkApplier;
use Reprint\Importer\FileSync\Port\LocalFileApplyContext;
use Reprint\Importer\Protocol\PreserveLocalSkipException;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class FileChunkApplierTest extends TestCase
{
    public string $temp_dir;
    public array $audit = [];
    public array $started = [];
    public array $skipped = [];
    public array $index = [];
    public array $volatile = [];
    public array $current_file = ['path' => 'pending', 'bytes' => 10];

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
        $test = $this;
        $context = new class($test, $ensure_directory) implements LocalFileApplyContext {
            private FileChunkApplierTest $test;
            private $ensure_directory;

            public function __construct(FileChunkApplierTest $test, ?callable $ensure_directory)
            {
                $this->test = $test;
                $this->ensure_directory = $ensure_directory;
            }

            public function local_path_for_remote_path(string $path): string
            {
                return $this->test->temp_dir . $path;
            }

            public function remove_path_without_following_symlinks(string $local_path): bool
            {
                return $this->test->remove_path($local_path);
            }

            public function ensure_directory_path(string $dir): void
            {
                if ($this->ensure_directory) {
                    ($this->ensure_directory)($dir);
                    return;
                }
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            public function path_traverses_symlink(string $path): bool
            {
                return false;
            }

            public function filesystem_root_path(): string
            {
                return $this->test->temp_dir;
            }

            public function map_absolute_symlink_target_for_local_mirror(
                string $path,
                string $local_path,
                string $target
            ): string {
                return $target;
            }

            public function audit(string $message, bool $to_console = true): void
            {
                $this->test->audit[] = [$message, $to_console];
            }

            public function show_file_fetch_progress(string $path, int $file_size): void
            {
                $this->test->started[] = ['path' => $path, 'size' => $file_size];
            }

            public function emit_skip_progress(string $path): void
            {
                $this->test->skipped[] = $path;
            }

            public function upsert_index_entry(string $path, int $ctime, int $size, string $type): void
            {
                $this->test->index[] = compact('path', 'ctime', 'size', 'type');
            }

            public function clear_volatile_file(string $path): void
            {
                $this->test->volatile[] = $path;
            }

            public function set_current_file(?string $path, ?int $bytes): void
            {
                $this->test->current_file = ['path' => $path, 'bytes' => $bytes];
            }

            public function output_progress(array $progress, bool $force = false): void
            {
            }
        };

        return new FileChunkApplier(
            0,
            $context,
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

    public function remove_path(string $path): bool
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
