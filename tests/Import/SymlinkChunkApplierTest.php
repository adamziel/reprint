<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\SymlinkChunkApplier;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class SymlinkChunkApplierTest extends TestCase
{
    private string $temp_dir;
    private array $audit = [];
    private array $skipped = [];
    private array $index = [];
    private array $progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/symlink-chunk-applier-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->audit = [];
        $this->skipped = [];
        $this->index = [];
        $this->progress = [];
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testCreatesSymlinkAndUpdatesIndex(): void
    {
        $ctime = time() - 60;

        $this->make_applier()->handle($this->symlink_chunk('/test/link', 'target', $ctime));

        $link = $this->root_path() . '/test/link';
        $this->assertTrue(is_link($link));
        $this->assertSame('target', readlink($link));
        $this->assertSame([
            'path' => '/test/link',
            'ctime' => $ctime,
            'size' => 0,
            'type' => 'link',
        ], $this->index[0]);
        $this->assertSame('symlink', $this->progress[0]['type']);
    }

    public function testRejectsEscapingSymlinkTarget(): void
    {
        $this->make_applier()->handle(
            $this->symlink_chunk('/a/link', '../../../escape', time()),
        );

        $this->assertFalse(is_link($this->root_path() . '/a/link'));
        $this->assertSame('symlink_error', $this->progress[0]['type']);
        $this->assertStringContainsString('escapes filesystem root', $this->progress[0]['error']);
    }

    public function testPreserveLocalExistingPathSkipsSymlink(): void
    {
        mkdir($this->root_path() . '/test', 0755, true);
        file_put_contents($this->root_path() . '/test/link', 'existing');

        $this->make_applier(preserve_local: true)->handle(
            $this->symlink_chunk('/test/link', 'target', time()),
        );

        $this->assertFalse(is_link($this->root_path() . '/test/link'));
        $this->assertSame('existing', file_get_contents($this->root_path() . '/test/link'));
        $this->assertSame(['/test/link'], $this->skipped);
    }

    public function testSymlinkChunkReplacesExistingFile(): void
    {
        mkdir($this->root_path() . '/test', 0755, true);
        file_put_contents($this->root_path() . '/test/link', 'existing');

        $this->make_applier()->handle($this->symlink_chunk('/test/link', 'target', time()));

        $this->assertTrue(is_link($this->root_path() . '/test/link'));
        $this->assertSame('target', readlink($this->root_path() . '/test/link'));
    }

    private function make_applier(bool $preserve_local = false): SymlinkChunkApplier
    {
        return new SymlinkChunkApplier(
            $preserve_local,
            fn(string $path): string => $this->root_path() . $path,
            fn(string $path, string $local_path, string $target): string => $target,
            fn(): string => $this->root_path(),
            fn(string $path): bool => $this->path_traverses_symlink($path),
            fn(string $path): bool => $this->remove_path($path),
            function (string $dir): void {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            },
            function (string $message, bool $to_console): void {
                $this->audit[] = [$message, $to_console];
            },
            function (string $path): void {
                $this->skipped[] = $path;
            },
            function (string $path, int $ctime, int $size, string $type): void {
                $this->index[] = compact('path', 'ctime', 'size', 'type');
            },
            function (array $progress): void {
                $this->progress[] = $progress;
            },
        );
    }

    private function symlink_chunk(string $path, string $target, int $ctime): array
    {
        return [
            'headers' => [
                'x-symlink-path' => base64_encode($path),
                'x-symlink-target' => base64_encode($target),
                'x-symlink-ctime' => (string) $ctime,
            ],
        ];
    }

    private function path_traverses_symlink(string $path): bool
    {
        $root = $this->root_path();
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

    private function root_path(): string
    {
        return realpath($this->temp_dir) ?: $this->temp_dir;
    }
}
