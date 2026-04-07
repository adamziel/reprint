<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ChunkWriter — the filesystem writer used by file_receive.
 *
 * ChunkWriter is the last line of defense before arbitrary file data
 * is written to the production filesystem. If it fails to validate paths,
 * an attacker (or a corrupted push) can overwrite system files.
 */
final class ChunkWriterTest extends TestCase
{
    private $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->recursiveDelete($dir);
        }
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/chunk-writer-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $item) {
                if ($item === '.' || $item === '..') continue;
                $this->recursiveDelete($path . '/' . $item);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    // ---------------------------------------------------------------
    // Basic file writing
    // ---------------------------------------------------------------

    /**
     * Write a single-chunk file.
     */
    public function testWriteSingleChunkFile(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $writer->write_file_chunk('/test.txt', 'Hello, World!', true, true);

        $this->assertFileExists($root . '/test.txt');
        $this->assertSame('Hello, World!', file_get_contents($root . '/test.txt'));
    }

    /**
     * Write a multi-chunk file.
     */
    public function testWriteMultiChunkFile(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $writer->write_file_chunk('/large.bin', 'chunk1-', true, false);
        $writer->write_file_chunk('/large.bin', 'chunk2-', false, false);
        $writer->write_file_chunk('/large.bin', 'chunk3', false, true);

        $this->assertSame('chunk1-chunk2-chunk3', file_get_contents($root . '/large.bin'));
    }

    /**
     * Write a file in a nested directory that doesn't exist yet.
     */
    public function testCreatesIntermediateDirectories(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $writer->write_file_chunk(
            '/wp-content/uploads/2024/01/photo.jpg',
            'JPEG data',
            true,
            true
        );

        $this->assertFileExists($root . '/wp-content/uploads/2024/01/photo.jpg');
        $this->assertSame('JPEG data', file_get_contents($root . '/wp-content/uploads/2024/01/photo.jpg'));
    }

    /**
     * Overwriting a file on a new push.
     */
    public function testOverwritesExistingFile(): void
    {
        $root = $this->createTempDir();
        file_put_contents($root . '/existing.txt', 'old content');

        $writer = new ChunkWriter($root);
        $writer->write_file_chunk('/existing.txt', 'new content', true, true);

        $this->assertSame('new content', file_get_contents($root . '/existing.txt'));
    }

    // ---------------------------------------------------------------
    // Directory creation
    // ---------------------------------------------------------------

    public function testWriteDirectory(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $writer->write_directory('/wp-content/uploads/2024/');

        $this->assertTrue(is_dir($root . '/wp-content/uploads/2024'));
    }

    // ---------------------------------------------------------------
    // Symlink handling
    // ---------------------------------------------------------------

    /**
     * Valid symlink within root should be created.
     */
    public function testValidSymlinkWithinRoot(): void
    {
        $root = $this->createTempDir();
        mkdir($root . '/target-dir', 0755, true);
        file_put_contents($root . '/target-dir/file.txt', 'content');

        $writer = new ChunkWriter($root);
        $writer->write_symlink('/link', 'target-dir', 0);

        $this->assertTrue(is_link($root . '/link'));
        $this->assertSame('target-dir', readlink($root . '/link'));
    }

    /**
     * SECURITY: Symlink that escapes root must be rejected.
     */
    public function testSymlinkEscapingRootIsRejected(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink target escapes root');

        $writer->write_symlink('/evil-link', '../../etc/passwd', 0);
    }

    /**
     * SECURITY: Absolute symlink target outside root must be rejected.
     */
    public function testAbsoluteSymlinkOutsideRootIsRejected(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink target escapes root');

        $writer->write_symlink('/evil-link', '/etc/passwd', 0);
    }

    // ---------------------------------------------------------------
    // Path traversal attacks
    // ---------------------------------------------------------------

    /**
     * SECURITY: Path with dot-dot segments must be rejected.
     */
    public function testPathTraversalIsRejected(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $this->expectException(RuntimeException::class);

        $writer->write_file_chunk(
            '/../../../etc/crontab',
            'malicious content',
            true,
            true
        );
    }

    /**
     * SECURITY: Path with NUL byte must be rejected.
     */
    public function testNulByteInPathIsRejected(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $this->expectException(RuntimeException::class);

        $writer->write_file_chunk(
            "/test.php\x00.jpg",
            '<?php evil();',
            true,
            true
        );
    }

    // ---------------------------------------------------------------
    // Incomplete writes
    // ---------------------------------------------------------------

    /**
     * If a multi-chunk write is interrupted (no last chunk), the next
     * first chunk for a different file must close the previous file.
     */
    public function testInterruptedMultiChunkWriteIsClosed(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        // Start writing file A but never send the last chunk
        $writer->write_file_chunk('/file-a.txt', 'partial data', true, false);

        // Start writing file B (is_first=true should close file A)
        $writer->write_file_chunk('/file-b.txt', 'complete', true, true);

        // Both files should exist
        $this->assertFileExists($root . '/file-a.txt');
        $this->assertFileExists($root . '/file-b.txt');

        // File A has partial data (it was closed when file B started)
        $this->assertSame('partial data', file_get_contents($root . '/file-a.txt'));
        $this->assertSame('complete', file_get_contents($root . '/file-b.txt'));
    }

    /**
     * Destructor should close any open file handle.
     */
    public function testDestructorClosesOpenFile(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $writer->write_file_chunk('/orphan.txt', 'data', true, false);
        // Destroy without sending last chunk
        unset($writer);

        $this->assertFileExists($root . '/orphan.txt');
        $this->assertSame('data', file_get_contents($root . '/orphan.txt'));
    }

    // ---------------------------------------------------------------
    // Symlink in directory path
    // ---------------------------------------------------------------

    /**
     * SECURITY: If a directory component in the path is a symlink pointing
     * outside root, writing through it would escape the root.
     *
     * ChunkWriter's ensure_directory removes symlinks that block directory
     * creation. This test verifies that behavior.
     */
    public function testSymlinkInDirectoryPathIsRemoved(): void
    {
        $root = $this->createTempDir();
        $outside = $this->createTempDir();

        // Create a symlink at root/wp-content pointing outside
        symlink($outside, $root . '/wp-content');

        $writer = new ChunkWriter($root);

        // Writing to /wp-content/file.txt should remove the symlink
        // and create a real directory instead
        $writer->write_file_chunk('/wp-content/file.txt', 'safe content', true, true);

        $this->assertFalse(is_link($root . '/wp-content'), "Symlink should be replaced with real directory");
        $this->assertTrue(is_dir($root . '/wp-content'));
        $this->assertSame('safe content', file_get_contents($root . '/wp-content/file.txt'));

        // The outside directory should NOT have the file
        $this->assertFalse(file_exists($outside . '/file.txt'));
    }

    // ---------------------------------------------------------------
    // Binary data integrity
    // ---------------------------------------------------------------

    /**
     * Binary data (images, executables) must be written byte-for-byte.
     */
    public function testBinaryDataIntegrity(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        // Generate binary data with all byte values
        $binary = '';
        for ($i = 0; $i < 256; $i++) {
            $binary .= chr($i);
        }
        $binary = str_repeat($binary, 10); // 2560 bytes

        $writer->write_file_chunk('/binary.bin', $binary, true, true);

        $this->assertSame(
            $binary,
            file_get_contents($root . '/binary.bin'),
            "Binary data must be preserved byte-for-byte"
        );
    }

    /**
     * Empty file (zero bytes).
     */
    public function testEmptyFile(): void
    {
        $root = $this->createTempDir();
        $writer = new ChunkWriter($root);

        $writer->write_file_chunk('/empty.txt', '', true, true);

        $this->assertFileExists($root . '/empty.txt');
        $this->assertSame('', file_get_contents($root . '/empty.txt'));
    }
}
