<?php

namespace FileSyncProducerTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../class-file-sync-producer.php';

/**
 * Base test class for FileSyncProducer tests
 */
abstract class FileSyncProducerTestBase extends TestCase
{
    protected $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = __DIR__ . '/fixtures';

        // Create fixtures directory if it doesn't exist
        if (!is_dir($this->fixturesDir)) {
            mkdir($this->fixturesDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up all fixtures after each test
        $this->cleanupFixtures();
        parent::tearDown();
    }

    /**
     * Create a test directory with files
     */
    protected function createTestDirectory(string $name, array $files = []): string
    {
        $dir = $this->fixturesDir . '/' . $name;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach ($files as $path => $content) {
            $fullPath = $dir . '/' . $path;
            $pathDir = dirname($fullPath);

            if (!is_dir($pathDir)) {
                mkdir($pathDir, 0755, true);
            }

            file_put_contents($fullPath, $content);
        }

        return $dir;
    }

    /**
     * Create a file in test directory
     */
    protected function createFile(string $dir, string $path, string $content): string
    {
        $fullPath = $dir . '/' . $path;
        $pathDir = dirname($fullPath);

        if (!is_dir($pathDir)) {
            mkdir($pathDir, 0755, true);
        }

        file_put_contents($fullPath, $content);
        return $fullPath;
    }

    /**
     * Delete a file from test directory
     */
    protected function deleteFile(string $dir, string $path): void
    {
        $fullPath = $dir . '/' . $path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Update a file in test directory
     */
    protected function updateFile(string $dir, string $path, string $content): void
    {
        $fullPath = $dir . '/' . $path;
        file_put_contents($fullPath, $content);
        // Touch to update mtime/ctime
        touch($fullPath);
    }

    /**
     * Clean up all fixtures
     */
    protected function cleanupFixtures(): void
    {
        if (!is_dir($this->fixturesDir)) {
            return;
        }

        $this->recursiveDelete($this->fixturesDir);
    }

    /**
     * Recursively delete directory
     */
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

            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Process all chunks from sync producer
     */
    protected function processAllChunks(\FileSyncProducer $sync): array
    {
        $chunks = [];

        while ($sync->next_chunk()) {
            $chunk = $sync->get_current_chunk();
            if ($chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * Get list of files from chunks
     */
    protected function getFilesFromChunks(array $chunks): array
    {
        $files = [];

        foreach ($chunks as $chunk) {
            if ($chunk['is_first_chunk']) {
                $files[] = $chunk['path'];
            }
        }

        return $files;
    }

    /**
     * Reconstruct file content from chunks
     */
    protected function reconstructFileFromChunks(array $chunks, string $path): string
    {
        $content = '';

        foreach ($chunks as $chunk) {
            if ($chunk['path'] === $path) {
                $content .= $chunk['data'];
            }
        }

        return $content;
    }
}
