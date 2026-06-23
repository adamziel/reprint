<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Application\UseCase\RuntimeApplyHandler;
use Reprint\Importer\Importer;

require_once __DIR__ . '/../../importer/import.php';

class RemoteUploadProxyRuntimeTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remote-upload-proxy-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->stateDir, 0755, true);
        mkdir($this->stateDir . '/.reprint', 0755, true);
        mkdir($this->fsRoot, 0755, true);
        file_put_contents($this->fsRoot . '/index.php', "<?php echo 'ok';\n");
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
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }

            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }

        rmdir($dir);
    }

    private function writeState(array $state): void
    {
        $defaults = [
            'command' => null,
            'status' => null,
            'preflight' => [
                'http_code' => 200,
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                    ],
                    'database' => [
                        'wp' => [
                            'siteurl' => 'https://source.example',
                            'home' => 'https://source.example',
                            'paths_urls' => [
                                'abspath' => $this->fsRoot,
                                'uploads' => [
                                    'baseurl' => 'https://source.example/wp-content/uploads',
                                ],
                                'home_url' => 'https://source.example',
                                'site_url' => 'https://source.example',
                            ],
                        ],
                    ],
                ],
            ],
            'webhost' => 'other',
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'error',
            'filter' => 'none',
            'max_allowed_packet' => null,
        ];

        file_put_contents(
            $this->stateDir . '/.reprint/run.json',
            json_encode(array_replace_recursive($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function makeClient(): Importer
    {
        return new Importer('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function runApplyRuntime(Importer $client): string
    {
        $context = $client->context();
        $context->state();

        ob_start();
        try {
            (new RuntimeApplyHandler())->execute($context, new ImportServices($context), [
                'runtime' => 'php-builtin',
                'output_dir' => $this->outputDir,
                'flat_document_root' => $this->fsRoot,
            ]);
        } finally {
            ob_end_clean();
        }

        return file_get_contents($this->outputDir . '/runtime.php');
    }

    public function testApplyRuntimeAddsProxyWhenSkippedUploadsRemain(): void
    {
        $this->writeState([
            'command' => 'db-apply',
            'status' => 'complete',
            'filter' => 'essential-files',
        ]);
        file_put_contents(
            $this->stateDir . '/.import-download-list-skipped.jsonl',
            json_encode(['path' => '/wp-content/uploads/2024/01/photo.jpg']) . "\n",
        );

        $client = $this->makeClient();
        $runtime = $this->runApplyRuntime($client);

        $this->assertStringContainsString(
            "STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_BASEURL",
            $runtime,
        );
        $this->assertStringContainsString(
            "STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_STATE_FILE",
            $runtime,
        );
        $this->assertStringContainsString(
            "STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_SKIPPED_FILE",
            $runtime,
        );
        $this->assertStringContainsString(
            "https://source.example/wp-content/uploads",
            $runtime,
        );
        $this->assertStringContainsString(
            "Remote upload proxy failed.",
            $runtime,
        );
    }

    public function testApplyRuntimeAddsProxyWhileFilesSyncIsIncomplete(): void
    {
        $this->writeState([
            'command' => 'files-pull',
            'status' => 'partial',
        ]);

        $client = $this->makeClient();
        $runtime = $this->runApplyRuntime($client);

        $this->assertStringContainsString(
            "STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_BASEURL",
            $runtime,
        );
        $this->assertStringContainsString(
            "STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_STATE_FILE",
            $runtime,
        );
        $this->assertStringContainsString(
            "Proxy missing uploads from the source site until files-pull completes.",
            $runtime,
        );
    }

    public function testApplyRuntimeOmitsProxyAfterFilesSyncCompletes(): void
    {
        $this->writeState([
            'command' => 'files-pull',
            'status' => 'complete',
            'filter' => 'none',
        ]);

        $client = $this->makeClient();
        $runtime = $this->runApplyRuntime($client);

        $this->assertStringNotContainsString(
            "STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_BASEURL",
            $runtime,
        );
        $this->assertStringNotContainsString(
            "STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_STATE_FILE",
            $runtime,
        );
        $this->assertStringNotContainsString(
            "Remote upload proxy failed.",
            $runtime,
        );
    }
}
