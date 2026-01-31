<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../import.php';

/**
 * Test import symlink recreation
 */
class ImportSymlinkTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-symlink-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/filesystem-root', 0755, true);
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

            // Remove symlinks first
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

    public function testSymlinkIsCreated()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir);

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/test/link'),
                'x-symlink-target' => base64_encode('target'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        $symlinkPath = $this->tempDir . '/filesystem-root/test/link';
        $this->assertTrue(is_link($symlinkPath), 'Symlink should be created');
        $this->assertEquals('target', readlink($symlinkPath), 'Symlink target should match');
    }

    public function testRelativeSymlinkPreserved()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir);

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/var/www/site/__wp__'),
                'x-symlink-target' => base64_encode('../../../wordpress/core/latest'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        $symlinkPath = $this->tempDir . '/filesystem-root/var/www/site/__wp__';
        $this->assertTrue(is_link($symlinkPath), 'Symlink should be created');
        $this->assertEquals('../../../wordpress/core/latest', readlink($symlinkPath));
    }

    public function testChainedSymlinks()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir);

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        // Create first symlink
        $chunk1 = [
            'headers' => [
                'x-symlink-path' => base64_encode('/site/__wp__'),
                'x-symlink-target' => base64_encode('../wordpress/core'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];
        $method->invoke($client, $chunk1);

        // Create second symlink that uses the first
        $chunk2 = [
            'headers' => [
                'x-symlink-path' => base64_encode('/site/wp-load.php'),
                'x-symlink-target' => base64_encode('__wp__/wp-load.php'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];
        $method->invoke($client, $chunk2);

        $link1 = $this->tempDir . '/filesystem-root/site/__wp__';
        $link2 = $this->tempDir . '/filesystem-root/site/wp-load.php';

        $this->assertTrue(is_link($link1));
        $this->assertTrue(is_link($link2));
        $this->assertEquals('../wordpress/core', readlink($link1));
        $this->assertEquals('__wp__/wp-load.php', readlink($link2));
    }

    public function testAbsoluteSymlinkPreserved()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir);

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/bin-link'),
                'x-symlink-target' => base64_encode('/usr/local/bin/php'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        $symlinkPath = $this->tempDir . '/filesystem-root/bin-link';
        $this->assertTrue(is_link($symlinkPath));
        $this->assertEquals('/usr/local/bin/php', readlink($symlinkPath));
    }

    public function testSymlinkWithMissingDataSkipped()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir);

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        // Missing path
        $chunk1 = [
            'headers' => [
                'x-symlink-target' => base64_encode('target'),
            ]
        ];
        $method->invoke($client, $chunk1);

        // Missing target
        $chunk2 = [
            'headers' => [
                'x-symlink-path' => base64_encode('/path'),
            ]
        ];
        $method->invoke($client, $chunk2);

        // No symlinks should be created
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (is_link($file->getPathname())) {
                $count++;
            }
        }

        $this->assertEquals(0, $count, 'No symlinks should be created for invalid chunks');
    }

    public function testSymlinkReplacesExistingFile()
    {
        $client = new \ImportClient('http://fake.url', $this->tempDir);

        // Create a regular file
        $filePath = $this->tempDir . '/filesystem-root/test/link';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, 'content');
        $this->assertTrue(is_file($filePath));

        // Create symlink at same location
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_symlink_chunk');

        $chunk = [
            'headers' => [
                'x-symlink-path' => base64_encode('/test/link'),
                'x-symlink-target' => base64_encode('target'),
                'x-symlink-ctime' => '1234567890'
            ]
        ];

        $method->invoke($client, $chunk);

        // Should now be a symlink
        $this->assertTrue(is_link($filePath), 'File should be replaced with symlink');
        $this->assertEquals('target', readlink($filePath));
    }
}
