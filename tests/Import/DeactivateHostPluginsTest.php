<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;
use Reprint\Importer\Sql\ActivePluginDeactivator;
use Reprint\Importer\Sql\TargetDatabaseConnectionFactory;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify ActivePluginDeactivator rewrites active_plugins identically on
 * MySQL and SQLite targets. Regression: prepare() used to throw "object
 * is uninitialized" against the WP_PDO_MySQL_On_SQLite wrapper.
 */
class DeactivateHostPluginsTest extends TestCase
{
    private string $tempDir;
    private ?PDO $cleanupPdo = null;
    private ?string $mysqlDbName = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/deactivate-host-plugins-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->cleanupPdo !== null && $this->mysqlDbName !== null) {
            try {
                $this->cleanupPdo->exec("DROP DATABASE IF EXISTS `{$this->mysqlDbName}`");
            } catch (PDOException $_) {
                // best-effort
            }
        }
        $this->cleanupPdo = null;
        $this->mysqlDbName = null;
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public static function targetProvider(): array
    {
        return [
            'mysql' => ['mysql'],
            'sqlite' => ['sqlite'],
        ];
    }

    /**
     * @dataProvider targetProvider
     */
    public function testRemovesHostPluginsFromActivePluginsOption(string $engine): void
    {
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo);
        $this->insertOption($pdo, 'active_plugins', serialize([
            'sg-cachepress/sg-cachepress.php',
            'sg-security/sg-security.php',
            'woocommerce/woocommerce.php',
            'akismet/akismet.php',
        ]));

        $result = ActivePluginDeactivator::deactivate_for_removed_paths(
            $pdo,
            [
                'wp-content/plugins/sg-cachepress',
                'wp-content/plugins/sg-security',
            ],
        );

        sort($result);
        $this->assertSame(
            [
                'sg-cachepress/sg-cachepress.php',
                'sg-security/sg-security.php',
            ],
            $result,
            'expected only sg-* entries to be reported as deactivated',
        );

        $remaining = unserialize($this->fetchOption($pdo, 'active_plugins'));
        $this->assertSame(
            [
                'woocommerce/woocommerce.php',
                'akismet/akismet.php',
            ],
            array_values($remaining),
            'expected non-host plugins to be preserved in order',
        );
    }

    /**
     * @dataProvider targetProvider
     */
    public function testHonorsCustomTablePrefix(string $engine): void
    {
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo, 'custom_');
        $this->insertOption($pdo, 'active_plugins', serialize([
            'sg-cachepress/sg-cachepress.php',
            'akismet/akismet.php',
        ]), 'custom_');

        $result = ActivePluginDeactivator::deactivate_for_removed_paths(
            $pdo,
            ['wp-content/plugins/sg-cachepress'],
            'custom_',
        );
        $this->assertSame(['sg-cachepress/sg-cachepress.php'], $result);

        $remaining = unserialize($this->fetchOption($pdo, 'active_plugins', 'custom_'));
        $this->assertSame(['akismet/akismet.php'], array_values($remaining));
    }

    /**
     * @dataProvider targetProvider
     */
    public function testReturnsEmptyWhenNoHostPluginsUnderPluginsDir(string $engine): void
    {
        // wpcloud only declares paths under mu-plugins and object-cache.php;
        // none match wp-content/plugins/, so deactivate should be a no-op
        // and the active_plugins value must be untouched.
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo);
        $serialized = serialize(['akismet/akismet.php']);
        $this->insertOption($pdo, 'active_plugins', $serialized);

        $result = ActivePluginDeactivator::deactivate_for_removed_paths(
            $pdo,
            [
                'wp-content/object-cache.php',
                'wp-content/mu-plugins/wpcomsh',
            ],
        );
        $this->assertSame([], $result);
        $this->assertSame($serialized, $this->fetchOption($pdo, 'active_plugins'));
    }

    /**
     * @dataProvider targetProvider
     */
    public function testReturnsEmptyWhenActivePluginsRowMissing(string $engine): void
    {
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo);
        // Intentionally no active_plugins row.

        $result = ActivePluginDeactivator::deactivate_for_removed_paths(
            $pdo,
            ['wp-content/plugins/sg-cachepress'],
        );
        $this->assertSame([], $result);
    }

    /**
     * @dataProvider targetProvider
     */
    public function testPathIncompatiblePluginsOnlyDeactivateForSubpathUrls(string $engine): void
    {
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo);
        $this->insertOption($pdo, 'active_plugins', serialize([
            'page-optimize/page-optimize.php',
            'akismet/akismet.php',
        ]));

        $result = ActivePluginDeactivator::deactivate_path_incompatible(
            $pdo,
            'https://playground.test/scope:abc',
        );

        $this->assertSame(['page-optimize/page-optimize.php'], $result);
        $remaining = unserialize($this->fetchOption($pdo, 'active_plugins'));
        $this->assertSame(['akismet/akismet.php'], array_values($remaining));
    }

    /**
     * @dataProvider targetProvider
     */
    public function testPathIncompatiblePluginsNoopForRootUrls(string $engine): void
    {
        $pdo = $this->createPdo($engine);
        $this->createWpOptionsTable($pdo);
        $serialized = serialize(['page-optimize/page-optimize.php']);
        $this->insertOption($pdo, 'active_plugins', $serialized);

        $result = ActivePluginDeactivator::deactivate_path_incompatible(
            $pdo,
            'https://example.test/',
        );

        $this->assertSame([], $result);
        $this->assertSame($serialized, $this->fetchOption($pdo, 'active_plugins'));
    }

    // ---- helpers ----

    private function createPdo(string $engine): PDO
    {
        if ($engine === 'mysql') {
            return $this->createMysqlPdo();
        }
        if ($engine === 'sqlite') {
            return $this->createSqlitePdo();
        }
        $this->fail("unknown engine: {$engine}");
    }

    private function createMysqlPdo(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $this->mysqlDbName = 'test_deactivate_host_plugins_' . bin2hex(random_bytes(4));

        try {
            $root = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            if (getenv('REPRINT_REQUIRE_MYSQL')) {
                throw $e;
            }
            $this->markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }

        $root->exec("DROP DATABASE IF EXISTS `{$this->mysqlDbName}`");
        $root->exec("CREATE DATABASE `{$this->mysqlDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->cleanupPdo = $root;

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$this->mysqlDbName};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
        return $pdo;
    }

    private function createSqlitePdo(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        return TargetDatabaseConnectionFactory::sqlite(
            $this->tempDir . '/target.sqlite',
            'test_db',
        );
    }

    private function createWpOptionsTable(PDO $pdo, string $prefix = 'wp_'): void
    {
        $table = '`' . $prefix . 'options`';
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
        $pdo->exec(
            "CREATE TABLE {$table} ("
            . "`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`option_name` varchar(191) NOT NULL DEFAULT '', "
            . "`option_value` longtext NOT NULL, "
            . "`autoload` varchar(20) NOT NULL DEFAULT 'yes', "
            . "PRIMARY KEY (`option_id`), "
            . "UNIQUE KEY `option_name` (`option_name`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function insertOption(PDO $pdo, string $name, string $value, string $prefix = 'wp_'): void
    {
        // Use exec() with ANSI-escaped literals to stay within the universal
        // PDO surface that WP_PDO_MySQL_On_SQLite supports.
        $table = '`' . $prefix . 'options`';
        $quotedName = $this->ansiQuote($name);
        $quotedValue = $this->ansiQuote($value);
        $pdo->exec(
            "INSERT INTO {$table} (option_name, option_value, autoload) "
            . "VALUES ({$quotedName}, {$quotedValue}, 'yes')"
        );
    }

    private function fetchOption(PDO $pdo, string $name, string $prefix = 'wp_'): string
    {
        $table = '`' . $prefix . 'options`';
        $quotedName = $this->ansiQuote($name);
        $stmt = $pdo->query(
            "SELECT option_value FROM {$table} WHERE option_name = {$quotedName}"
        );
        $this->assertNotFalse($stmt, 'query returned false');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "no row for option {$name}");
        return $row['option_value'];
    }

    private function ansiQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
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
}
