<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Sql\TargetDatabaseConnectionFactory;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class TargetDatabaseConnectionFactoryTest extends TestCase
{
    private string $temp_dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }
        $this->temp_dir = sys_get_temp_dir() . '/target-db-factory-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testSqliteCreatesParentDirectoryAndRegistersBase64Functions(): void
    {
        $sqlite_path = $this->temp_dir . '/database/target.sqlite';

        $pdo = TargetDatabaseConnectionFactory::sqlite($sqlite_path, 'wp_test');

        $this->assertDirectoryExists(dirname($sqlite_path));

        $row = $pdo->query(
            "SELECT FROM_BASE64('aGVsbG8=') AS decoded, TO_BASE64('world') AS encoded"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('hello', $row['decoded']);
        $this->assertSame(base64_encode('world'), $row['encoded']);
    }

    private function remove_path(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_link($path) || is_file($path)) {
            return unlink($path);
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->remove_path($path . '/' . $entry)) {
                return false;
            }
        }
        return rmdir($path);
    }
}
