<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test filesystem root calculation
 */
class FilesystemRootTest extends FileSyncProducerTestBase
{
    public function testSymlinkToSiblingDirectory()
    {
        // Common scenario: /home/user/htdocs with symlink to ../wordpress
        // Should calculate filesystem root as /home/user, NOT reject
        $baseDir = $this->fixturesDir . '/user';
        mkdir($baseDir, 0755, true);

        // Create htdocs directory
        $htdocsDir = $baseDir . '/htdocs';
        mkdir($htdocsDir);
        file_put_contents($htdocsDir . '/index.php', '<?php');

        // Create wordpress directory (sibling)
        $wordpressDir = $baseDir . '/wordpress';
        mkdir($wordpressDir);
        file_put_contents($wordpressDir . '/wp-load.php', '<?php');

        // Create symlink from htdocs to ../wordpress
        $symlinkPath = $htdocsDir . '/__wp__';
        symlink('../wordpress', $symlinkPath);

        // This should NOT throw an error
        $sync = new \FileSyncProducer($htdocsDir, [
            'follow_symlinks' => true
        ]);

        $chunks = $this->processAllChunks($sync);

        // Should successfully complete
        $this->assertNotEmpty($chunks, 'Should process chunks without error');

        // Filesystem root should be the common parent
        $fsRoot = $sync->get_filesystem_root();
        $this->assertNotNull($fsRoot);
        $this->assertStringContainsString('user', $fsRoot, 'Filesystem root should be user directory');
        $this->assertNotEquals('/', $fsRoot, 'Should not be filesystem root');

        // Clean up
        unlink($symlinkPath);
        unlink($htdocsDir . '/index.php');
        unlink($wordpressDir . '/wp-load.php');
        rmdir($htdocsDir);
        rmdir($wordpressDir);
        rmdir($baseDir);
    }

    public function testSymlinkToParentSibling()
    {
        // /home/user/site/htdocs with symlink to ../../wordpress
        // Filesystem root should be /home/user
        $baseDir = $this->fixturesDir . '/user2';
        mkdir($baseDir, 0755, true);

        $siteDir = $baseDir . '/site';
        mkdir($siteDir);

        $htdocsDir = $siteDir . '/htdocs';
        mkdir($htdocsDir);
        file_put_contents($htdocsDir . '/index.php', '<?php');

        $wordpressDir = $baseDir . '/wordpress';
        mkdir($wordpressDir);
        file_put_contents($wordpressDir . '/wp-load.php', '<?php');

        // Symlink: ../../wordpress (goes up two levels)
        $symlinkPath = $htdocsDir . '/__wp__';
        symlink('../../wordpress', $symlinkPath);

        $sync = new \FileSyncProducer($htdocsDir, [
            'follow_symlinks' => true
        ]);

        $chunks = $this->processAllChunks($sync);

        $this->assertNotEmpty($chunks);

        $fsRoot = $sync->get_filesystem_root();
        $this->assertStringContainsString('user2', $fsRoot);

        // Clean up
        unlink($symlinkPath);
        unlink($htdocsDir . '/index.php');
        unlink($wordpressDir . '/wp-load.php');
        rmdir($htdocsDir);
        rmdir($siteDir);
        rmdir($wordpressDir);
        rmdir($baseDir);
    }

    public function testNoSymlinksUsesScannedDirectory()
    {
        // When there are no symlinks, filesystem root should be the scanned directory
        $dir = $this->createTestDirectory('no-symlinks', [
            'file1.txt' => 'Content 1',
            'file2.txt' => 'Content 2'
        ]);

        $sync = new \FileSyncProducer($dir);
        $chunks = $this->processAllChunks($sync);

        $fsRoot = $sync->get_filesystem_root();

        // Should be the scan directory itself (or its real path)
        $this->assertEquals(realpath($dir), $fsRoot);
    }

    public function testMultipleSymlinksToSameParent()
    {
        // Multiple symlinks all pointing to siblings
        $baseDir = $this->fixturesDir . '/multi-symlinks';
        mkdir($baseDir, 0755, true);

        $siteDir = $baseDir . '/site';
        mkdir($siteDir);
        file_put_contents($siteDir . '/index.php', '<?php');

        $lib1Dir = $baseDir . '/lib1';
        mkdir($lib1Dir);
        file_put_contents($lib1Dir . '/code.php', '<?php');

        $lib2Dir = $baseDir . '/lib2';
        mkdir($lib2Dir);
        file_put_contents($lib2Dir . '/code.php', '<?php');

        symlink('../lib1', $siteDir . '/lib1');
        symlink('../lib2', $siteDir . '/lib2');

        $sync = new \FileSyncProducer($siteDir, [
            'follow_symlinks' => true
        ]);

        $chunks = $this->processAllChunks($sync);

        $this->assertNotEmpty($chunks);

        $fsRoot = $sync->get_filesystem_root();
        $this->assertStringContainsString('multi-symlinks', $fsRoot);

        // Clean up
        unlink($siteDir . '/lib1');
        unlink($siteDir . '/lib2');
        unlink($siteDir . '/index.php');
        unlink($lib1Dir . '/code.php');
        unlink($lib2Dir . '/code.php');
        rmdir($lib1Dir);
        rmdir($lib2Dir);
        rmdir($siteDir);
        rmdir($baseDir);
    }

    public function testSymlinkToNonExistentTarget()
    {
        // Symlink to target that doesn't exist
        // Should not crash, should use scan directory as filesystem root
        $dir = $this->createTestDirectory('broken-link', [
            'index.php' => '<?php'
        ]);

        // Create symlink to non-existent target
        $symlinkPath = $dir . '/__wp__';
        symlink('../nonexistent/wordpress', $symlinkPath);

        // Should not throw error
        $sync = new \FileSyncProducer($dir, [
            'follow_symlinks' => true
        ]);

        $chunks = $this->processAllChunks($sync);

        $this->assertNotEmpty($chunks, 'Should complete despite broken symlink');

        $fsRoot = $sync->get_filesystem_root();
        $this->assertNotNull($fsRoot);

        // Clean up
        unlink($symlinkPath);
    }
}
