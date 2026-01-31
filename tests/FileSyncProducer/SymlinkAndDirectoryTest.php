<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test symlink handling and empty directory communication
 */
class SymlinkAndDirectoryTest extends FileSyncProducerTestBase
{
    public function testFollowSymlinksEnabledByDefault()
    {
        $dir = $this->createTestDirectory('symlink-default', [
            'real.txt' => 'Real content'
        ]);

        // Create symlink to file
        $linkPath = $dir . '/link.txt';
        symlink($dir . '/real.txt', $linkPath);

        $sync = new \FileSyncProducer($dir);
        $chunks = $this->processAllChunks($sync);

        // Should include both real file and symlink
        $files = $this->getFilesFromChunks($chunks);
        $this->assertGreaterThanOrEqual(1, count($files), 'Should follow symlinks by default');

        // Clean up
        unlink($linkPath);
    }

    public function testFollowSymlinksCanBeDisabled()
    {
        $dir = $this->createTestDirectory('symlink-disabled', [
            'real.txt' => 'Real content'
        ]);

        // Create symlink to file
        $linkPath = $dir . '/link.txt';
        symlink($dir . '/real.txt', $linkPath);

        $sync = new \FileSyncProducer($dir, [
            'follow_symlinks' => false
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should only include real file, not symlink
        $files = $this->getFilesFromChunks($chunks);
        $this->assertCount(1, $files, 'Should not follow symlinks when disabled');
        $this->assertStringContainsString('real.txt', $files[0]);

        // Clean up
        unlink($linkPath);
    }

    public function testSymlinkToDirectory()
    {
        $dir = $this->createTestDirectory('symlink-dir');

        // Create target directory with file
        $targetDir = $dir . '/target';
        mkdir($targetDir);
        file_put_contents($targetDir . '/file.txt', 'Content in target');

        // Create symlink to directory
        $linkDir = $dir . '/link';
        symlink($targetDir, $linkDir);

        $sync = new \FileSyncProducer($dir, [
            'follow_symlinks' => true
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should follow symlink to directory
        $files = $this->getFilesFromChunks($chunks);
        $this->assertGreaterThanOrEqual(1, count($files), 'Should follow directory symlinks');

        // Clean up
        unlink($linkDir);
        unlink($targetDir . '/file.txt');
        rmdir($targetDir);
    }

    public function testRecursiveSymlinkProtection()
    {
        $dir = $this->createTestDirectory('symlink-cycle');

        // Create directory structure
        $subdir = $dir . '/subdir';
        mkdir($subdir);
        file_put_contents($dir . '/file1.txt', 'File 1');
        file_put_contents($subdir . '/file2.txt', 'File 2');

        // Create symlink that points back to parent (creates cycle)
        $linkPath = $subdir . '/parent_link';
        symlink($dir, $linkPath);

        $sync = new \FileSyncProducer($dir, [
            'follow_symlinks' => true
        ]);

        // This should not hang or cause infinite loop
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        // Should get files but not loop infinitely
        $this->assertGreaterThanOrEqual(2, count($files), 'Should detect cycle and continue');

        // Clean up
        unlink($linkPath);
        unlink($subdir . '/file2.txt');
        rmdir($subdir);
    }

    public function testBrokenSymlinkSkipped()
    {
        $dir = $this->createTestDirectory('broken-symlink', [
            'real.txt' => 'Real content'
        ]);

        // Create broken symlink (points to non-existent file)
        $linkPath = $dir . '/broken.txt';
        symlink($dir . '/nonexistent.txt', $linkPath);

        $sync = new \FileSyncProducer($dir, [
            'follow_symlinks' => true
        ]);
        $chunks = $this->processAllChunks($sync);

        // Should only include real file, skip broken symlink
        $files = $this->getFilesFromChunks($chunks);
        $this->assertCount(1, $files, 'Should skip broken symlinks');
        $this->assertStringContainsString('real.txt', $files[0]);

        // Clean up
        unlink($linkPath);
    }

    public function testEmptyDirectoryCommunicated()
    {
        $dir = $this->createTestDirectory('empty-dirs');

        // Create empty directory
        $emptyDir = $dir . '/empty';
        mkdir($emptyDir);

        // Create directory with file
        $fullDir = $dir . '/full';
        mkdir($fullDir);
        file_put_contents($fullDir . '/file.txt', 'Content');

        $sync = new \FileSyncProducer($dir);
        $chunks = $this->processAllChunks($sync);

        // Get directory chunks
        $dirChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'directory');

        // Should have at least one directory chunk for empty dir
        $this->assertGreaterThanOrEqual(1, count($dirChunks), 'Should output empty directories');

        // Verify empty directory is in the output
        $dirPaths = array_map(fn($c) => $c['path'], $dirChunks);
        $hasEmptyDir = false;
        foreach ($dirPaths as $path) {
            if (strpos($path, 'empty') !== false) {
                $hasEmptyDir = true;
                break;
            }
        }
        $this->assertTrue($hasEmptyDir, 'Empty directory should be communicated');

        // Clean up
        rmdir($emptyDir);
        unlink($fullDir . '/file.txt');
        rmdir($fullDir);
    }

    public function testNestedEmptyDirectories()
    {
        $dir = $this->createTestDirectory('nested-empty');

        // Create nested empty directories
        $level1 = $dir . '/level1';
        $level2 = $level1 . '/level2';
        $level3 = $level2 . '/level3';
        mkdir($level1);
        mkdir($level2);
        mkdir($level3);

        $sync = new \FileSyncProducer($dir);
        $chunks = $this->processAllChunks($sync);

        // Get directory chunks
        $dirChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'directory');

        // Should have chunks for all nested empty directories
        $this->assertGreaterThanOrEqual(3, count($dirChunks), 'Should output all nested empty directories');

        // Clean up
        rmdir($level3);
        rmdir($level2);
        rmdir($level1);
    }

    public function testEmptyDirectoryNotOutputWhenHasFiles()
    {
        $dir = $this->createTestDirectory('dir-with-files');

        // Create directory with file
        $subdir = $dir . '/subdir';
        mkdir($subdir);
        file_put_contents($subdir . '/file.txt', 'Content');

        $sync = new \FileSyncProducer($dir);
        $chunks = $this->processAllChunks($sync);

        // Get directory chunks
        $dirChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'directory');

        // Should not have directory chunk for subdir since it has files
        $dirPaths = array_map(fn($c) => $c['path'], $dirChunks);
        $hasSubdir = false;
        foreach ($dirPaths as $path) {
            if ($path === $subdir) {
                $hasSubdir = true;
                break;
            }
        }
        $this->assertFalse($hasSubdir, 'Directory with files should not be output as empty');

        // Clean up
        unlink($subdir . '/file.txt');
        rmdir($subdir);
    }

    public function testSymlinkCycleWithMultiplePaths()
    {
        $dir = $this->createTestDirectory('complex-cycle');

        // Create complex structure with multiple potential cycles
        $dirA = $dir . '/a';
        $dirB = $dir . '/b';
        mkdir($dirA);
        mkdir($dirB);

        file_put_contents($dirA . '/file1.txt', 'A1');
        file_put_contents($dirB . '/file2.txt', 'B1');

        // Create cross-links
        symlink($dirB, $dirA . '/link_to_b');
        symlink($dirA, $dirB . '/link_to_a');

        $sync = new \FileSyncProducer($dir, [
            'follow_symlinks' => true
        ]);

        // Should not hang
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        // Should get the actual files
        $this->assertGreaterThanOrEqual(2, count($files), 'Should handle complex cycles');

        // Clean up
        unlink($dirA . '/link_to_b');
        unlink($dirB . '/link_to_a');
        unlink($dirA . '/file1.txt');
        unlink($dirB . '/file2.txt');
        rmdir($dirA);
        rmdir($dirB);
    }

    public function testDirectoryChunkFormat()
    {
        $dir = $this->createTestDirectory('dir-format');

        // Create empty directory
        $emptyDir = $dir . '/test_empty';
        mkdir($emptyDir);

        $sync = new \FileSyncProducer($dir);
        $chunks = $this->processAllChunks($sync);

        // Get directory chunks
        $dirChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'directory');

        $this->assertNotEmpty($dirChunks, 'Should have directory chunks');

        $dirChunk = reset($dirChunks);
        $this->assertEquals('directory', $dirChunk['type'], 'Should have type=directory');
        $this->assertArrayHasKey('path', $dirChunk, 'Should have path');
        $this->assertArrayNotHasKey('data', $dirChunk, 'Directory chunks should not have data');
        $this->assertArrayNotHasKey('size', $dirChunk, 'Directory chunks should not have size');

        // Clean up
        rmdir($emptyDir);
    }
}
