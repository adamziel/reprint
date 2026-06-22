<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\RuntimeFilesDownloader;
use Reprint\Importer\ImportClient;
use Reprint\Importer\Protocol\StreamingContext;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Tests for auto_prepend/append script downloading during preflight.
 *
 * The import side reads auto_prepend_file and auto_append_file paths
 * from ini_get_all in the preflight response and downloads them via
 * the file_fetch endpoint into state_dir/runtime_files/.
 *
 * Since unit tests cannot hit a real server, these tests cover:
 * - Directory wipe/create lifecycle of runtime_files/
 * - Graceful handling when no scripts are configured
 * - Tolerance of fetch failures
 */
class RuntimeFilesTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/runtime-files-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fs_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->stateDir . '/.reprint', 0755, true);
        mkdir($this->fs_root, 0755, true);
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

    private function makeClient(): ImportClient
    {
        return new ImportClient('http://fake.url', $this->stateDir, $this->fs_root);
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
            "fs_root_nonempty_behavior" => "error",
            "max_allowed_packet" => null,
        ];
        file_put_contents(
            $this->stateDir . '/.reprint/run.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function callPrivate(ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $m = $reflection->getMethod($method);
        return $m->invoke($client, ...$args);
    }

    private function setPrivate(ImportClient $client, string $property, $value): void
    {
        $reflection = new \ReflectionClass($client);
        $p = $reflection->getProperty($property);
        $p->setValue($client, $value);
    }

    private function loadClientState(ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $this->setPrivate($client, 'state', $state);
    }

    /**
     * When no auto_prepend/append scripts are configured, the
     * runtime_files/ directory is not created.
     */
    public function testNoScriptsNoDirectory()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "ini_get_all" => [
                            "auto_prepend_file" => "",
                            "auto_append_file" => "",
                        ],
                    ],
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
     * on re-run.
     */
    public function testRuntimeFilesDirWipedOnRerun()
    {
        $runtimeDir = $this->stateDir . '/runtime_files';
        mkdir($runtimeDir . '/old', 0755, true);
        file_put_contents($runtimeDir . '/old/stale.php', '<?php // old');

        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "ini_get_all" => [
                            "auto_prepend_file" => "/scripts/prepend.php",
                            "auto_append_file" => "",
                        ],
                    ],
                ],
            ],
        ]);

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->callPrivate($client, 'download_runtime_files');

        // The old stale file should be gone because the directory was wiped.
        $this->assertFileDoesNotExist($runtimeDir . '/old/stale.php');
    }

    /**
     * download_runtime_files() tolerates fetch failures without throwing.
     */
    public function testDownloadToleratesFetchFailure()
    {
        $this->writeState([
            "preflight" => [
                "http_code" => 200,
                "data" => [
                    "ok" => true,
                    "runtime" => [
                        "ini_get_all" => [
                            "auto_prepend_file" => "/scripts/prepend.php",
                            "auto_append_file" => "/scripts/append.php",
                        ],
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

    public function testDownloaderSavesFetchedRuntimeFile(): void
    {
        $runtimeDir = $this->stateDir . '/runtime_files';
        $audit = [];
        $urls = [];

        $downloader = new RuntimeFilesDownloader(
            function (string $endpoint, ?string $cursor, array $params) use (&$urls): string {
                $this->assertSame('file_fetch', $endpoint);
                $this->assertNull($cursor);
                $this->assertSame(['/scripts'], $params['directory']);

                $url = 'http://fake.url/export.php?endpoint=' . $endpoint;
                $urls[] = $url;

                return $url;
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ): void {
                $this->assertSame('http://fake.url/export.php?endpoint=file_fetch', $url);
                $this->assertNull($cursor);
                $this->assertSame('file_fetch', $phase);
                $this->assertIsArray($post_data);
                $this->assertArrayHasKey('file_list', $post_data);

                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'file',
                        'x-file-path' => base64_encode('/scripts/env.php'),
                        'x-first-chunk' => '1',
                        'x-last-chunk' => '1',
                    ],
                    'body' => "<?php\nreturn ['loaded' => true];\n",
                ]);
                ($context->on_chunk)([
                    'headers' => [
                        'x-chunk-type' => 'completion',
                    ],
                ]);
            },
            function (string $message) use (&$audit): void {
                $audit[] = $message;
            },
        );

        $downloaded = $downloader->download(
            [
                'runtime' => [
                    'ini_get_all' => [
                        'auto_prepend_file' => '/scripts/env.php',
                        'auto_append_file' => '/scripts/env.php',
                    ],
                ],
            ],
            $runtimeDir,
        );

        $this->assertSame(1, $downloaded);
        $this->assertCount(1, $urls);
        $this->assertSame(
            "<?php\nreturn ['loaded' => true];\n",
            file_get_contents($runtimeDir . '/scripts/env.php'),
        );
        $this->assertContains('RUNTIME FILES | downloaded 1/1 script(s)', $audit);
        $this->assertStringContainsString(
            'Saved /scripts/env.php',
            implode("\n", $audit),
        );
    }
}
