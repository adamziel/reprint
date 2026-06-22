<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\DirectoryChunkApplier;
use Reprint\Importer\FileSync\Port\LocalFileApplyContext;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class DirectoryChunkApplierTest extends TestCase
{
    public string $temp_dir;
    public array $audit = [];
    public array $skipped = [];
    public array $index = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/directory-chunk-applier-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->audit = [];
        $this->skipped = [];
        $this->index = [];
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testCreatesDirectoryAndUpdatesIndex(): void
    {
        $ctime = time() - 60;

        $this->make_applier()->handle($this->directory_chunk('/wp-content/uploads', $ctime));

        $this->assertDirectoryExists($this->temp_dir . '/wp-content/uploads');
        $this->assertSame([
            'path' => '/wp-content/uploads',
            'ctime' => $ctime,
            'size' => 0,
            'type' => 'dir',
        ], $this->index[0]);
        $this->assertSame('Directory: /wp-content/uploads', $this->audit[0][0]);
    }

    public function testPreserveLocalExistingDirectorySkipsAndIndexes(): void
    {
        mkdir($this->temp_dir . '/wp-content/uploads', 0755, true);
        $ctime = time() - 60;

        $this->make_applier(preserve_local: true)->handle(
            $this->directory_chunk('/wp-content/uploads', $ctime),
        );

        $this->assertSame(['/wp-content/uploads'], $this->skipped);
        $this->assertSame('/wp-content/uploads', $this->index[0]['path']);
        $this->assertStringContainsString('PRESERVE-LOCAL skip directory', $this->audit[0][0]);
    }

    public function testDirectoryChunkReplacesExistingSymlink(): void
    {
        file_put_contents($this->temp_dir . '/target-file', 'old');
        symlink($this->temp_dir . '/target-file', $this->temp_dir . '/swapped-dir');

        $this->make_applier()->handle($this->directory_chunk('/swapped-dir', time()));

        $this->assertFalse(is_link($this->temp_dir . '/swapped-dir'));
        $this->assertDirectoryExists($this->temp_dir . '/swapped-dir');
    }

    private function make_applier(bool $preserve_local = false): DirectoryChunkApplier
    {
        $test = $this;
        $context = new class($test) implements LocalFileApplyContext {
            private DirectoryChunkApplierTest $test;

            public function __construct(DirectoryChunkApplierTest $test)
            {
                $this->test = $test;
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
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            public function path_traverses_symlink(string $path): bool
            {
                return $this->test->path_traverses_symlink($path);
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
            }

            public function set_current_file(?string $path, ?int $bytes): void
            {
            }

            public function output_progress(array $progress, bool $force = false): void
            {
            }
        };

        return new DirectoryChunkApplier(
            $preserve_local,
            $context,
        );
    }

    private function directory_chunk(string $path, int $ctime): array
    {
        return [
            'headers' => [
                'x-directory-path' => base64_encode($path),
                'x-directory-ctime' => (string) $ctime,
            ],
        ];
    }

    public function path_traverses_symlink(string $path): bool
    {
        $root = realpath($this->temp_dir);
        if ($root === false || !str_starts_with($path, $root)) {
            return true;
        }

        $relative = ltrim(substr($path, strlen($root)), '/');
        $current = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            if (is_link($current)) {
                return true;
            }
            if (!file_exists($current)) {
                break;
            }
        }
        return false;
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
