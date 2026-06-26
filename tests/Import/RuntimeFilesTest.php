<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\RuntimeFilesDownloader;
use Reprint\Importer\Application\Importer;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Session\PreflightCheckpoint;
use Reprint\Importer\Session\StatePathCodec;

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

    private function makeClient(): Importer
    {
        return new Importer('http://fake.url', $this->stateDir, $this->fs_root);
    }

    private function writeState(array $state): void
    {
        $defaults = [
            "command" => null,
            "status" => null,
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "error",
            "max_allowed_packet" => null,
        ];
        $state = array_merge($defaults, $state);
        $this->writePreflightCheckpoint($state);
        unset(
            $state['preflight'],
            $state['remote_protocol_version'],
            $state['remote_protocol_min_version'],
            $state['version'],
            $state['webhost'],
        );

        file_put_contents(
            $this->stateDir . '/.reprint/run.json',
            json_encode($state, JSON_PRETTY_PRINT),
        );
    }

    private function writePreflightCheckpoint(array $state): void
    {
        $dir = $this->stateDir . '/.reprint/preflight';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $codec = new StatePathCodec();
        $checkpoint = PreflightCheckpoint::from_array($state);
        file_put_contents(
            $dir . '/checkpoint.json',
            json_encode(
                $checkpoint->to_persisted_array([$codec, 'encode_preflight_data_paths']),
                JSON_PRETTY_PRINT,
            ),
        );
    }

    private function downloadRuntimeFiles(Importer $client): void
    {
        $context = $client->context();
        $context->state();

        (new ImportServices($context))->runtime()->download_runtime_files();
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
        $this->downloadRuntimeFiles($client);

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
        $this->downloadRuntimeFiles($client);

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

        // This must not throw — failures are caught internally.
        $this->downloadRuntimeFiles($client);

        // The directory should exist even though no files were downloaded.
        $this->assertDirectoryExists($this->stateDir . '/runtime_files');
    }

    public function testDownloaderSavesFetchedRuntimeFile(): void
    {
        $runtimeDir = $this->stateDir . '/runtime_files';
        $audit = [];
        $urls = [];

        $downloader = new RuntimeFilesDownloader(
            new RuntimeFilesTestStreamClient($this, $urls),
            new RuntimeFilesTestAuditLogger($audit),
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

    public function testDownloaderRejectsFetchedRuntimeFilePathTraversal(): void
    {
        $runtimeDir = $this->stateDir . '/runtime_files';
        $audit = [];
        $urls = [];

        $downloader = new RuntimeFilesDownloader(
            new RuntimeFilesTestStreamClient(
                $this,
                $urls,
                '/../escape.php',
                "<?php\n// escaped\n",
            ),
            new RuntimeFilesTestAuditLogger($audit),
        );

        $downloaded = $downloader->download(
            [
                'runtime' => [
                    'ini_get_all' => [
                        'auto_prepend_file' => '/scripts/env.php',
                        'auto_append_file' => '',
                    ],
                ],
            ],
            $runtimeDir,
        );

        $this->assertSame(0, $downloaded);
        $this->assertFileDoesNotExist($this->stateDir . '/escape.php');
        $this->assertStringContainsString(
            'RUNTIME FILES | refusing invalid runtime file path /../escape.php',
            implode("\n", $audit),
        );
    }

    public function testDownloaderRejectsFetchedRuntimeFileThroughSymlinkParent(): void
    {
        $runtimeDir = $this->stateDir . '/runtime_files';
        $outsideDir = $this->tempDir . '/outside';
        mkdir($outsideDir, 0755);
        $outside = $outsideDir . '/escape.php';
        file_put_contents($outside, 'keep');
        $audit = [];
        $urls = [];

        $downloader = new RuntimeFilesDownloader(
            new RuntimeFilesTestStreamClient(
                $this,
                $urls,
                '/linked/escape.php',
                "<?php\n// escaped\n",
                function () use ($runtimeDir, $outsideDir): void {
                    if (!is_dir($runtimeDir)) {
                        mkdir($runtimeDir, 0755, true);
                    }
                    if (!@symlink($outsideDir, $runtimeDir . '/linked')) {
                        $this->markTestSkipped('Symlinks are not available on this filesystem.');
                    }
                },
            ),
            new RuntimeFilesTestAuditLogger($audit),
        );

        $downloaded = $downloader->download(
            [
                'runtime' => [
                    'ini_get_all' => [
                        'auto_prepend_file' => '/scripts/env.php',
                        'auto_append_file' => '',
                    ],
                ],
            ],
            $runtimeDir,
        );

        $this->assertSame(0, $downloaded);
        $this->assertSame('keep', file_get_contents($outside));
        $this->assertStringContainsString(
            'RUNTIME FILES | refusing runtime file path /linked/escape.php',
            implode("\n", $audit),
        );
    }
}

final class RuntimeFilesTestStreamClient implements FileSyncStreamClient
{
    private RuntimeFilesTest $test;
    private $urls;
    private string $emittedPath;
    private string $body;
    private $beforeEmit;

    public function __construct(
        RuntimeFilesTest $test,
        array &$urls,
        string $emittedPath = '/scripts/env.php',
        string $body = "<?php\nreturn ['loaded' => true];\n",
        ?callable $beforeEmit = null
    )
    {
        $this->test = $test;
        $this->urls =& $urls;
        $this->emittedPath = $emittedPath;
        $this->body = $body;
        $this->beforeEmit = $beforeEmit;
    }

    public function build_url(string $endpoint, ?string $cursor, array $params): string
    {
        $this->test->assertSame('file_fetch', $endpoint);
        $this->test->assertNull($cursor);
        $this->test->assertSame(['/scripts'], $params['directory']);

        $url = 'http://fake.url/export.php?endpoint=' . $endpoint;
        $this->urls[] = $url;

        return $url;
    }

    public function tuned_params(string $endpoint): array
    {
        return [];
    }

    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        $this->test->assertSame('http://fake.url/export.php?endpoint=file_fetch', $url);
        $this->test->assertNull($cursor);
        $this->test->assertSame('file_fetch', $phase);
        $this->test->assertIsArray($post_data);
        $this->test->assertArrayHasKey('file_list', $post_data);

        if ($this->beforeEmit) {
            ($this->beforeEmit)();
        }

        ($context->on_chunk)([
            'headers' => [
                'x-chunk-type' => 'file',
                'x-file-path' => base64_encode($this->emittedPath),
                'x-first-chunk' => '1',
                'x-last-chunk' => '1',
            ],
            'body' => $this->body,
        ]);
        ($context->on_chunk)([
            'headers' => [
                'x-chunk-type' => 'completion',
            ],
        ]);
    }

    public function finalize_request(string $endpoint, float $wall_time, array $response_stats): void
    {
    }
}

final class RuntimeFilesTestAuditLogger implements AuditLogger
{
    private $messages;

    public function __construct(array &$messages)
    {
        $this->messages =& $messages;
    }

    public function record(string $message, bool $to_console = true): void
    {
        $this->messages[] = $message;
    }

    public function path(): string
    {
        return '';
    }
}
