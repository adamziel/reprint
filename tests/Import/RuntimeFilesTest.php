<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Tests for PHP runtime file downloading during preflight.
 *
 * The export preflight includes a runtime_files array with
 * base64-encoded contents of php.ini, scanned ini files,
 * and auto_prepend/append scripts.  The import side writes
 * these into state_dir/runtime_files/ preserving their
 * original absolute paths.
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
     * Runtime files from preflight are written to state_dir/runtime_files/
     * preserving the original absolute path structure.
     */
    public function testRuntimeFilesWrittenFromPreflight()
    {
        $phpIniContent = "; PHP configuration\nmax_execution_time = 30\n";
        $prependContent = "<?php // auto prepend\n";
        $scannedContent = "extension=curl\n";

        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime_files" => [
                        [
                            "path" => "/etc/php/8.1/php.ini",
                            "content" => base64_encode($phpIniContent),
                            "size" => strlen($phpIniContent),
                            "error" => null,
                        ],
                        [
                            "path" => "/scripts/env.php",
                            "content" => base64_encode($prependContent),
                            "size" => strlen($prependContent),
                            "error" => null,
                        ],
                        [
                            "path" => "/etc/php/8.1/conf.d/curl.ini",
                            "content" => base64_encode($scannedContent),
                            "size" => strlen($scannedContent),
                            "error" => null,
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'download_runtime_files');

        $runtimeDir = $this->stateDir . '/runtime_files';
        $this->assertDirectoryExists($runtimeDir);

        $phpIniPath = $runtimeDir . '/etc/php/8.1/php.ini';
        $this->assertFileExists($phpIniPath);
        $this->assertEquals($phpIniContent, file_get_contents($phpIniPath));

        $prependPath = $runtimeDir . '/scripts/env.php';
        $this->assertFileExists($prependPath);
        $this->assertEquals($prependContent, file_get_contents($prependPath));

        $curlIniPath = $runtimeDir . '/etc/php/8.1/conf.d/curl.ini';
        $this->assertFileExists($curlIniPath);
        $this->assertEquals($scannedContent, file_get_contents($curlIniPath));
    }

    /**
     * Entries with errors in the preflight data are skipped gracefully.
     */
    public function testRuntimeFilesSkipsErrors()
    {
        $goodContent = "extension=gd\n";

        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime_files" => [
                        [
                            "path" => "/etc/php/unreadable.ini",
                            "content" => null,
                            "size" => null,
                            "error" => "not readable",
                        ],
                        [
                            "path" => "/etc/php/8.1/conf.d/gd.ini",
                            "content" => base64_encode($goodContent),
                            "size" => strlen($goodContent),
                            "error" => null,
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'download_runtime_files');

        $runtimeDir = $this->stateDir . '/runtime_files';

        // Errored file should not exist
        $this->assertFileDoesNotExist($runtimeDir . '/etc/php/unreadable.ini');

        // Good file should exist
        $gdPath = $runtimeDir . '/etc/php/8.1/conf.d/gd.ini';
        $this->assertFileExists($gdPath);
        $this->assertEquals($goodContent, file_get_contents($gdPath));
    }

    /**
     * Re-running preflight wipes and recreates runtime_files/.
     */
    public function testRuntimeFilesWipedOnRerun()
    {
        $content = "max_execution_time = 60\n";

        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime_files" => [
                        [
                            "path" => "/etc/php.ini",
                            "content" => base64_encode("old content"),
                            "size" => 11,
                            "error" => null,
                        ],
                        [
                            "path" => "/etc/old-file.ini",
                            "content" => base64_encode("stale"),
                            "size" => 5,
                            "error" => null,
                        ],
                    ],
                ],
            ],
        ]);

        // First run
        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'download_runtime_files');

        $runtimeDir = $this->stateDir . '/runtime_files';
        $this->assertFileExists($runtimeDir . '/etc/php.ini');
        $this->assertFileExists($runtimeDir . '/etc/old-file.ini');

        // Simulate a second preflight with different files
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime_files" => [
                        [
                            "path" => "/etc/php.ini",
                            "content" => base64_encode($content),
                            "size" => strlen($content),
                            "error" => null,
                        ],
                    ],
                ],
            ],
        ]);

        $client2 = $this->makeClient();
        $this->loadClientState($client2);
        $this->callPrivate($client2, 'download_runtime_files');

        // New content should be there
        $this->assertEquals($content, file_get_contents($runtimeDir . '/etc/php.ini'));

        // Old stale file should be gone
        $this->assertFileDoesNotExist($runtimeDir . '/etc/old-file.ini');
    }

    /**
     * When preflight has no runtime_files, the directory should not be created.
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
}
