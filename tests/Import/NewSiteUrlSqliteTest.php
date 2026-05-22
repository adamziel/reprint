<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

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
     * Write the import state file that db-apply expects.
     */
    private function writeState(array $extra = []): void
    {
        $state = array_merge([
            'command' => null,
            'status' => null,
            'apply' => [],
        ], $extra);
        file_put_contents(
            $this->tempDir . '/.import-state.json',
            json_encode($state, JSON_PRETTY_PRINT),
        );
    }

    private function buildRowStreamSidecar(\ImportClient $client, string $sqlFile): void
    {
        $method = new \ReflectionMethod($client, 'build_sqlite_row_stream_sidecar');
        $method->setAccessible(true);
        $method->invoke($client, $sqlFile);
    }

    private function sidecarOffsetAfterRecords(string $sidecarPath, int $records): int
    {
        $handle = fopen($sidecarPath, 'r');
        $this->assertIsResource($handle);
        for ($i = 0; $i <= $records; $i++) {
            $line = fgets($handle);
            $this->assertNotFalse($line);
        }
        $offset = ftell($handle);
        fclose($handle);
        $this->assertIsInt($offset);
        return $offset;
    }

    /**
     * Read a value from the SQLite database using the MySQL-on-SQLite driver.
     */
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

        $sqlite_pdo = $pdo->get_connection()->get_pdo();
        \register_sqlite_function($sqlite_pdo, 'FROM_BASE64', function ($data) {
            return $data === null ? null : base64_decode($data);
        });

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
        $client = new \ImportClient(
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

        $client = new \ImportClient(
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

        $client = new \ImportClient(
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
            "SELECT hex(option_value) AS hex_value FROM wp_options WHERE option_name = 'binary_payload'",
            'wp_test',
        );

        $this->assertCount(1, $rows);
        $this->assertSame(strtoupper(bin2hex($bytes)), $rows[0]['hex_value']);
    }

    public function testExperimentalRowStreamAppliesStructuredAndFallbackRecordsToSqlite(): void
    {
        $oldUrl = 'https://old-site.example.com';
        $newUrl = 'https://new-site.example.com';
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
            . "(1, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s')), "
            . "(2, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('siteurl'),
            base64_encode($oldUrl),
            base64_encode('yes'),
            base64_encode('home'),
            base64_encode($oldUrl),
            base64_encode('yes'),
        );
        $stmts[] = "DROP TABLE IF EXISTS `wp_posts`;";
        $stmts[] = "CREATE TABLE `wp_posts` ("
            . "`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`post_content` longtext NOT NULL, "
            . "`menu_order` int(11) NOT NULL DEFAULT 0, "
            . "PRIMARY KEY (`ID`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $stmts[] = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`, `menu_order`) VALUES "
            . "(1, FROM_BASE64('%s'), -3.5e+2);",
            base64_encode('<a href="' . $oldUrl . '/structured">Structured</a>'),
        );
        $stmts[] = sprintf(
            "INSERT INTO `wp_posts` VALUES (2, FROM_BASE64('%s'), 4);",
            base64_encode('<a href="' . $oldUrl . '/fallback">Fallback</a>'),
        );
        $sql = implode("\n", $stmts);
        $sqlFile = $this->tempDir . '/db.sql';
        file_put_contents($sqlFile, $sql);

        $state = [
            'preflight' => [
                'data' => [
                    'database' => ['wp' => ['table_prefix' => 'wp_']],
                ],
            ],
        ];
        $this->writeState($state);

        $client = new \ImportClient(
            $oldUrl . '/?reprint-api',
            $this->tempDir,
            $this->tempDir . '/fs-root',
        );
        $client->state = array_merge($client->default_state(), $state);
        $this->buildRowStreamSidecar($client, $sqlFile);

        $sidecarPath = $this->tempDir . '/.import-sqlite-row-stream.jsonl';
        $this->assertFileExists($sidecarPath);
        $sidecarState = json_decode(file_get_contents($this->tempDir . '/.import-state.json'), true);
        $this->assertGreaterThanOrEqual(2, $sidecarState['sqlite_row_stream']['structured_inserts']);
        $this->assertGreaterThanOrEqual(1, $sidecarState['sqlite_row_stream']['fallback_statements']);

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
            'experimental_sqlite_row_stream' => true,
        ]);

        $posts = $this->querySqlite(
            $sqlitePath,
            'SELECT ID, post_content, menu_order FROM wp_posts ORDER BY ID',
            'wp_test',
        );

        $this->assertCount(2, $posts);
        $this->assertSame('<a href="' . $newUrl . '/structured">Structured</a>', $posts[0]['post_content']);
        $this->assertEquals(-350, $posts[0]['menu_order']);
        $this->assertSame('<a href="' . $newUrl . '/fallback">Fallback</a>', $posts[1]['post_content']);

        $state = json_decode(file_get_contents($this->tempDir . '/.import-state.json'), true);
        $this->assertSame('complete', $state['status']);
        $this->assertSame(count($stmts), $state['apply']['statements_executed']);
        $this->assertSame(strlen($sql), $state['apply']['bytes_read']);
        $this->assertSame(filesize($sidecarPath), $state['apply']['row_stream_bytes_read']);
    }

    public function testExperimentalRowStreamResumesPartialState(): void
    {
        $sqlitePath = $this->tempDir . '/database/wordpress.sqlite';
        $create = "CREATE TABLE `wp_resume` (" .
            "`id` bigint(20) unsigned NOT NULL, " .
            "`name` longtext NOT NULL, " .
            "PRIMARY KEY (`id`)" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $firstInsert = sprintf(
            "INSERT INTO `wp_resume` (`id`, `name`) VALUES (1, FROM_BASE64('%s'));",
            base64_encode('one'),
        );
        $secondInsert = sprintf(
            "INSERT INTO `wp_resume` (`id`, `name`) VALUES (2, FROM_BASE64('%s'));",
            base64_encode('two'),
        );
        $prefixSql = implode("\n", [$create, $firstInsert]);
        $fullSql = implode("\n", [$create, $firstInsert, $secondInsert]);
        $sqlFile = $this->tempDir . '/db.sql';

        file_put_contents($sqlFile, $prefixSql);
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

        file_put_contents($sqlFile, $fullSql);
        $client = new \ImportClient(
            'https://old-site.example.com/?reprint-api',
            $this->tempDir,
            $this->tempDir . '/fs-root',
        );
        $this->buildRowStreamSidecar($client, $sqlFile);
        $sidecarPath = $this->tempDir . '/.import-sqlite-row-stream.jsonl';
        $this->writeState([
            'command' => 'db-apply',
            'status' => 'partial',
            'apply' => [
                'statements_executed' => 2,
                'bytes_read' => strlen($prefixSql),
                'row_stream_bytes_read' => $this->sidecarOffsetAfterRecords($sidecarPath, 2),
                'target_engine' => 'sqlite',
                'target_db' => 'wp_test',
                'target_sqlite_path' => $sqlitePath,
            ],
        ]);

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
            'experimental_sqlite_row_stream' => true,
        ]);

        $rows = $this->querySqlite(
            $sqlitePath,
            'SELECT id, name FROM wp_resume ORDER BY id',
            'wp_test',
        );
        $rows = array_map(
            fn(array $row): array => ['id' => $row['id'], 'name' => $row['name']],
            $rows,
        );
        $this->assertSame([
            ['id' => 1, 'name' => 'one'],
            ['id' => 2, 'name' => 'two'],
        ], $rows);

        $state = json_decode(file_get_contents($this->tempDir . '/.import-state.json'), true);
        $this->assertSame('complete', $state['status']);
        $this->assertSame(3, $state['apply']['statements_executed']);
        $this->assertSame(strlen($fullSql), $state['apply']['bytes_read']);
        $this->assertSame(filesize($sidecarPath), $state['apply']['row_stream_bytes_read']);
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

        $state = json_decode(file_get_contents($this->tempDir . '/.import-state.json'), true);
        $this->assertSame('complete', $state['status']);
        $this->assertSame(3, $state['apply']['statements_executed']);
        $this->assertSame(strlen($sql), $state['apply']['bytes_read']);
    }
}
