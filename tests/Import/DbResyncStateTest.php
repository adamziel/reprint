<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Test database re-sync behavior: re-running db-pull / db-apply on a
 * completed state performs a refresh (full re-dump / re-apply) instead
 * of throwing "already completed … use --abort".
 *
 * The round-trip test simulates the real re-pull flow: apply a dump,
 * replace it with a newer dump containing an edited row, a new row, and
 * a deleted row, re-apply, and verify the target matches the new dump
 * exactly.
 */
class DbResyncStateTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/db-resync-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fs_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fs_root, 0755, true);
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

    private function writeState(array $state): void
    {
        $defaults = [
            "command" => null,
            "status" => null,
            "cursor" => null,
            "stage" => null,
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "follow_symlinks" => false,
            "max_allowed_packet" => null,
        ];
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function readState(): array
    {
        return json_decode(
            file_get_contents($this->stateDir . '/.import-state.json'),
            true,
        );
    }

    /**
     * Build a minimal wp_posts dump in the real producer's format
     * (DROP + CREATE + FROM_BASE64 INSERTs). $posts is ID => content.
     */
    private function buildPostsDump(array $posts): string
    {
        $stmts = [];
        $stmts[] = "DROP TABLE IF EXISTS `wp_posts`;";
        $stmts[] = "CREATE TABLE `wp_posts` ("
            . "`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`post_content` longtext NOT NULL, "
            . "PRIMARY KEY (`ID`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $values = [];
        foreach ($posts as $id => $content) {
            $values[] = sprintf(
                "(%d, FROM_BASE64('%s'))",
                $id,
                base64_encode($content),
            );
        }
        if ($values) {
            $stmts[] = "INSERT INTO `wp_posts` VALUES " . implode(", ", $values) . ";";
        }
        return implode("\n", $stmts) . "\n";
    }

    private function runDbApply(): void
    {
        $client = new \ImportClient(
            'https://old-site.example.com/?reprint-api',
            $this->stateDir,
            $this->fs_root,
        );
        $client->run([
            'command' => 'db-apply',
            'abort' => false,
            'verbose' => false,
            'secret' => null,
            'tuning_config' => [],
            'target_engine' => 'sqlite',
            'target_sqlite_path' => $this->tempDir . '/database/wordpress.sqlite',
            'target_db' => 'wp_test',
        ]);
    }

    private function queryPosts(): array
    {
        $polyfills = resolve_sqlite_integration_path("/php-polyfills.php");
        $driver = resolve_sqlite_integration_path("/wp-pdo-mysql-on-sqlite.php");
        require_once $polyfills;
        require_once $driver;

        $dsn = "mysql-on-sqlite:path={$this->tempDir}/database/wordpress.sqlite;dbname=wp_test";
        $pdo = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $rows = $pdo->query("SELECT ID, post_content FROM wp_posts ORDER BY ID")->fetchAll();
        $posts = [];
        foreach ($rows as $row) {
            $posts[(int) $row['ID']] = $row['post_content'];
        }
        return $posts;
    }

    /**
     * Round-trip via the sub-command path: a completed db-apply re-run
     * re-applies the (re-downloaded) dump from the top, so edited rows,
     * new rows, and deleted rows are all reflected in the target.
     */
    public function testDbApplyRerunReappliesFreshDump(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        // First pull: posts 1 and 2.
        file_put_contents(
            $this->stateDir . '/db.sql',
            $this->buildPostsDump([
                1 => 'Hey there',
                2 => 'Doomed post',
            ]),
        );
        $this->writeState([]);
        $this->runDbApply();

        $state = $this->readState();
        $this->assertSame('complete', $state['status']);
        $this->assertSame('db-apply', $state['command']);
        $this->assertSame(
            ['Hey there', 'Doomed post'],
            array_values($this->queryPosts()),
        );

        // Remote changed: post 1 edited, post 2 deleted, post 3 added.
        // (In the real flow db-pull re-downloads db.sql; here we just
        // swap the file.)
        file_put_contents(
            $this->stateDir . '/db.sql',
            $this->buildPostsDump([
                1 => 'Hey there, dude, again',
                3 => 'Brand new post',
            ]),
        );

        // Re-run db-apply on the completed state — must refresh, not throw.
        $this->runDbApply();

        $posts = $this->queryPosts();
        $this->assertSame(
            'Hey there, dude, again',
            $posts[1],
            'Edited row must reflect the new dump after re-apply',
        );
        $this->assertArrayNotHasKey(
            2,
            $posts,
            'Row deleted at the source must disappear after re-apply',
        );
        $this->assertSame(
            'Brand new post',
            $posts[3],
            'Row added at the source must appear after re-apply',
        );

        // Apply progress must restart from zero, not accumulate.
        $state = $this->readState();
        $this->assertSame('complete', $state['status']);
        $this->assertSame(
            3,
            $state['apply']['statements_executed'],
            'Re-apply must reset statements_executed (3 statements in the new dump)',
        );
    }

    /**
     * db-apply strips `_edit_lock` postmeta captured from the remote —
     * on the imported copy it only produces phantom "X is currently
     * editing" badges — while leaving all other postmeta intact.
     */
    public function testDbApplyStripsEphemeralEditLocks(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required');
        }

        $sql = $this->buildPostsDump([1 => 'Hey there']);
        $sql .= "DROP TABLE IF EXISTS `wp_postmeta`;\n";
        $sql .= "CREATE TABLE `wp_postmeta` ("
            . "`meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, "
            . "`post_id` bigint(20) unsigned NOT NULL DEFAULT 0, "
            . "`meta_key` varchar(255) DEFAULT NULL, "
            . "`meta_value` longtext, "
            . "PRIMARY KEY (`meta_id`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
        $sql .= sprintf(
            "INSERT INTO `wp_postmeta` VALUES "
            . "(1, 1, FROM_BASE64('%s'), FROM_BASE64('%s')), "
            . "(2, 1, FROM_BASE64('%s'), FROM_BASE64('%s'));\n",
            base64_encode('_edit_lock'),
            base64_encode('1781114164:51814349'),
            base64_encode('_thumbnail_id'),
            base64_encode('42'),
        );

        file_put_contents($this->stateDir . '/db.sql', $sql);
        $this->writeState([]);
        $this->runDbApply();

        $polyfills = resolve_sqlite_integration_path("/php-polyfills.php");
        $driver = resolve_sqlite_integration_path("/wp-pdo-mysql-on-sqlite.php");
        require_once $polyfills;
        require_once $driver;
        $dsn = "mysql-on-sqlite:path={$this->tempDir}/database/wordpress.sqlite;dbname=wp_test";
        $pdo = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $rows = $pdo->query("SELECT meta_key FROM wp_postmeta ORDER BY meta_id")->fetchAll();
        $keys = array_column($rows, 'meta_key');

        $this->assertNotContains(
            '_edit_lock',
            $keys,
            'Remote editor locks must not survive the import — they render as phantom "currently editing" badges',
        );
        $this->assertContains(
            '_thumbnail_id',
            $keys,
            'Other postmeta must be left intact',
        );
    }

    /**
     * Re-running db-pull on a completed db-pull state resets the DB
     * state for a full refresh: db.sql, cached domains, and the
     * .sql-buffer crash-recovery file are deleted, sql_bytes is
     * cleared, and the run proceeds as a fresh pull.
     */
    public function testDbPullRerunResetsForFullRefresh(): void
    {
        file_put_contents($this->stateDir . '/db.sql', "-- stale dump\n");
        file_put_contents($this->stateDir . '/.import-domains.json', '["old.example.com"]');
        file_put_contents($this->stateDir . '/.sql-buffer', 'INSERT INTO `wp_posts` VAL');

        $this->writeState([
            "command" => "db-pull",
            "status" => "complete",
            "sql_bytes" => 14,
            "sql_output" => "file",
        ]);

        $client = new \ImportClient(
            'http://fake.url/?reprint-api',
            $this->stateDir,
            $this->fs_root,
        );
        $reflection = new \ReflectionClass($client);
        $stateProperty = $reflection->getProperty('state');
        $loadState = $reflection->getMethod('load_state');
        $stateProperty->setValue($client, $loadState->invoke($client));
        $reflection->getProperty('is_tty')->setValue($client, false);

        try {
            $reflection->getMethod('run_db_sync')->invoke($client);
        } catch (\Exception $e) {
            // Expected: contacting the fake URL fails after the reset.
        }

        $this->assertFileDoesNotExist(
            $this->stateDir . '/db.sql',
            'Stale db.sql must be deleted so the refresh re-downloads from scratch',
        );
        $this->assertFileDoesNotExist(
            $this->stateDir . '/.import-domains.json',
            'Cached domains belong to the previous dump and must be cleared',
        );
        $this->assertFileDoesNotExist(
            $this->stateDir . '/.sql-buffer',
            'A stale crash-recovery buffer must not be replayed into a refresh',
        );

        $state = $this->readState();
        $this->assertNotEquals(
            'complete',
            $state['status'],
            'db-pull re-run on a completed state must start a refresh, not throw or no-op',
        );
        $this->assertSame('db-pull', $state['command']);
        $this->assertNull($state['sql_bytes'], 'Stale sql_bytes must be cleared');
        $this->assertNull($state['cursor'], 'The refresh must start from a null cursor (full re-dump)');
    }

    /**
     * Legacy state repair: a --filter=skipped-earlier run that finished
     * under an older version left status "in_progress" forever. run()
     * must detect the terminal shape (stage null, skipped list gone)
     * and mark it complete so re-runs take the delta path instead of
     * tripping the mid-flight filter guard.
     */
    public function testStuckSkippedEarlierStateIsRepairedOnNextRun(): void
    {
        file_put_contents(
            $this->stateDir . '/.import-index.jsonl',
            json_encode([
                "path" => base64_encode('/wp-login.php'),
                "ctime" => 1000,
                "size" => 100,
                "type" => "file",
            ], JSON_UNESCAPED_SLASHES) . "\n",
        );

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => null,
            "filter" => "skipped-earlier",
        ]);

        $client = new \ImportClient(
            'http://fake.url/?reprint-api',
            $this->stateDir,
            $this->fs_root,
        );

        // The old behavior threw "Cannot change --filter from
        // 'skipped-earlier' to 'essential-files' while a sync is in
        // progress". With the repair, the run proceeds into a delta sync
        // and only fails when it can't reach the fake URL.
        try {
            $client->run([
                'command' => 'files-pull',
                'abort' => false,
                'verbose' => false,
                'secret' => null,
                'tuning_config' => [],
                'filter' => 'essential-files',
            ]);
        } catch (\Exception $e) {
            $this->assertStringNotContainsString(
                'Cannot change --filter',
                $e->getMessage(),
                'The stuck skipped-earlier state must be repaired before the filter guard runs',
            );
        }

        $state = $this->readState();
        $this->assertSame('files-pull', $state['command']);
        $this->assertSame('essential-files', $state['filter']);
    }
}
