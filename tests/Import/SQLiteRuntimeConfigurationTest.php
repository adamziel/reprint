<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Observability\NullAuditLogger;
use Reprint\Importer\TargetRuntime\RuntimeConfigurationApplier;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/bootstrap.php';

class SQLiteRuntimeConfigurationTest extends TestCase
{
    private string $tempDir;
    private string $fsRoot;
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sqlite-runtime-config-' . uniqid('', true);
        $this->fsRoot = $this->tempDir . '/files';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->fsRoot . '/srv/htdocs/wp-content/database', 0755, true);
        mkdir($this->fsRoot . '/wordpress/core/7.0', 0755, true);
        file_put_contents($this->fsRoot . '/srv/htdocs/index.php', "<?php\n");
        file_put_contents($this->fsRoot . '/wordpress/core/7.0/index.php', "<?php\n");
        file_put_contents($this->fsRoot . '/srv/htdocs/wp-content/database/.ht.sqlite', '');
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testSqliteRuntimeDefinesDatabaseName(): void
    {
        $applier = new RuntimeConfigurationApplier(new NullAuditLogger());

        $applier->apply([
            'runtime' => 'php-builtin',
            'output_dir' => $this->outputDir,
            'fs_root' => $this->fsRoot,
            'webhost' => 'other',
            'preflight_data' => [
                'runtime' => [
                    'document_root' => '/srv/htdocs',
                    'env_names' => [],
                    'ini_get_all' => [],
                ],
                'filesystem' => [
                    'directories' => [],
                ],
                'wp_detect' => [
                    'roots' => [],
                ],
                'database' => [
                    'wp' => [
                        'paths_urls' => [
                            'abspath' => '/wordpress/core/7.0/',
                            'content_dir' => '/srv/htdocs/wp-content',
                        ],
                    ],
                ],
            ],
            'apply_state' => [
                'target_engine' => 'sqlite',
                'target_db' => 'sqlite_database',
                'target_sqlite_path' => $this->fsRoot . '/srv/htdocs/wp-content/database/.ht.sqlite',
            ],
        ]);

        $runtime = file_get_contents($this->outputDir . '/runtime.php');

        $this->assertStringContainsString("define('DB_NAME', 'sqlite_database');", $runtime);
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->recursiveDelete($path . '/' . $item);
        }

        rmdir($path);
    }
}
