<?php

namespace IntegrationTests;

use PHPUnit\Framework\TestCase;

/**
 * Test that verifies symlinks are correctly exported and imported
 */
class SymlinkVerificationTest extends TestCase
{
    private $sourceDir;
    private $importDir;

    protected function setUp(): void
    {
        $this->sourceDir = sys_get_temp_dir() . '/symlink-test-source-' . uniqid();
        $this->importDir = sys_get_temp_dir() . '/symlink-test-import-' . uniqid();

        mkdir($this->sourceDir, 0755, true);
        mkdir($this->importDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->sourceDir);
        $this->recursiveDelete($this->importDir);
    }

    public function testSymlinksAreCreatedOnImport()
    {
        // Create source structure with symlinks
        mkdir($this->sourceDir . '/srv/htdocs', 0755, true);
        mkdir($this->sourceDir . '/srv/wordpress/core/latest', 0755, true);

        file_put_contents($this->sourceDir . '/srv/htdocs/index.php', '<?php echo "Site";');
        file_put_contents($this->sourceDir . '/srv/wordpress/core/latest/wp-load.php', '<?php echo "WordPress";');

        // Create symlinks
        symlink('../wordpress/core/latest', $this->sourceDir . '/srv/htdocs/__wp__');
        symlink('__wp__/wp-load.php', $this->sourceDir . '/srv/htdocs/wp-load.php');

        // Export
        $_GET['SECRET_KEY'] = 'your-secret-pw';
        require_once __DIR__ . '/../../export.php';

        ob_start();
        $config = ['directory' => $this->sourceDir . '/srv/htdocs'];
        endpoint_file_chunk($config, microtime(true), 30, PHP_INT_MAX, 0.8);
        $exportData = ob_get_clean();

        $this->assertNotEmpty($exportData, 'Export should produce data');

        // Parse boundary
        $boundary = null;
        foreach (explode("\n", $exportData) as $line) {
            $line = trim($line);
            if (strpos($line, "--boundary-") === 0) {
                $suffix = substr($line, strlen("--boundary-"));
                if ($suffix !== "") {
                    $boundary = "boundary-" . $suffix;
                    break;
                }
            }
        }
        if ($boundary === null) {
            $this->fail("Could not find boundary in export data");
        }

        // Import
        require_once __DIR__ . '/../../import.php';
        mkdir($this->importDir . '/filesystem-root', 0755, true);

        $filesystemRoot = null;
        $filesCreated = 0;
        $symlinksCreated = 0;
        $fileHandles = [];

        $parser = new \MultipartStreamParser($boundary, function($chunk) use (&$filesystemRoot, &$filesCreated, &$symlinksCreated, &$fileHandles) {
            $type = $chunk['type'] ?? '';
            $headers = $chunk['headers'] ?? [];
            $chunkType = $headers['x-chunk-type'] ?? '';

            if ($type === 'body' && $chunkType === 'file') {
                $path = base64_decode($headers['x-file-path'] ?? '');
                $isFirst = ($headers['x-first-chunk'] ?? '0') === '1';
                $data = $chunk['data'] ?? '';

                if ($path && $isFirst) {
                    $localPath = $this->importDir . '/filesystem-root' . $path;
                    $dir = dirname($localPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $fileHandles[$path] = fopen($localPath, 'wb');
                }

                if ($path && isset($fileHandles[$path]) && $data !== '') {
                    fwrite($fileHandles[$path], $data);
                }
            } elseif ($type === 'complete') {
                if ($chunkType === 'metadata') {
                    $filesystemRoot = base64_decode($headers['x-filesystem-root'] ?? '');
                } elseif ($chunkType === 'file') {
                    $path = base64_decode($headers['x-file-path'] ?? '');
                    if ($path && isset($fileHandles[$path])) {
                        fclose($fileHandles[$path]);
                        unset($fileHandles[$path]);
                        $filesCreated++;
                    }
                } elseif ($chunkType === 'symlink') {
                    $path = base64_decode($headers['x-symlink-path'] ?? '');
                    $target = base64_decode($headers['x-symlink-target'] ?? '');

                    if ($path && $target) {
                        $localPath = $this->importDir . '/filesystem-root' . $path;
                        $dir = dirname($localPath);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }

                        if (file_exists($localPath) || is_link($localPath)) {
                            unlink($localPath);
                        }

                        $result = symlink($target, $localPath);
                        $this->assertTrue($result, "Failed to create symlink: $path -> $target");
                        $symlinksCreated++;
                    }
                }
            }
        });

        $parser->feed($exportData);

        // Close any remaining file handles
        foreach ($fileHandles as $fh) {
            fclose($fh);
        }

        // Assertions
        $this->assertNotNull($filesystemRoot, 'Filesystem root should be set');
        $this->assertEquals(1, $filesCreated, 'Should create 1 file (only index.php from scan dir)');
        $this->assertEquals(2, $symlinksCreated, 'Should create 2 symlinks');

        // Verify symlinks exist in filesystem
        $fsRoot = $this->importDir . '/filesystem-root' . $filesystemRoot;

        $this->assertTrue(is_link($fsRoot . '/htdocs/__wp__'), '__wp__ should be a symlink');
        $this->assertTrue(is_link($fsRoot . '/htdocs/wp-load.php'), 'wp-load.php should be a symlink');

        // Verify symlink targets are preserved
        $this->assertEquals('../wordpress/core/latest', readlink($fsRoot . '/htdocs/__wp__'));
        $this->assertEquals('__wp__/wp-load.php', readlink($fsRoot . '/htdocs/wp-load.php'));

        // Verify file from scan directory exists
        $this->assertFileExists($fsRoot . '/htdocs/index.php');

        // Files outside scan directory are NOT exported, so symlinks may be broken
        // This is expected behavior when scanning only /srv/htdocs
        $this->assertFileDoesNotExist($fsRoot . '/wordpress/core/latest/wp-load.php');
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            if (file_exists($dir)) unlink($dir);
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
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
}
