<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test symlink following behavior - ensure symlinks and their targets use correct paths
 */
class SymlinkFollowingTest extends FileSyncProducerTestBase
{
    public function testSymlinkAndTargetHaveDifferentPaths()
    {
        // Create target file
        $dir = $this->createTestDirectory('symlink-paths', [
            'target/file.txt' => 'Target content'
        ]);

        // Create symlink
        $linkPath = $dir . '/link.txt';
        symlink($dir . '/target/file.txt', $linkPath);

        $sync = new \FileSyncProducer($dir, [
            'follow_symlinks' => true
        ]);

        $chunks = $this->processAllChunks($sync);

        // Find symlink chunk
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertCount(1, $symlinkChunks, 'Should have one symlink chunk');

        $symlinkChunk = reset($symlinkChunks);
        $this->assertStringContainsString('link.txt', $symlinkChunk['path'],
            'Symlink chunk should have symlink path');

        // Find file chunks
        $fileChunks = array_filter($chunks, fn($c) =>
            ($c['type'] ?? 'file') === 'file' &&
            isset($c['data']) &&
            $c['data'] === 'Target content'
        );
        $this->assertNotEmpty($fileChunks, 'Should have file chunks for target');

        $fileChunk = reset($fileChunks);
        $this->assertStringContainsString('target/file.txt', $fileChunk['path'],
            'File chunk should have TARGET path, not symlink path');
        $this->assertStringNotContainsString('link.txt', $fileChunk['path'],
            'File chunk should NOT have symlink path');

        // Clean up
        unlink($linkPath);
    }

    public function testSymlinkToExternalFileUsesRealPath()
    {
        // Symlink to a file outside the scanned directory
        $baseDir = $this->fixturesDir . '/external-test';
        mkdir($baseDir, 0755, true);

        $scanDir = $baseDir . '/site';
        mkdir($scanDir);

        $externalDir = $baseDir . '/external';
        mkdir($externalDir);
        file_put_contents($externalDir . '/lib.php', 'External library');

        // Create symlink from site to external
        $linkPath = $scanDir . '/lib.php';
        symlink($externalDir . '/lib.php', $linkPath);

        $sync = new \FileSyncProducer($scanDir, [
            'follow_symlinks' => true
        ]);

        $chunks = $this->processAllChunks($sync);

        // Symlink chunk should reference the symlink
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertNotEmpty($symlinkChunks);

        $symlinkChunk = reset($symlinkChunks);
        $this->assertStringContainsString('/site/lib.php', $symlinkChunk['path']);

        // File chunk should reference the REAL path
        $fileChunks = array_filter($chunks, fn($c) =>
            isset($c['data']) && $c['data'] === 'External library'
        );
        $this->assertNotEmpty($fileChunks);

        $fileChunk = reset($fileChunks);
        $this->assertStringContainsString('/external/lib.php', $fileChunk['path'],
            'File chunk must use real path, not symlink path');

        // Clean up
        unlink($linkPath);
        unlink($externalDir . '/lib.php');
        rmdir($externalDir);
        rmdir($scanDir);
        rmdir($baseDir);
    }

    public function testMultipleSymlinksToSameTargetOnlyExportTargetOnce()
    {
        $dir = $this->createTestDirectory('multiple-links', [
            'target.txt' => 'Shared content'
        ]);

        // Create multiple symlinks to same target
        $link1 = $dir . '/link1.txt';
        $link2 = $dir . '/link2.txt';
        $link3 = $dir . '/link3.txt';

        $targetPath = $dir . '/target.txt';
        symlink($targetPath, $link1);
        symlink($targetPath, $link2);
        symlink($targetPath, $link3);

        $sync = new \FileSyncProducer($dir, [
            'follow_symlinks' => true
        ]);

        $chunks = $this->processAllChunks($sync);

        // Should have 3 symlink chunks
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertCount(3, $symlinkChunks, 'Should have 3 symlink chunks');

        // Should only export the target file ONCE (not 3 times)
        $fileChunks = array_filter($chunks, fn($c) =>
            isset($c['data']) && $c['data'] === 'Shared content'
        );

        // Count unique file paths
        $filePaths = array_unique(array_map(fn($c) => $c['path'], $fileChunks));
        $this->assertCount(1, $filePaths,
            'Should only export target file once, even with multiple symlinks');

        $targetFilePath = reset($filePaths);
        $this->assertStringContainsString('target.txt', $targetFilePath);

        // Clean up
        unlink($link1);
        unlink($link2);
        unlink($link3);
    }

    public function testSymlinkToDirectoryExportsDirectoryContents()
    {
        $baseDir = $this->fixturesDir . '/dir-symlink-test';
        mkdir($baseDir, 0755, true);

        $siteDir = $baseDir . '/site';
        mkdir($siteDir);

        $libDir = $baseDir . '/lib';
        mkdir($libDir);
        file_put_contents($libDir . '/util.php', 'Utility');
        file_put_contents($libDir . '/helper.php', 'Helper');

        // Symlink to directory
        $linkDir = $siteDir . '/lib';
        symlink($libDir, $linkDir);

        $sync = new \FileSyncProducer($siteDir, [
            'follow_symlinks' => true
        ]);

        $chunks = $this->processAllChunks($sync);

        // Should have symlink chunk for the directory link
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertNotEmpty($symlinkChunks);

        // Should have file chunks for files INSIDE the linked directory
        // And they should use the REAL paths (not through the symlink)
        $fileChunks = array_filter($chunks, fn($c) => isset($c['data']));

        $paths = array_map(fn($c) => $c['path'], $fileChunks);

        // Files should be at the REAL location
        $hasRealPaths = false;
        foreach ($paths as $path) {
            if (strpos($path, '/lib/util.php') !== false || strpos($path, '/lib/helper.php') !== false) {
                // Should NOT be under /site/lib
                $this->assertStringNotContainsString('/site/lib/', $path,
                    'Files inside symlinked directory should use real paths');
                $hasRealPaths = true;
            }
        }
        $this->assertTrue($hasRealPaths, 'Should have exported files from symlinked directory');

        // Clean up
        unlink($linkDir);
        unlink($libDir . '/util.php');
        unlink($libDir . '/helper.php');
        rmdir($libDir);
        rmdir($siteDir);
        rmdir($baseDir);
    }

    public function testFollowSymlinksDisabledDoesNotFollowSymlink()
    {
        // Create target OUTSIDE the scan directory
        $baseDir = $this->fixturesDir . '/no-follow-test';
        mkdir($baseDir, 0755, true);

        $scanDir = $baseDir . '/site';
        mkdir($scanDir);
        file_put_contents($scanDir . '/regular.txt', 'Regular file');

        $externalDir = $baseDir . '/external';
        mkdir($externalDir);
        file_put_contents($externalDir . '/target.txt', 'External target');

        // Symlink to external file
        $linkPath = $scanDir . '/link.txt';
        symlink($externalDir . '/target.txt', $linkPath);

        $sync = new \FileSyncProducer($scanDir, [
            'follow_symlinks' => false
        ]);

        $chunks = $this->processAllChunks($sync);

        // Should have symlink chunk
        $symlinkChunks = array_filter($chunks, fn($c) => ($c['type'] ?? 'file') === 'symlink');
        $this->assertNotEmpty($symlinkChunks, 'Should record symlink');

        // Should have regular.txt
        $regularFileChunks = array_filter($chunks, fn($c) =>
            isset($c['data']) && $c['data'] === 'Regular file'
        );
        $this->assertNotEmpty($regularFileChunks, 'Should export regular files');

        // Should NOT have the external target content
        $externalChunks = array_filter($chunks, fn($c) =>
            isset($c['data']) && $c['data'] === 'External target'
        );
        $this->assertEmpty($externalChunks,
            'Should not follow symlink when follow_symlinks is false');

        // Clean up
        unlink($linkPath);
        unlink($externalDir . '/target.txt');
        unlink($scanDir . '/regular.txt');
        rmdir($externalDir);
        rmdir($scanDir);
        rmdir($baseDir);
    }
}
