<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\ImportClient;
use Reprint\Importer\Sql\TargetDatabaseConnectionFactory;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Test that --new-site-url rewrites siteurl and home when db-apply
 * targets a SQLite database. This is the integration test for the
 * full flow: SQL dump → URL rewriting → SQLite execution → verify.
 */
class NewSiteUrlSqliteTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $this->tempDir = sys_get_temp_dir() . '/import-new-site-url-sqlite-' . uniqid();
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
     * Build a minimal SQL dump that creates wp_options and inserts
     * siteurl and home rows. Uses FROM_BASE64() encoding exactly
     * like the real MySQLDumpProducer.
     */
    private function buildSqlDump(string $siteUrl): string
    {
        $stmts = [];
        $stmts[] = "DROP TABLE IF EXISTS `wp_options`;";
        $stmts[] = "CREATE TABLE `wp_options` ("
            . "`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`option_name` varchar(191) NOT NULL DEFAULT '', "
            . "`option_value` longtext NOT NULL, "
            . "`autoload` varchar(20) NOT NULL DEFAULT 'yes', "
            . "PRIMARY KEY (`option_id`), "
            . "UNIQUE KEY `option_name` (`option_name`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        // INSERT with FROM_BASE64 — mirrors real dump output
        $stmts[] = sprintf(
            "INSERT INTO `wp_options` VALUES "
            . "(1, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s')), "
            . "(2, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('siteurl'),
            base64_encode($siteUrl),
            base64_encode('yes'),
            base64_encode('home'),
            base64_encode($siteUrl),
            base64_encode('yes'),
        );

        // Add a non-URL option to make sure it's not clobbered
        $stmts[] = sprintf(
            "INSERT INTO `wp_options` VALUES "
            . "(3, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('blogname'),
            base64_encode('My Test Blog'),
            base64_encode('yes'),
        );

        return implode("\n", $stmts) . "\n";
    }

    /**
     * Write the import state and preflight checkpoint files that db-apply expects.
     */
    private function writeState(array $extra = []): void
    {
        $preflight = array_replace_recursive(
            [
                'http_code' => 200,
                'data' => [
                    'ok' => true,
                    'database' => [
                        'wp' => [
                            'table_prefix' => 'wp_',
                            'paths_urls' => [
                                'content_dir' => '/wp-content',
                            ],
                        ],
                    ],
                ],
            ],
            is_array($extra['preflight'] ?? null) ? $extra['preflight'] : [],
        );
        $webhost = is_string($extra['webhost'] ?? null) ? $extra['webhost'] : 'other';
        unset(
            $extra['preflight'],
            $extra['remote_protocol_version'],
            $extra['remote_protocol_min_version'],
            $extra['version'],
            $extra['webhost'],
        );

        $state = array_merge([
            'command' => null,
            'status' => null,
        ], $extra);
        if (!is_dir($this->tempDir . '/.reprint')) {
            mkdir($this->tempDir . '/.reprint', 0755, true);
        }
        if (!is_dir($this->tempDir . '/.reprint/preflight')) {
            mkdir($this->tempDir . '/.reprint/preflight', 0755, true);
        }
        file_put_contents(
            $this->tempDir . '/.reprint/run.json',
            json_encode($state, JSON_PRETTY_PRINT),
        );
        file_put_contents(
            $this->tempDir . '/.reprint/preflight/checkpoint.json',
            json_encode([
                'preflight' => $preflight,
                'remote_protocol_version' => null,
                'remote_protocol_min_version' => null,
                'version' => null,
                'webhost' => $webhost,
            ], JSON_PRETTY_PRINT),
        );
    }

    /**
     * Read a value from the SQLite database using the MySQL-on-SQLite driver.
     */
    private function querySqlite(string $dbPath, string $sql, string $dbName): array
    {
        $pdo = TargetDatabaseConnectionFactory::sqlite($dbPath, $dbName);

        return $pdo->query($sql)->fetchAll();
    }

    /**
     * --new-site-url rewrites siteurl and home in a SQLite target.
     */
    public function testNewSiteUrlRewritesSiteurlAndHomeInSqlite(): void
    {
        $oldUrl = 'https://old-site.example.com';
        $newUrl = 'https://brand-new.example.com';
        $exportUrl = 'https://old-site.example.com/?reprint-api';
        $sqlitePath = $this->tempDir . '/database/wordpress.sqlite';

        // Prepare db.sql
        file_put_contents($this->tempDir . '/db.sql', $this->buildSqlDump($oldUrl));
        $this->writeState();

        // Run db-apply via ImportClient
        $client = new ImportClient(
            $exportUrl,
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
            'new_site_url' => $newUrl,
        ]);

        // Verify siteurl and home were rewritten
        $rows = $this->querySqlite(
            $sqlitePath,
            "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl', 'home') ORDER BY option_name",
            'wp_test',
        );

        $this->assertCount(2, $rows, 'Expected siteurl and home rows');
        foreach ($rows as $row) {
            $this->assertSame(
                $newUrl,
                $row['option_value'],
                "Expected {$row['option_name']} to be rewritten to {$newUrl}, got: {$row['option_value']}",
            );
        }

        // Verify non-URL options are not clobbered
        $blogname = $this->querySqlite(
            $sqlitePath,
            "SELECT option_value FROM wp_options WHERE option_name = 'blogname'",
            'wp_test',
        );
        $this->assertSame('My Test Blog', $blogname[0]['option_value']);
    }

    /**
     * --new-site-url with an HTTP source also rewrites HTTPS variants.
     */
    public function testNewSiteUrlRewritesBothSchemes(): void
    {
        // The DB stores https:// but the export is served over http.
        // --new-site-url should still rewrite both variants.
        $httpsUrl = 'https://old-site.example.com';
        $httpUrl  = 'http://old-site.example.com';
        $newUrl   = 'https://new-site.example.com';
        $exportUrl = 'http://old-site.example.com/?reprint-api';
        $sqlitePath = $this->tempDir . '/database/wordpress.sqlite';

        // Build dump with HTTPS siteurl — note that the export URL uses HTTP
        file_put_contents($this->tempDir . '/db.sql', $this->buildSqlDump($httpsUrl));
        $this->writeState();

        $client = new ImportClient(
            $exportUrl,
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
            'new_site_url' => $newUrl,
        ]);

        $rows = $this->querySqlite(
            $sqlitePath,
            "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl', 'home') ORDER BY option_name",
            'wp_test',
        );

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                $newUrl,
                $row['option_value'],
                "Expected {$row['option_name']} to be rewritten from HTTPS variant, got: {$row['option_value']}",
            );
        }
    }

    /**
     * Subpath URLs are correctly rewritten (e.g. /wp-content/uploads/...).
     */
    public function testNewSiteUrlRewritesSubpathUrls(): void
    {
        $oldUrl = 'https://old-site.example.com';
        $newUrl = 'https://new-site.example.com';
        $exportUrl = 'https://old-site.example.com/?reprint-api';
        $sqlitePath = $this->tempDir . '/database/wordpress.sqlite';

        // Build a dump with a post that references the old URL in content
        $sql = $this->buildSqlDump($oldUrl);

        // Add wp_posts table with content containing old URLs
        $sql .= "DROP TABLE IF EXISTS `wp_posts`;\n";
        $sql .= "CREATE TABLE `wp_posts` ("
            . "`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`post_content` longtext NOT NULL, "
            . "`post_title` varchar(255) NOT NULL DEFAULT '', "
            . "PRIMARY KEY (`ID`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";

        $content = '<p>Visit <a href="https://old-site.example.com/about">About</a></p>'
            . '<!-- wp:image {"url":"https://old-site.example.com/wp-content/uploads/photo.jpg"} -->';
        $sql .= sprintf(
            "INSERT INTO `wp_posts` VALUES (1, FROM_BASE64('%s'), FROM_BASE64('%s'));\n",
            base64_encode($content),
            base64_encode('Test Post'),
        );

        file_put_contents($this->tempDir . '/db.sql', $sql);
        $this->writeState([
            'preflight' => [
                'data' => [
                    'database' => ['wp' => ['table_prefix' => 'wp_']],
                ],
            ],
        ]);

        $client = new ImportClient(
            $exportUrl,
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
            'new_site_url' => $newUrl,
        ]);

        // Verify post_content URLs were rewritten
        $posts = $this->querySqlite(
            $sqlitePath,
            "SELECT post_content FROM wp_posts WHERE ID = 1",
            'wp_test',
        );

        $this->assertCount(1, $posts);
        $postContent = $posts[0]['post_content'];
        $this->assertStringContainsString('https://new-site.example.com/about', $postContent);
        $this->assertStringContainsString('https://new-site.example.com/wp-content/uploads/photo.jpg', $postContent);
        $this->assertStringNotContainsString('old-site.example.com', $postContent);
    }

    public function testSqliteDbApplyPreservesArbitraryBase64Bytes(): void
    {
        $bytes = '';
        for ($i = 0; $i <= 255; $i++) {
            $bytes .= chr($i);
        }

        $sqlitePath = $this->tempDir . '/database/wordpress.sqlite';
        $stmts = [];
        $stmts[] = "DROP TABLE IF EXISTS `wp_options`;";
        $stmts[] = "CREATE TABLE `wp_options` ("
            . "`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`option_name` varchar(191) NOT NULL DEFAULT '', "
            . "`option_value` longtext NOT NULL, "
            . "`autoload` varchar(20) NOT NULL DEFAULT 'yes', "
            . "PRIMARY KEY (`option_id`), "
            . "UNIQUE KEY `option_name` (`option_name`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $stmts[] = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES "
            . "(1, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('binary_payload'),
            base64_encode($bytes),
            base64_encode('yes')
        );

        file_put_contents($this->tempDir . '/db.sql', implode("\n", $stmts) . "\n");
        $this->writeState();

        $client = new ImportClient(
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
            "SELECT hex(option_value) AS hex_value FROM wp_options WHERE option_name = 'binary_payload'",
            'wp_test',
        );

        $this->assertCount(1, $rows);
        $this->assertSame(strtoupper(bin2hex($bytes)), $rows[0]['hex_value']);
    }

    public function testSqliteImportPragmasDoNotChangeProgressCounters(): void
    {
        $sqlitePath = $this->tempDir . '/database/wordpress.sqlite';
        $drop = "DROP TABLE IF EXISTS `wp_options`;";
        $create = "CREATE TABLE `wp_options` ("
            . "`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`option_name` varchar(191) NOT NULL DEFAULT '', "
            . "`option_value` longtext NOT NULL, "
            . "`autoload` varchar(20) NOT NULL DEFAULT 'yes', "
            . "PRIMARY KEY (`option_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $insert = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES "
            . "(1, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('siteurl'),
            base64_encode('https://example.com'),
            base64_encode('yes')
        );
        $sql = implode("\n", [$drop, $create, $insert]);

        file_put_contents($this->tempDir . '/db.sql', $sql);
        $this->writeState();

        $client = new ImportClient(
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

        $state = json_decode(file_get_contents($this->tempDir . '/.reprint/run.json'), true);
        $checkpoint = json_decode(
            file_get_contents($this->tempDir . '/.reprint/db-apply/checkpoint.json'),
            true,
        );
        $this->assertSame('complete', $state['status']);
        $this->assertSame(3, $checkpoint['statements_executed']);
        $this->assertSame(strlen($sql), $checkpoint['bytes_read']);
    }
}
