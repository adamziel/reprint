<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Protocol\PreserveLocalSkipException;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class LocalImportFilesystemTest extends TestCase
{
    private string $temp_dir;
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/local-import-filesystem-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->audit = [];
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testResolvesRemotePathUnderCanonicalRoot(): void
    {
        $filesystem = $this->make_filesystem();

        $this->assertSame($this->root_path(), $filesystem->filesystem_root_path());
        $this->assertSame(
            $this->root_path() . '/wp-content/file.txt',
            $filesystem->local_path_for_remote_path('/wp-content/file.txt'),
        );
    }

    public function testRemovePathDoesNotFollowSymlinkTargets(): void
    {
        mkdir($this->root_path() . '/tree', 0755, true);
        file_put_contents($this->root_path() . '/outside.txt', 'keep');
        symlink($this->root_path() . '/outside.txt', $this->root_path() . '/tree/link');

        $this->assertTrue(
            $this->make_filesystem()->remove_path_without_following_symlinks(
                $this->root_path() . '/tree',
            ),
        );

        $this->assertDirectoryDoesNotExist($this->root_path() . '/tree');
        $this->assertSame('keep', file_get_contents($this->root_path() . '/outside.txt'));
    }

    public function testEnsureDirectoryPathReplacesBlockingSymlink(): void
    {
        mkdir($this->root_path() . '/target', 0755);
        symlink($this->root_path() . '/target', $this->root_path() . '/top');

        $this->make_filesystem()->ensure_directory_path($this->root_path() . '/top/sub');

        $this->assertFalse(is_link($this->root_path() . '/top'));
        $this->assertDirectoryExists($this->root_path() . '/top/sub');
        $this->assertNotEmpty($this->audit);
    }

    public function testEnsureDirectoryPathPreserveLocalSkipsBlockingSymlink(): void
    {
        mkdir($this->root_path() . '/target', 0755);
        symlink($this->root_path() . '/target', $this->root_path() . '/top');

        $this->expectException(PreserveLocalSkipException::class);
        $this->make_filesystem('preserve-local')->ensure_directory_path(
            $this->root_path() . '/top/sub',
        );
    }

    public function testSymlinkTargetOutsideRootIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes filesystem root');

        $this->make_filesystem()->assert_symlink_target_within_root(
            $this->root_path() . '/site',
            '../../outside',
            $this->root_path(),
        );
    }

    private function make_filesystem(string $mode = 'error'): LocalImportFilesystem
    {
        return new LocalImportFilesystem(
            $this->temp_dir,
            $mode,
            function (string $message, bool $to_console): void {
                $this->audit[] = [$message, $to_console];
            },
        );
    }

    private function root_path(): string
    {
        return realpath($this->temp_dir) ?: $this->temp_dir;
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
