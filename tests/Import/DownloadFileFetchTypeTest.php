<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Regression test for TypeError when download_file_fetch receives a string
 * instead of an array for $post_data.
 *
 * This can happen when the file list JSON content is passed inline as a
 * string (e.g. from file_get_contents($batch_file)) rather than wrapped
 * in a ['file_list' => new CURLFile(...)] array.
 *
 * The function should handle string input gracefully by converting it to
 * the expected CURLFile array format, rather than throwing a TypeError.
 */
class DownloadFileFetchTypeTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $docroot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-fetch-type-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->docroot = $this->tempDir . '/docroot';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->docroot, 0755, true);
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
        foreach (scandir($dir) as $item) {
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

    /**
     * Calling download_file_fetch with a JSON string (the file list content)
     * instead of an array should NOT throw a TypeError.
     *
     * It will throw a RuntimeException because there's no server to connect
     * to — that's expected. The important thing is that the function accepts
     * the string and converts it internally.
     */
    public function testStringPostDataDoesNotThrowTypeError(): void
    {
        // Use a port that nothing listens on to get a quick connection-refused error.
        $client = new \ImportClient(
            'http://127.0.0.1:1',
            $this->stateDir,
            $this->docroot,
        );

        $method = new \ReflectionMethod($client, 'download_file_fetch');
        $method->setAccessible(true);

        // This is the scenario that triggers the bug: a raw JSON string
        // is passed as $post_data instead of an array with CURLFile.
        $json_file_list = '["/wp-content/uploads/photo.jpg"]';

        try {
            $method->invoke($client, $json_file_list, null);
            // If we reach here, the function completed without error
            // (unlikely without a real server, but not a TypeError).
        } catch (\TypeError $e) {
            $this->fail(
                "download_file_fetch should accept string post_data without " .
                "TypeError, but got: " . $e->getMessage()
            );
        } catch (\RuntimeException $e) {
            // Expected: HTTP connection fails because there's no server.
            // The important thing is we got past the type check.
            $this->assertTrue(true, "Function accepted string and failed on HTTP (expected)");
        }
    }

    /**
     * Null post_data should still work (used for non-file-list fetches).
     */
    public function testNullPostDataStillWorks(): void
    {
        $client = new \ImportClient(
            'http://127.0.0.1:1',
            $this->stateDir,
            $this->docroot,
        );

        $method = new \ReflectionMethod($client, 'download_file_fetch');
        $method->setAccessible(true);

        try {
            $method->invoke($client, null, null);
        } catch (\TypeError $e) {
            $this->fail("Null post_data should not throw TypeError: " . $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * Array post_data (the normal CURLFile case) should still work.
     */
    public function testArrayPostDataStillWorks(): void
    {
        $client = new \ImportClient(
            'http://127.0.0.1:1',
            $this->stateDir,
            $this->docroot,
        );

        $method = new \ReflectionMethod($client, 'download_file_fetch');
        $method->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'test-file-list-');
        file_put_contents($tmp, '["/wp-content/uploads/photo.jpg"]');

        $post_data = [
            'file_list' => new \CURLFile($tmp, 'application/json', 'file-list.json'),
        ];

        try {
            $method->invoke($client, $post_data, null);
        } catch (\TypeError $e) {
            $this->fail("Array post_data should not throw TypeError: " . $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        } finally {
            @unlink($tmp);
        }
    }
}
