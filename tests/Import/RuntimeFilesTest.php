<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Tests for PHP runtime file path collection and directory management.
 *
 * The import side reads runtime file paths (php.ini, scanned ini files,
 * auto_prepend/append scripts) from the preflight response and downloads
 * them via the file_fetch endpoint.  Since unit tests cannot hit a real
 * server, these tests cover:
 *
 * - collect_runtime_file_paths(): correct parsing of preflight runtime data
 * - Directory wipe/create lifecycle of runtime_files/
 * - Graceful handling when no runtime paths are present
 * - Tolerance of fetch failures (the file_fetch call will fail against a
 *   fake URL, but download_runtime_files() must not throw)
 */
class RuntimeFilesTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $docroot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/runtime-files-test-' . uniqid();
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

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('http://fake.url', $this->stateDir, $this->docroot);
    }

    private function writeState(array $state): void
    {
        $defaults = [
            "command" => null,
            "status" => null,
            "cursor" => null,
            "stage" => null,
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "docroot_nonempty_behavior" => "error",
            "max_allowed_packet" => null,
        ];
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $m = $reflection->getMethod($method);
        return $m->invoke($client, ...$args);
    }

    private function setPrivate(\ImportClient $client, string $property, $value): void
    {
        $reflection = new \ReflectionClass($client);
        $p = $reflection->getProperty($property);
        $p->setValue($client, $value);
    }

    /**
     * Load state from disk and assign to client's state property.
     */
    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $this->setPrivate($client, 'state', $state);
    }

    /**
     * collect_runtime_file_paths() extracts php_ini, auto_prepend_file,
     * auto_append_file, and scanned ini files from the preflight runtime
     * section.
     */
    public function testCollectRuntimeFilePathsAllFields()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "php_ini" => "/etc/php/8.1/php.ini",
                        "auto_prepend_file" => "/scripts/prepend.php",
                        "auto_append_file" => "/scripts/append.php",
                        "php_ini_scanned_files" => "/etc/php/8.1/conf.d/curl.ini, /etc/php/8.1/conf.d/gd.ini",
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);

        $result = $this->callPrivate($client, 'collect_runtime_file_paths');

        $this->assertEqualsCanonicalizing(
            [
                "/etc/php/8.1/php.ini",
                "/scripts/prepend.php",
                "/scripts/append.php",
                "/etc/php/8.1/conf.d/curl.ini",
                "/etc/php/8.1/conf.d/gd.ini",
            ],
            $result["files"],
        );

        // Parent directories should be computed for each unique path.
        $this->assertContains("/etc/php/8.1", $result["directories"]);
        $this->assertContains("/scripts", $result["directories"]);
        $this->assertContains("/etc/php/8.1/conf.d", $result["directories"]);
    }

    /**
     * collect_runtime_file_paths() deduplicates paths — if php_ini and a
     * scanned ini share the same path, it appears only once.
     */
    public function testCollectRuntimeFilePathsDeduplicates()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "php_ini" => "/etc/php.ini",
                        "auto_prepend_file" => null,
                        "auto_append_file" => "",
                        "php_ini_scanned_files" => "/etc/php.ini, /etc/conf.d/extra.ini",
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);

        $result = $this->callPrivate($client, 'collect_runtime_file_paths');

        // /etc/php.ini should appear only once despite being in both php_ini and scanned list.
        $this->assertEquals(
            ["/etc/php.ini", "/etc/conf.d/extra.ini"],
            $result["files"],
        );
    }

    /**
     * collect_runtime_file_paths() returns empty arrays when no runtime
     * section is present in preflight data.
     */
    public function testCollectRuntimeFilePathsEmpty()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);

        $result = $this->callPrivate($client, 'collect_runtime_file_paths');

        $this->assertEmpty($result["files"]);
        $this->assertEmpty($result["directories"]);
    }

    /**
     * When preflight has no runtime paths, download_runtime_files() should
     * not create the runtime_files/ directory.
     */
    public function testNoRuntimeFilesNoDirectory()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'download_runtime_files');

        $this->assertDirectoryDoesNotExist($this->stateDir . '/runtime_files');
    }

    /**
     * download_runtime_files() wipes the existing runtime_files/ directory
     * even when the fetch fails (which it will against a fake URL).
     */
    public function testRuntimeFilesDirWipedOnRerun()
    {
        $runtimeDir = $this->stateDir . '/runtime_files';
        mkdir($runtimeDir . '/etc/old', 0755, true);
        file_put_contents($runtimeDir . '/etc/old/stale.ini', 'stale content');

        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "php_ini" => "/etc/php.ini",
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'download_runtime_files');

        // The old stale file should be gone because the directory was wiped.
        $this->assertFileDoesNotExist($runtimeDir . '/etc/old/stale.ini');

        // The runtime_files/ directory itself should exist (it was recreated
        // because there are files to download, even though the fetch failed).
        $this->assertDirectoryExists($runtimeDir);
    }

    /**
     * download_runtime_files() tolerates fetch failures without throwing.
     * The file_fetch call against a fake URL will fail, but the method
     * must catch the exception and continue.
     */
    public function testDownloadToleratesFetchFailure()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "php_ini" => "/etc/php/8.1/php.ini",
                        "auto_prepend_file" => "/scripts/prepend.php",
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);

        // This must not throw — failures are caught internally.
        $this->callPrivate($client, 'download_runtime_files');

        // The directory should exist even though no files were downloaded.
        $this->assertDirectoryExists($this->stateDir . '/runtime_files');
    }
}
