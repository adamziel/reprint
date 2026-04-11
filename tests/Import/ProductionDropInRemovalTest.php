<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

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
            'cursor' => null,
            'stage' => null,
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

        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_replace_recursive($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('https://source.example/export.php', $this->stateDir, $this->fsRoot);
    }

    private function callPrivate(\ImportClient $client, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($client);
        $method_reflection = $reflection->getMethod($method);
        return $method_reflection->invoke($client, ...$args);
    }

    private function setPrivate(\ImportClient $client, string $property, $value): void
    {
        $reflection = new \ReflectionClass($client);
        $property_reflection = $reflection->getProperty($property);
        $property_reflection->setValue($client, $value);
    }

    private function loadClientState(\ImportClient $client): void
    {
        $state = $this->callPrivate($client, 'load_state');
        $this->setPrivate($client, 'state', $state);
    }

    private function runApplyRuntime(\ImportClient $client): void
    {
        ob_start();
        try {
            $this->callPrivate($client, 'run_apply_runtime', [[
                'runtime' => 'php-builtin',
                'output_dir' => $this->outputDir,
                'flat_document_root' => $this->fsRoot,
            ]]);
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
        $this->loadClientState($client);
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
        $this->loadClientState($client);
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
        $this->loadClientState($client);
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
        $this->loadClientState($client);
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
        $this->loadClientState($client);

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
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertFileExists($muPlugins . '/my-custom-plugin.php');
    }

    public function testApplyRuntimeLogsRemovalsToAuditLog(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        $client = $this->makeClient();
        $this->loadClientState($client);
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

    public function testApplyRuntimePersistsPathsRemovedToState(): void
    {
        $this->writeState(['command' => 'files-pull', 'status' => 'complete']);
        $this->createProductionDropIns();

        $client = $this->makeClient();
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        // Re-read the state file to verify paths_removed was persisted.
        $state = json_decode(
            file_get_contents($this->stateDir . '/.import-state.json'),
            true,
        );

        $this->assertArrayHasKey('remote_paths_removed_from_local_site', $state['apply']);
        $this->assertContains('wp-content/object-cache.php', $state['apply']['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh', $state['apply']['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh-dev', $state['apply']['remote_paths_removed_from_local_site']);
        $this->assertContains('wp-content/mu-plugins/wpcomsh-loader.php', $state['apply']['remote_paths_removed_from_local_site']);
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
        $this->loadClientState($client);
        $this->runApplyRuntime($client);

        $this->assertFileExists($wpContent . '/object-cache.php');
    }
}
