<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Application\UseCase\RuntimeApplyHandler;
use Reprint\Importer\Application\Importer;
use Reprint\Importer\Session\PreflightCheckpoint;
use Reprint\Importer\Session\StatePathCodec;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify that run_apply_runtime removes production drop-ins declared in
 * the host analyzer's paths_to_remove manifest field, and logs each removal.
 */
class ProductionDropInRemovalTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/production-drop-in-removal-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->stateDir, 0755, true);
        mkdir($this->stateDir . '/.reprint', 0755, true);
        mkdir($this->fsRoot, 0755, true);
        mkdir($this->outputDir, 0755, true);
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
                        'document_root' => '/srv/htdocs',
                        'env_names' => ['PRIVACY_MODEL'],
                        'ini_get_all' => [
                            'auto_prepend_file' => '',
                        ],
                    ],
                    'filesystem' => [
                        'directories' => [
                            ['path' => '/srv/htdocs/__wp__', 'exists' => true],
                        ],
                    ],
                    'wp_detect' => [
                        'roots' => [
                            ['path' => '/srv/htdocs/__wp__'],
                        ],
                    ],
                    'database' => [
                        'wp' => [
                            'siteurl' => 'https://source.example',
                            'home' => 'https://source.example',
                            'paths_urls' => [
                                'abspath' => '/wordpress/core/6.7.2/',
                                'content_dir' => '/srv/htdocs/wp-content',
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
            'webhost' => 'wpcloud',
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'error',
            'filter' => 'none',
            'max_allowed_packet' => null,
        ];

        $state = array_replace_recursive($defaults, $state);
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

    private function makeClient(): Importer
    {
        return new Importer('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function readRuntimeCheckpoint(): array
    {
        return json_decode(
            file_get_contents($this->stateDir . '/.reprint/runtime/checkpoint.json'),
            true,
        );
    }

    private function runApplyRuntime(Importer $client): void
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
    }

    /**
     * Create the production drop-in files that WpcloudHostAnalyzer declares
     * for removal, so we can verify they get deleted.
     */
    private function createProductionDropIns(): void
    {
        // object-cache.php (file)
        $wpContent = $this->fsRoot . '/wp-content';
        mkdir($wpContent, 0755, true);
        file_put_contents($wpContent . '/object-cache.php', "<?php // Memcached object cache\n");

        // wpcomsh directory with files inside
        $muPlugins = $wpContent . '/mu-plugins';
        mkdir($muPlugins . '/wpcomsh', 0755, true);
        file_put_contents($muPlugins . '/wpcomsh/wpcomsh.php', "<?php // wpcomsh\n");
        file_put_contents($muPlugins . '/wpcomsh/functions.php', "<?php // functions\n");

        // wpcomsh-dev directory
        mkdir($muPlugins . '/wpcomsh-dev', 0755, true);
        file_put_contents($muPlugins . '/wpcomsh-dev/wpcomsh-dev.php', "<?php // wpcomsh-dev\n");

        // wpcomsh-loader.php (file)
        file_put_contents($muPlugins . '/wpcomsh-loader.php', "<?php // wpcomsh loader\n");
    }

    public function testApplyRuntimeRemovesObjectCacheFile(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        $objectCachePath = $this->fsRoot . '/wp-content/object-cache.php';
        $this->assertFileExists($objectCachePath);

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertFileDoesNotExist($objectCachePath);
    }

    public function testApplyRuntimeRemovesWpcomshDirectory(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        $wpcomshDir = $this->fsRoot . '/wp-content/mu-plugins/wpcomsh';
        $this->assertDirectoryExists($wpcomshDir);

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($wpcomshDir);
    }

    public function testApplyRuntimeRemovesWpcomshDevDirectory(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        $wpcomshDevDir = $this->fsRoot . '/wp-content/mu-plugins/wpcomsh-dev';
        $this->assertDirectoryExists($wpcomshDevDir);

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($wpcomshDevDir);
    }

    public function testApplyRuntimeRemovesWpcomshLoaderFile(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        $loaderPath = $this->fsRoot . '/wp-content/mu-plugins/wpcomsh-loader.php';
        $this->assertFileExists($loaderPath);

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertFileDoesNotExist($loaderPath);
    }

    public function testApplyRuntimeToleratesMissingDropIns(): void
    {
        // Don't create any drop-in files. The removal loop should skip
        // them gracefully without errors.
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        mkdir($this->fsRoot . '/wp-content', 0755, true);

        $client = $this->makeClient();

        // Should not throw.
        $this->runApplyRuntime($client);
        $this->assertTrue(true);
    }

    public function testApplyRuntimePreservesUnrelatedFiles(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        // Create a legitimate mu-plugin that should NOT be removed.
        $muPlugins = $this->fsRoot . '/wp-content/mu-plugins';
        file_put_contents($muPlugins . '/my-custom-plugin.php', "<?php // custom\n");

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertFileExists($muPlugins . '/my-custom-plugin.php');
    }

    public function testApplyRuntimeLogsRemovalsToAuditLog(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        // The audit log records every removal.
        $auditLog = file_get_contents($this->stateDir . '/.import-audit.log');

        $this->assertStringContainsString(
            'removed wp-content/object-cache.php (production-only)',
            $auditLog,
        );
        $this->assertStringContainsString(
            'removed wp-content/mu-plugins/wpcomsh (production-only)',
            $auditLog,
        );
        $this->assertStringContainsString(
            'removed wp-content/mu-plugins/wpcomsh-loader.php (production-only)',
            $auditLog,
        );
    }

    public function testApplyRuntimePersistsPathsRemovedToRuntimeCheckpoint(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $checkpoint = $this->readRuntimeCheckpoint();

        $this->assertContains('wp-content/object-cache.php', $checkpoint['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh', $checkpoint['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh-dev', $checkpoint['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh-loader.php', $checkpoint['remote_paths_removed_from_local_site']);
    }

    public function testNonWpcloudHostDoesNotRemoveAnything(): void
    {
        // Override webhost to 'other' — the DefaultHostAnalyzer should
        // produce an empty paths_to_remove.
        $this->writeState([
            'command' => 'files-pull',
            'status' => 'complete',
            'webhost' => 'other',
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                        'env_names' => [],
                        'ini_get_all' => [],
                    ],
                    'filesystem' => ['directories' => []],
                    'wp_detect' => ['roots' => []],
                ],
            ],
        ]);

        // Create an object-cache.php that a non-wpcloud host should leave alone.
        $wpContent = $this->fsRoot . '/wp-content';
        mkdir($wpContent, 0755, true);
        file_put_contents($wpContent . '/object-cache.php', "<?php // Redis object cache\n");

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertFileExists($wpContent . '/object-cache.php');
    }

    // ---- SiteGround-specific tests ----

    private function writeSitegroundState(array $overrides = []): void
    {
        $this->writeState(array_replace_recursive([
            'command' => 'files-sync',
            'status' => 'complete',
            'webhost' => 'siteground',
            'preflight' => [
                'data' => [
                    'runtime' => [
                        'document_root' => '',
                        'env_names' => [],
                        'ini_get_all' => [],
                    ],
                    'filesystem' => ['directories' => []],
                    'wp_detect' => ['roots' => []],
                    'wp_content' => [
                        'roots' => [
                            [
                                'plugins' => [
                                    ['name' => 'sg-cachepress', 'type' => 'dir'],
                                    ['name' => 'sg-security', 'type' => 'dir'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $overrides));
    }

    private function createSitegroundPlugins(): void
    {
        $plugins = $this->fsRoot . '/wp-content/plugins';
        mkdir($plugins . '/sg-cachepress', 0755, true);
        file_put_contents(
            $plugins . '/sg-cachepress/sg-cachepress.php',
            "<?php // SG CachePress\n",
        );
        mkdir($plugins . '/sg-security', 0755, true);
        file_put_contents(
            $plugins . '/sg-security/sg-security.php',
            "<?php // SG Security\n",
        );
    }

    public function testSitegroundRemovesSgCachepressDirectory(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $sgCacheDir = $this->fsRoot . '/wp-content/plugins/sg-cachepress';
        $this->assertDirectoryExists($sgCacheDir);

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($sgCacheDir);
    }

    public function testSitegroundRemovesSgSecurityDirectory(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $sgSecurityDir = $this->fsRoot . '/wp-content/plugins/sg-security';
        $this->assertDirectoryExists($sgSecurityDir);

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertDirectoryDoesNotExist($sgSecurityDir);
    }

    public function testSitegroundPreservesUnrelatedPlugins(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $plugins = $this->fsRoot . '/wp-content/plugins';
        mkdir($plugins . '/woocommerce', 0755, true);
        file_put_contents($plugins . '/woocommerce/woocommerce.php', "<?php // WooCommerce\n");

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $this->assertDirectoryExists($plugins . '/woocommerce');
    }

    public function testSitegroundLogsRemovalsToAuditLog(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $auditLog = file_get_contents($this->stateDir . '/.import-audit.log');

        $this->assertStringContainsString(
            'removed wp-content/plugins/sg-cachepress (production-only)',
            $auditLog,
        );
        $this->assertStringContainsString(
            'removed wp-content/plugins/sg-security (production-only)',
            $auditLog,
        );
    }

    public function testSitegroundPersistsPathsRemovedToRuntimeCheckpoint(): void
    {
        $this->writeSitegroundState();
        $this->createSitegroundPlugins();

        $client = $this->makeClient();
        $this->runApplyRuntime($client);

        $checkpoint = $this->readRuntimeCheckpoint();

        $this->assertContains(
            'wp-content/plugins/sg-cachepress',
            $checkpoint['remote_paths_removed_from_local_site'],
        );
        $this->assertContains(
            'wp-content/plugins/sg-security',
            $checkpoint['remote_paths_removed_from_local_site'],
        );
    }

}
