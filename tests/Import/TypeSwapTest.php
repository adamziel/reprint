<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FileSyncLocalApplier;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Session\VolatileFileTracker;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Test type swap handling during delta sync.
 *
 * When a path changes type between syncs (e.g., symlink→file, symlink→directory),
 * the importer must replace the old entity rather than failing or leaving stale state.
 */
class TypeSwapTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-typeswap-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function makeFilesystem(): LocalImportFilesystem
    {
        return new LocalImportFilesystem(
            $this->tempDir . '/fs-root',
            'error',
            function (string $message, bool $to_console): void {
            },
        );
    }

    private function makeApplier(array &$state): FileSyncLocalApplier
    {
        return new FileSyncLocalApplier(
            $this->makeFilesystem(),
            new IndexStore(
                $this->tempDir . '/.import-index.jsonl',
                $this->tempDir . '/.import-index-updates.jsonl',
            ),
            new VolatileFileTracker($this->tempDir . '/.import-volatile-files.json'),
            new BufferedImportOutput(),
            $this->tempDir . '/fs-root',
            $this->tempDir . '/.import-remote-index.jsonl',
            'error',
            true,
            0,
            null,
            null,
            $state,
            function (string $message, bool $to_console = true): void {
            },
            function (array $progress, bool $force = false): void {
            },
        );
    }

    /**
     * ensure_directory_path should remove a symlink that blocks directory creation.
     */
    public function testEnsureDirectoryPathRemovesBlockingSymlink()
    {
        // Resolve the fs-root path so it matches the realpath() check
        // inside ensure_directory_path (on macOS, /var -> /private/var).
        $fsRoot = realpath($this->tempDir . '/fs-root');

        // Create a symlink at a path where we want a real directory
        $symlinkPath = $fsRoot . '/some-dir';
        $targetDir = $fsRoot . '/target';
        mkdir($targetDir, 0755);
        symlink($targetDir, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        // ensure_directory_path for a child should replace the symlink with a real dir
        $this->makeFilesystem()->ensure_directory_path($fsRoot . '/some-dir/child');

        $this->assertFalse(is_link($symlinkPath), 'Symlink should be removed');
        $this->assertTrue(is_dir($symlinkPath), 'Should be a real directory now');
        $this->assertTrue(is_dir($fsRoot . '/some-dir/child'), 'Child directory should exist');
    }

    /**
     * A file chunk should replace a symlink-to-directory with a regular file.
     */
    public function testFileChunkReplacesSymlinkToDirectory()
    {
        $fsRoot = $this->tempDir . '/fs-root';

        // Create a real directory and a symlink pointing to it
        $realDir = $fsRoot . '/real-target-dir';
        mkdir($realDir, 0755);
        $symlinkPath = $fsRoot . '/swapped-path';
        symlink($realDir, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        $state = [];
        $applier = $this->makeApplier($state);
        $context = new StreamingContext();
        $chunk = [
            'headers' => [
                'x-file-path' => base64_encode('/swapped-path'),
                'x-first-chunk' => '1',
                'x-last-chunk' => '1',
                'x-file-ctime' => '1234567890',
                'x-file-size' => '5',
            ],
            'body' => 'hello',
        ];

        $applier->handle_file_chunk($chunk, $context);

        // Clean up streaming context
        if ($context->file_handle) {
            fclose($context->file_handle);
        }

        $this->assertFalse(is_link($symlinkPath), 'Symlink should be removed');
        $this->assertTrue(is_file($symlinkPath), 'Should be a regular file now');
        $this->assertEquals('hello', file_get_contents($symlinkPath));
    }

    /**
     * A directory chunk should replace a symlink-to-file with a real directory.
     */
    public function testDirectoryChunkReplacesSymlinkToFile()
    {
        $fsRoot = $this->tempDir . '/fs-root';

        // Create a real file and a symlink pointing to it
        $realFile = $fsRoot . '/real-target-file';
        file_put_contents($realFile, 'content');
        $symlinkPath = $fsRoot . '/swapped-dir';
        symlink($realFile, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        $state = [];
        $applier = $this->makeApplier($state);
        $chunk = [
            'headers' => [
                'x-directory-path' => base64_encode('/swapped-dir'),
                'x-directory-ctime' => '1234567890',
            ],
        ];

        $applier->handle_directory_chunk($chunk);

        $this->assertFalse(is_link($symlinkPath), 'Symlink should be removed');
        $this->assertTrue(is_dir($symlinkPath), 'Should be a real directory now');
    }

    /**
     * After replacing a symlink with a directory, nested files should be writable.
     *
     * Simulates the scenario: symlink at path A is replaced by a directory chunk,
     * then a file chunk arrives at A/sub/file.txt.
     */
    public function testFileChunkUnderFormerSymlink()
    {
        $fsRoot = $this->tempDir . '/fs-root';

        // Create a symlink at the path
        $realFile = $fsRoot . '/target-file';
        file_put_contents($realFile, 'old');
        $symlinkPath = $fsRoot . '/parent';
        symlink($realFile, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        $state = [];
        $applier = $this->makeApplier($state);

        // Step 1: directory chunk replaces the symlink
        $applier->handle_directory_chunk([
            'headers' => [
                'x-directory-path' => base64_encode('/parent'),
                'x-directory-ctime' => '1234567890',
            ],
        ]);

        $this->assertTrue(is_dir($symlinkPath), 'Should be a real directory after dir chunk');

        // Step 2: file chunk writes a nested file
        $context = new StreamingContext();
        $applier->handle_file_chunk([
            'headers' => [
                'x-file-path' => base64_encode('/parent/sub/file.txt'),
                'x-first-chunk' => '1',
                'x-last-chunk' => '1',
                'x-file-ctime' => '1234567890',
                'x-file-size' => '7',
            ],
            'body' => 'content',
        ], $context);

        if ($context->file_handle) {
            fclose($context->file_handle);
        }

        $nestedFile = $fsRoot . '/parent/sub/file.txt';
        $this->assertTrue(file_exists($nestedFile), 'Nested file should exist');
        $this->assertEquals('content', file_get_contents($nestedFile));
    }

    /**
     * ensure_directory_path should replace a symlink with a full real directory
     * hierarchy when creating deeply nested paths.
     */
    public function testNestedFileUnderExistingSymlinkViaEnsureDirectory()
    {
        // Resolve the fs-root path so it matches the realpath() check
        // inside ensure_directory_path (on macOS, /var -> /private/var).
        $fsRoot = realpath($this->tempDir . '/fs-root');

        // Create a symlink at the top-level path component
        $targetDir = $fsRoot . '/real-target';
        mkdir($targetDir, 0755);
        $symlinkPath = $fsRoot . '/top';
        symlink($targetDir, $symlinkPath);
        $this->assertTrue(is_link($symlinkPath), 'Precondition: symlink exists');

        // Call ensure_directory_path for a deeply nested path
        $this->makeFilesystem()->ensure_directory_path($fsRoot . '/top/sub/deep');

        $this->assertFalse(is_link($symlinkPath), 'Symlink should be removed');
        $this->assertTrue(is_dir($symlinkPath), 'top should be a real directory');
        $this->assertTrue(is_dir($fsRoot . '/top/sub'), 'sub should exist');
        $this->assertTrue(is_dir($fsRoot . '/top/sub/deep'), 'deep should exist');
    }
}
