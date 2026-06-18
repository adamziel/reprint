<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Integration test for the full db-apply flow: a dump carrying an
 * `_edit_lock` postmeta row is applied to a SQLite target, and db-apply
 * must strip the lock (so the local copy shows no phantom "X is currently
 * editing" badge) while leaving every other postmeta row intact.
 */
class DbApplyEditLockTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $this->tempDir = sys_get_temp_dir() . '/import-edit-lock-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);
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
     * Build a dump with a wp_postmeta table holding an `_edit_lock` row and
     * a `_thumbnail_id` row, using FROM_BASE64 like the real dump output.
     */
    private function buildPostmetaDump(): string
    {
        $stmts = [];
        $stmts[] = "DROP TABLE IF EXISTS `wp_postmeta`;";
        $stmts[] = "CREATE TABLE `wp_postmeta` ("
            . "`meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`post_id` bigint(20) unsigned NOT NULL DEFAULT 0, "
            . "`meta_key` varchar(255) DEFAULT NULL, "
            . "`meta_value` longtext, "
            . "PRIMARY KEY (`meta_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $stmts[] = sprintf(
            "INSERT INTO `wp_postmeta` VALUES "
            . "(1, 1, FROM_BASE64('%s'), FROM_BASE64('%s')), "
            . "(2, 1, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('_edit_lock'),
            base64_encode('1781114164:51814349'),
            base64_encode('_thumbnail_id'),
            base64_encode('42'),
        );
        return implode("\n", $stmts) . "\n";
    }

    private function writeState(): void
    {
        file_put_contents(
            $this->tempDir . '/.import-state.json',
            json_encode(['command' => null, 'status' => null, 'apply' => []], JSON_PRETTY_PRINT),
        );
    }

    private function querySqlite(string $dbPath, string $sql, string $dbName): array
    {
        $polyfills = resolve_sqlite_integration_path("/php-polyfills.php");
        $driver = resolve_sqlite_integration_path("/wp-pdo-mysql-on-sqlite.php");
        require_once $polyfills;
        require_once $driver;

        $dsn = "mysql-on-sqlite:path={$dbPath};dbname={$dbName}";
        $pdo = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        \register_sqlite_function($pdo->get_connection()->get_pdo(), 'FROM_BASE64', function ($data) {
            return $data === null ? null : base64_decode($data);
        });

        return $pdo->query($sql)->fetchAll();
    }

    public function testDbApplyStripsEditLockButKeepsOtherMeta(): void
    {
        $sqlitePath = $this->tempDir . '/database/wordpress.sqlite';
        file_put_contents($this->tempDir . '/db.sql', $this->buildPostmetaDump());
        $this->writeState();

        $client = new \ImportClient(
            'https://old-site.example.com/?reprint-api',
            $this->tempDir,
            $this->tempDir . '/fs-root',
        );
        $client->run([
            'command' => 'db-apply',
            'abort' => false,
            'verbose' => false,
            'secret' => null,
            'tuning_config' => [],
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $sqlitePath,
            'target_db' => 'wp_test',
        ]);

        $rows = $this->querySqlite(
            $sqlitePath,
            "SELECT meta_key FROM wp_postmeta ORDER BY meta_id",
            'wp_test',
        );
        $keys = array_column($rows, 'meta_key');

        $this->assertNotContains(
            '_edit_lock',
            $keys,
            'Remote editor locks must not survive the import (they render as phantom "currently editing" badges)',
        );
        $this->assertContains(
            '_thumbnail_id',
            $keys,
            'Other postmeta must be left intact',
        );
    }
}
