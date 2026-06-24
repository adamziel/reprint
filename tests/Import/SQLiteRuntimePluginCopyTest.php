<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use function Reprint\Importer\TargetRuntime\copy_sqlite_plugin;

class SQLiteRuntimePluginCopyTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sqlite-runtime-plugin-copy-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testCopiesSplitDatabaseDriverIntoRuntimePlugin(): void
    {
        $pluginSource = $this->createSplitSqliteSource();
        $outputDir = $this->tempDir . '/runtime';

        $copiedPlugin = copy_sqlite_plugin($pluginSource, $outputDir);

        $this->assertSame($outputDir . '/sqlite-database-integration', $copiedPlugin);
        $this->assertFileExists($copiedPlugin . '/wp-includes/sqlite/db.php');
        $this->assertFileExists($copiedPlugin . '/wp-includes/database/version.php');
        $this->assertFileExists($copiedPlugin . '/wp-includes/database/load.php');
    }

    public function testRepairsExistingRuntimePluginMissingDatabaseDriver(): void
    {
        $pluginSource = $this->createSplitSqliteSource();
        $outputDir = $this->tempDir . '/runtime';
        $existingPlugin = $outputDir . '/sqlite-database-integration';
        mkdir($existingPlugin . '/wp-includes/sqlite', 0755, true);
        mkdir($existingPlugin . '/wp-includes/database', 0755, true);
        file_put_contents($existingPlugin . '/wp-includes/sqlite/db.php', "<?php\n");

        $copiedPlugin = copy_sqlite_plugin($pluginSource, $outputDir);

        $this->assertSame($existingPlugin, $copiedPlugin);
        $this->assertFileExists($copiedPlugin . '/wp-includes/database/version.php');
        $this->assertFileExists($copiedPlugin . '/wp-includes/database/load.php');
    }

    private function createSplitSqliteSource(): string
    {
        $root = $this->tempDir . '/sqlite-source/packages';
        $pluginSource = $root . '/plugin-sqlite-database-integration';
        $driverSource = $root . '/mysql-on-sqlite/src';

        mkdir($pluginSource . '/wp-includes/sqlite', 0755, true);
        mkdir($driverSource, 0755, true);
        file_put_contents($pluginSource . '/wp-includes/sqlite/db.php', "<?php\nrequire_once __DIR__ . '/../database/version.php';\n");
        file_put_contents($driverSource . '/version.php', "<?php\ndefine('SQLITE_DRIVER_VERSION', 'test');\n");
        file_put_contents($driverSource . '/load.php', "<?php\n");

        return $pluginSource;
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
