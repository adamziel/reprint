<?php

namespace IntegrationTests;

use PHPUnit\Framework\TestCase;

/**
 * Integration test for export.php and import.php working together.
 * Tests the full cycle: export → import → verify structure.
 */
class ExportImportTest extends TestCase
{
    private $fixturesDir;
    private $exportDir;
    private $importDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir() . '/export-import-test-' . uniqid();
        mkdir($this->fixturesDir, 0755, true);

        $this->exportDir = $this->fixturesDir . '/export-source';
        $this->importDir = $this->fixturesDir . '/import-target';
        mkdir($this->exportDir, 0755, true);
        mkdir($this->importDir, 0755, true);

        // Normalize paths to realpath for macOS /var vs /private/var consistency
        $this->fixturesDir = realpath($this->fixturesDir);
        $this->exportDir = realpath($this->exportDir);
        $this->importDir = realpath($this->importDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixturesDir)) {
            $this->recursiveDelete($this->fixturesDir);
        }
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

    public function testBasicFileExportImport()
    {
        // Create test files
        mkdir($this->exportDir . '/subdir', 0755, true);
        file_put_contents($this->exportDir . '/file1.txt', 'Content 1');
        file_put_contents($this->exportDir . '/subdir/file2.txt', 'Content 2');

        // Export
        $exportedData = $this->runExport($this->exportDir);

        // Import
        $context = $this->runImport($exportedData, $this->importDir);

        // Verify files exist
        $fsRoot = realpath($this->importDir) . '/filesystem-root' . $context->filesystem_root;
        $this->assertFileExists($fsRoot . '/file1.txt', 'file1.txt should exist');
        $this->assertFileExists($fsRoot . '/subdir/file2.txt', 'file2.txt should exist');

        // Verify content
        $this->assertEquals('Content 1', file_get_contents($fsRoot . '/file1.txt'));
        $this->assertEquals('Content 2', file_get_contents($fsRoot . '/subdir/file2.txt'));
    }

    public function testSymlinkExportImport()
    {
        // Create directory structure with symlinks
        mkdir($this->exportDir . '/htdocs', 0755, true);
        mkdir($this->exportDir . '/wordpress', 0755, true);

        file_put_contents($this->exportDir . '/htdocs/index.php', '<?php // Site');
        file_put_contents($this->exportDir . '/wordpress/wp-load.php', '<?php // WordPress');

        // Create symlink from htdocs to wordpress
        symlink('../wordpress', $this->exportDir . '/htdocs/__wp__');

        // Export from htdocs (which will follow symlink to ../wordpress)
        $exportedData = $this->runExport($this->exportDir . '/htdocs');

        // Import
        $context = $this->runImport($exportedData, $this->importDir);

        // Verify filesystem root is the common ancestor
        // Use realpath to normalize paths (macOS /var vs /private/var issue)
        $fsRoot = realpath($this->importDir) . '/filesystem-root' . $context->filesystem_root;

        // Verify files exist in their real locations
        $this->assertFileExists($fsRoot . '/htdocs/index.php', 'index.php should exist');
        $this->assertFileExists($fsRoot . '/wordpress/wp-load.php', 'wp-load.php should exist at real path');

        // Verify symlink exists
        $symlinkPath = $fsRoot . '/htdocs/__wp__';
        $this->assertTrue(is_link($symlinkPath), '__wp__ symlink should exist');

        // Verify symlink target
        $target = readlink($symlinkPath);
        $this->assertEquals('../wordpress', $target, 'Symlink should point to ../wordpress');

        // Verify symlink resolves correctly
        $this->assertTrue(is_dir($symlinkPath), 'Symlink should resolve to directory');
        $this->assertFileExists($symlinkPath . '/wp-load.php', 'wp-load.php should be accessible via symlink');
    }

    public function testMultipleSymlinksToSameTarget()
    {
        // Create structure
        mkdir($this->exportDir . '/site', 0755, true);
        file_put_contents($this->exportDir . '/target.txt', 'Shared content');

        // Create multiple symlinks
        symlink('../target.txt', $this->exportDir . '/site/link1.txt');
        symlink('../target.txt', $this->exportDir . '/site/link2.txt');

        // Export
        $exportedData = $this->runExport($this->exportDir . '/site');

        // Import
        $context = $this->runImport($exportedData, $this->importDir);

        $fsRoot = realpath($this->importDir) . '/filesystem-root' . $context->filesystem_root;

        // Verify target file exists only once
        $this->assertFileExists($fsRoot . '/target.txt');
        $this->assertEquals('Shared content', file_get_contents($fsRoot . '/target.txt'));

        // Verify both symlinks exist
        $this->assertTrue(is_link($fsRoot . '/site/link1.txt'));
        $this->assertTrue(is_link($fsRoot . '/site/link2.txt'));

        // Verify symlink targets
        $this->assertEquals('../target.txt', readlink($fsRoot . '/site/link1.txt'));
        $this->assertEquals('../target.txt', readlink($fsRoot . '/site/link2.txt'));

        // Verify content accessible through symlinks
        $this->assertEquals('Shared content', file_get_contents($fsRoot . '/site/link1.txt'));
        $this->assertEquals('Shared content', file_get_contents($fsRoot . '/site/link2.txt'));
    }

    public function testEmptyDirectories()
    {
        // Create empty directory structure
        mkdir($this->exportDir . '/empty1', 0755, true);
        mkdir($this->exportDir . '/parent/empty2', 0755, true);
        mkdir($this->exportDir . '/parent/with-file', 0755, true);
        file_put_contents($this->exportDir . '/parent/with-file/file.txt', 'Content');

        // Export
        $exportedData = $this->runExport($this->exportDir);

        // Import
        $context = $this->runImport($exportedData, $this->importDir);

        $fsRoot = realpath($this->importDir) . '/filesystem-root' . $context->filesystem_root;

        // Verify empty directories exist
        $this->assertDirectoryExists($fsRoot . '/empty1');
        $this->assertDirectoryExists($fsRoot . '/parent/empty2');
        $this->assertDirectoryExists($fsRoot . '/parent/with-file');
        $this->assertFileExists($fsRoot . '/parent/with-file/file.txt');
    }

    public function testChainedSymlinks()
    {
        // Create chain: link1 -> link2 -> target
        file_put_contents($this->exportDir . '/target.txt', 'Target content');
        symlink('target.txt', $this->exportDir . '/link2.txt');
        symlink('link2.txt', $this->exportDir . '/link1.txt');

        // Export
        $exportedData = $this->runExport($this->exportDir);

        // Import
        $context = $this->runImport($exportedData, $this->importDir);

        $fsRoot = realpath($this->importDir) . '/filesystem-root' . $context->filesystem_root;

        // Verify all pieces exist
        $this->assertFileExists($fsRoot . '/target.txt');
        $this->assertTrue(is_link($fsRoot . '/link1.txt'));
        $this->assertTrue(is_link($fsRoot . '/link2.txt'));

        // Verify chain works
        $this->assertEquals('Target content', file_get_contents($fsRoot . '/link1.txt'));
        $this->assertEquals('Target content', file_get_contents($fsRoot . '/link2.txt'));
    }

    public function testDirectorySymlink()
    {
        // Create directory structure
        mkdir($this->exportDir . '/site', 0755, true);
        mkdir($this->exportDir . '/lib', 0755, true);
        file_put_contents($this->exportDir . '/lib/util.php', 'Utility');
        file_put_contents($this->exportDir . '/lib/helper.php', 'Helper');

        // Create directory symlink
        symlink('../lib', $this->exportDir . '/site/lib');

        // Export from site
        $exportedData = $this->runExport($this->exportDir . '/site');

        // Import
        $context = $this->runImport($exportedData, $this->importDir);

        $fsRoot = realpath($this->importDir) . '/filesystem-root' . $context->filesystem_root;

        // Verify files exist at real location
        $this->assertFileExists($fsRoot . '/lib/util.php');
        $this->assertFileExists($fsRoot . '/lib/helper.php');

        // Verify directory symlink exists
        $this->assertTrue(is_link($fsRoot . '/site/lib'));
        $this->assertEquals('../lib', readlink($fsRoot . '/site/lib'));

        // Verify files accessible through symlink
        $this->assertFileExists($fsRoot . '/site/lib/util.php');
        $this->assertFileExists($fsRoot . '/site/lib/helper.php');
    }

    public function testLargeFileChunking()
    {
        // Create a large file (> default chunk size)
        $largeContent = str_repeat('ABCDEFGHIJ', 100000); // 1MB
        file_put_contents($this->exportDir . '/large.dat', $largeContent);

        // Export
        $exportedData = $this->runExport($this->exportDir);

        // Import
        $context = $this->runImport($exportedData, $this->importDir);

        $fsRoot = realpath($this->importDir) . '/filesystem-root' . $context->filesystem_root;

        // Verify file exists and content matches
        $this->assertFileExists($fsRoot . '/large.dat');
        $this->assertEquals($largeContent, file_get_contents($fsRoot . '/large.dat'));
    }

    public function testFilesystemRootCalculation()
    {
        // Create structure where scan dir is /parent/child
        // and symlink points to /parent/sibling
        mkdir($this->exportDir . '/parent/child', 0755, true);
        mkdir($this->exportDir . '/parent/sibling', 0755, true);

        file_put_contents($this->exportDir . '/parent/child/main.php', 'Main');
        file_put_contents($this->exportDir . '/parent/sibling/lib.php', 'Library');

        symlink('../sibling', $this->exportDir . '/parent/child/lib');

        // Export from child
        $exportedData = $this->runExport($this->exportDir . '/parent/child');

        // Import
        $context = $this->runImport($exportedData, $this->importDir);

        // Filesystem root should be /parent
        $fsRoot = realpath($this->importDir) . '/filesystem-root' . $context->filesystem_root;

        // Debug
        fwrite(STDERR, "\n=== testFilesystemRootCalculation ===\n");
        fwrite(STDERR, "Filesystem root from export: {$context->filesystem_root}\n");
        fwrite(STDERR, "fsRoot: {$fsRoot}\n");
        fwrite(STDERR, "Files created:\n");
        $files = $this->recursiveList(realpath($this->importDir) . '/filesystem-root');
        foreach ($files as $f) {
            fwrite(STDERR, "  $f\n");
        }

        // Verify structure preserved
        $this->assertFileExists($fsRoot . '/child/main.php');
        $this->assertFileExists($fsRoot . '/sibling/lib.php');
        $this->assertTrue(is_link($fsRoot . '/child/lib'));
    }

    /**
     * Run export.php programmatically and return the multipart data.
     */
    private function runExport(string $directory): array
    {
        // Capture export output
        ob_start();

        // Capture headers
        $capturedHeaders = [];
        $originalHeaderFunction = null;

        // Override header() function won't work in CLI, so we'll extract boundary from output

        $_GET = [
            'SECRET_KEY' => 'GbLGY0uYaP8UALGHkk3wJq7rnyluDo1CNc7zXGWD',
            'operation' => 'files',
            'directory' => $directory,
        ];

        // Include export.php but prevent it from executing
        // We'll call endpoint_file_chunk() directly
        require_once __DIR__ . '/../../wordpress-plugin/generic/export.php';

        $config = [
            'directory' => $directory,
        ];

        $script_start = microtime(true);
        $max_execution_time = 30;
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit === '-1') {
            $max_memory = PHP_INT_MAX;
        } else {
            $max_memory = parse_memory_limit($memory_limit);
        }
        $memory_threshold = 0.8;

        endpoint_file_chunk($config, $script_start, $max_execution_time, $max_memory, $memory_threshold);

        $output = ob_get_clean();

        // Extract boundary from the first boundary marker in the output
        // Format: --boundary-hexstring
        $boundary = null;
        foreach (explode("\n", $output) as $line) {
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
            $this->fail('Could not extract boundary from output. First 500 chars: ' . substr($output, 0, 500));
        }

        return [
            'boundary' => $boundary,
            'data' => $output,
        ];
    }

    /**
     * Run import programmatically with multipart data.
     * Returns the context which contains filesystem_root.
     */
    private function runImport(array $exportResult, string $targetDir)
    {
        $boundary = $exportResult['boundary'];
        $multipartData = $exportResult['data'];

        // Create import client
        require_once __DIR__ . '/../../importer/import.php';

        $client = new \ImportClient('http://dummy-url', $targetDir);

        // Parse and process multipart data
        $context = new \StreamingContext();

        $parser = new \MultipartStreamParser($boundary, function($chunk) use (&$context, $targetDir) {
            $type = $chunk['type'] ?? '';
            $headers = $chunk['headers'] ?? [];
            $chunkType = $headers['x-chunk-type'] ?? '';

            if ($type === 'body') {
                // Stream body data incrementally
                $data = $chunk['data'] ?? '';

                if ($chunkType === 'file') {
                    // Stream file data to disk
                    $this->handleFileBodyChunk($headers, $data, $context, $targetDir);
                } elseif ($chunkType === 'metadata') {
                    // Metadata has JSON body
                    if (!isset($context->metadata_buffer)) {
                        $context->metadata_buffer = '';
                    }
                    $context->metadata_buffer .= $data;
                }
            } elseif ($type === 'complete') {
                // Process complete chunk
                if ($chunkType === 'metadata') {
                    $this->handleMetadataChunk($headers, $context, $targetDir);
                    unset($context->metadata_buffer);
                } elseif ($chunkType === 'file') {
                    $this->handleFileComplete($headers, $context);
                } elseif ($chunkType === 'directory') {
                    $this->handleDirectoryChunk($headers, $targetDir);
                } elseif ($chunkType === 'symlink') {
                    $this->handleSymlinkChunk($headers, $targetDir);
                }
            }
        });

        $parser->feed($multipartData);

        // Close any open file handles
        if (isset($context->file_handle) && $context->file_handle) {
            fclose($context->file_handle);
            $context->file_handle = null;
        }

        return $context;
    }

    private function handleMetadataChunk(array $headers, $context, string $targetDir): void
    {
        $filesystem_root = base64_decode($headers['x-filesystem-root'] ?? '');
        $context->filesystem_root = $filesystem_root;

        // Create filesystem-root directory
        if (!is_dir($targetDir . '/filesystem-root')) {
            mkdir($targetDir . '/filesystem-root', 0755, true);
        }
    }

    private function handleFileBodyChunk(array $headers, string $data, $context, string $targetDir): void
    {
        $path = base64_decode($headers['x-file-path'] ?? '');
        $isFirst = ($headers['x-first-chunk'] ?? '0') === '1';

        if (!$path) {
            return;
        }

        $localPath = $targetDir . '/filesystem-root' . $path;

        // Open file on first chunk
        if ($isFirst) {
            // Close previous file if any
            if (isset($context->file_handle) && $context->file_handle) {
                fclose($context->file_handle);
            }

            // Create parent directory
            $dir = dirname($localPath);
            if (!is_dir($dir)) {
                // Check what exists at this path
                $existsType = 'nothing';
                if (file_exists($dir)) $existsType = 'file';
                if (is_link($dir)) $existsType = 'symlink';
                if (is_dir($dir)) $existsType = 'directory';

                if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new \RuntimeException("Failed to create directory: $dir (exists as: $existsType, error: " . (error_get_last()['message'] ?? 'unknown') . ")");
                }
            }

            // Open file
            $context->file_handle = fopen($localPath, 'wb');
            if (!$context->file_handle) {
                throw new \RuntimeException("Failed to open file: $localPath (dir exists: " . (is_dir($dir) ? 'yes' : 'no') . ")");
            }
            $context->file_path = $localPath;
        }

        // Write data incrementally (streaming)
        if (isset($context->file_handle) && $context->file_handle && $data !== '') {
            fwrite($context->file_handle, $data);
        }
    }

    private function handleFileComplete(array $headers, $context): void
    {
        // Close file
        if (isset($context->file_handle) && $context->file_handle) {
            fclose($context->file_handle);
            $context->file_handle = null;
        }

        // Set ctime if provided
        $ctime = (int)($headers['x-file-ctime'] ?? 0);
        if ($ctime > 0 && isset($context->file_path)) {
            touch($context->file_path, $ctime);
        }
    }

    private function handleDirectoryChunk(array $headers, string $targetDir): void
    {
        $path = base64_decode($headers['x-directory-path'] ?? '');

        if (!$path) {
            return;
        }

        $localPath = $targetDir . '/filesystem-root' . $path;

        if (!is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }
    }

    private function handleSymlinkChunk(array $headers, string $targetDir): void
    {
        $path = base64_decode($headers['x-symlink-path'] ?? '');
        $target = base64_decode($headers['x-symlink-target'] ?? '');

        if (!$path || $target === false || $target === '') {
            return;
        }

        $localPath = $targetDir . '/filesystem-root' . $path;

        // Remove existing file/symlink if present
        if (file_exists($localPath) || is_link($localPath)) {
            unlink($localPath);
        }

        // Create parent directory
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Create symlink
        symlink($target, $localPath);

        // Set ctime if provided
        $ctime = (int)($headers['x-symlink-ctime'] ?? 0);
        if ($ctime > 0) {
            @touch($localPath, $ctime);
        }
    }

    private function recursiveList(string $dir, string $prefix = ''): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            $displayPath = $prefix . $item;

            if (is_link($path)) {
                $target = readlink($path);
                $files[] = $displayPath . ' -> ' . $target;
            } elseif (is_dir($path)) {
                $files[] = $displayPath . '/';
                $files = array_merge($files, $this->recursiveList($path, $displayPath . '/'));
            } else {
                $files[] = $displayPath;
            }
        }

        return $files;
    }
}
