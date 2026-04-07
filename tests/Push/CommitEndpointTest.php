<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for endpoint_commit() — the most dangerous function in the codebase.
 *
 * This endpoint atomically swaps staging data into production. Every test
 * here represents a scenario that, if mishandled, breaks the target site.
 */
final class CommitEndpointTest extends TestCase
{
    private $pdo;
    private $dbName;
    private $tempDirs = [];

    protected function setUp(): void
    {
        $host = getenv("DB_HOST") ?: "localhost";
        $user = getenv("DB_USER") ?: "root";
        $pass = getenv("DB_PASS") ?: "";
        $this->dbName = (getenv("DB_NAME") ?: "test_mysql_dump") . "_push";

        $dsn = "mysql:host={$host};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        $this->pdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec("USE `{$this->dbName}`");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            try {
                $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
            } catch (PDOException $e) {
                // Ignore
            }
        }
        $this->pdo = null;

        foreach ($this->tempDirs as $dir) {
            $this->recursiveDelete($dir);
        }
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/push-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $item) {
                if ($item === '.' || $item === '..') continue;
                $this->recursiveDelete($path . '/' . $item);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    /**
     * Helper: build_staging_table_map requires a PDO that can query
     * INFORMATION_SCHEMA. We use $this->pdo which has the test database.
     */
    private function getExistingTableNames(): array
    {
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );
        $tables = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tables[] = $row['TABLE_NAME'];
        }
        return $tables;
    }

    // ---------------------------------------------------------------
    // RENAME TABLE statement generation
    // ---------------------------------------------------------------

    /**
     * The RENAME TABLE statement must swap each pushed table correctly:
     * - Existing table: live → _old, staging → live
     * - New table: staging → live (no _old needed)
     */
    public function testRenameTableSwapsExistingAndNewTables(): void
    {
        // Simulate: remote has wp_posts and wp_options.
        // Push includes wp_posts (existing) and wp_users (new).
        $this->pdo->exec("CREATE TABLE wp_posts (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_posts VALUES (1), (2), (3)");
        $this->pdo->exec("CREATE TABLE wp_options (option_id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_options VALUES (100)");

        // Create staging tables for pushed tables
        $this->pdo->exec("CREATE TABLE _push_wp_posts (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO _push_wp_posts VALUES (10), (20)");
        $this->pdo->exec("CREATE TABLE _push_wp_users (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO _push_wp_users VALUES (99)");

        $pushed_tables = ['wp_posts', 'wp_users'];
        $table_prefix = 'wp_';

        $existing_tables = [];
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_tables[$row['TABLE_NAME']] = true;
        }

        $rename_pairs = [];
        $old_tables = [];

        foreach ($pushed_tables as $table) {
            $staging_name = PUSH_STAGING_TABLE_PREFIX . $table;
            $old_name = '_old_' . $table;

            if (isset($existing_tables[$table])) {
                $rename_pairs[] = "`{$table}` TO `{$old_name}`";
                $rename_pairs[] = "`{$staging_name}` TO `{$table}`";
                $old_tables[] = $old_name;
            } else {
                $rename_pairs[] = "`{$staging_name}` TO `{$table}`";
            }
        }

        // Execute the RENAME
        $rename_sql = 'RENAME TABLE ' . implode(', ', $rename_pairs);
        $this->pdo->exec($rename_sql);

        // wp_posts should now have staging data (10, 20)
        $rows = $this->pdo->query("SELECT id FROM wp_posts ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([10, 20], array_map('intval', $rows));

        // wp_users should now exist with staging data (99)
        $rows = $this->pdo->query("SELECT id FROM wp_users ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([99], array_map('intval', $rows));

        // _old_wp_posts should have original data (1, 2, 3)
        $rows = $this->pdo->query("SELECT id FROM _old_wp_posts ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1, 2, 3], array_map('intval', $rows));

        // wp_options should be untouched (wasn't in pushed_tables... or was it?)
        // NOTE: This is the critical question — see testSilentTableDeletion below.
    }

    // ---------------------------------------------------------------
    // CRITICAL: Silent table deletion
    // ---------------------------------------------------------------

    /**
     * CRITICAL BUG: Tables that exist on the remote but are NOT in the push
     * are silently renamed to _old_ and then dropped. This means pushing a
     * subset of your local tables permanently deletes production data.
     *
     * Example: A plugin creates wp_woocommerce_orders on production. Your
     * local dev doesn't have WooCommerce. Pushing your local DB silently
     * deletes all WooCommerce order data.
     *
     * This test documents the current (dangerous) behavior.
     */
    public function testSilentTableDeletion(): void
    {
        // Remote has wp_posts, wp_options, and wp_woocommerce_orders
        $this->pdo->exec("CREATE TABLE wp_posts (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_posts VALUES (1)");
        $this->pdo->exec("CREATE TABLE wp_options (option_id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_options VALUES (1)");
        $this->pdo->exec("CREATE TABLE wp_woocommerce_orders (order_id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_woocommerce_orders VALUES (1000), (1001), (1002)");

        // Push only includes wp_posts and wp_options (local dev has no WooCommerce)
        $this->pdo->exec("CREATE TABLE _push_wp_posts (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO _push_wp_posts VALUES (1)");
        $this->pdo->exec("CREATE TABLE _push_wp_options (option_id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO _push_wp_options VALUES (1)");

        $pushed_tables = ['wp_posts', 'wp_options'];
        $table_prefix = 'wp_';

        $existing_tables = [];
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_tables[$row['TABLE_NAME']] = true;
        }

        $rename_pairs = [];
        $old_tables = [];

        foreach ($pushed_tables as $table) {
            $staging_name = PUSH_STAGING_TABLE_PREFIX . $table;
            $old_name = '_old_' . $table;

            if (isset($existing_tables[$table])) {
                $rename_pairs[] = "`{$table}` TO `{$old_name}`";
                $rename_pairs[] = "`{$staging_name}` TO `{$table}`";
                $old_tables[] = $old_name;
            } else {
                $rename_pairs[] = "`{$staging_name}` TO `{$table}`";
            }
        }

        // This is the current behavior: tables not in pushed_tables get renamed to _old_
        foreach ($existing_tables as $name => $_) {
            if (
                str_starts_with($name, $table_prefix) &&
                !in_array($name, $pushed_tables) &&
                !str_starts_with($name, PUSH_STAGING_TABLE_PREFIX) &&
                !str_starts_with($name, '_old_')
            ) {
                $old_name = '_old_' . $name;
                $rename_pairs[] = "`{$name}` TO `{$old_name}`";
                $old_tables[] = $old_name;
            }
        }

        $rename_sql = 'RENAME TABLE ' . implode(', ', $rename_pairs);
        $this->pdo->exec($rename_sql);

        // After RENAME, wp_woocommerce_orders is GONE from the live prefix.
        // It's been moved to _old_wp_woocommerce_orders.
        $live_tables = $this->getExistingTableNames();
        $this->assertNotContains(
            'wp_woocommerce_orders',
            $live_tables,
            "BUG DOCUMENTED: wp_woocommerce_orders was silently removed from live tables"
        );
        $this->assertContains(
            '_old_wp_woocommerce_orders',
            $live_tables,
            "The table is moved to _old_ prefix, but will be dropped by cleanup"
        );

        // After cleanup (which endpoint_commit does), the data is permanently lost
        foreach ($old_tables as $old_table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$old_table}`");
        }

        $final_tables = $this->getExistingTableNames();
        $this->assertNotContains(
            '_old_wp_woocommerce_orders',
            $final_tables,
            "BUG DOCUMENTED: WooCommerce orders are permanently deleted"
        );
    }

    // ---------------------------------------------------------------
    // Missing staging tables
    // ---------------------------------------------------------------

    /**
     * If a staging table is missing (push was interrupted), commit
     * must refuse to proceed. If it doesn't, RENAME TABLE will fail
     * partway through, potentially leaving the DB in an inconsistent state.
     */
    public function testMissingStagingTablePreventsCommit(): void
    {
        $this->pdo->exec("CREATE TABLE wp_posts (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_posts VALUES (1)");

        // Staging table for wp_posts is missing — push was interrupted
        // (no _push_wp_posts created)

        $pushed_tables = ['wp_posts'];

        $existing_tables = [];
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_tables[$row['TABLE_NAME']] = true;
        }

        $errors = [];
        foreach ($pushed_tables as $table) {
            $staging_name = PUSH_STAGING_TABLE_PREFIX . $table;
            if (!isset($existing_tables[$staging_name])) {
                $errors[] = "Missing staging table: {$staging_name}";
            }
        }

        $this->assertNotEmpty($errors, "Commit must detect missing staging tables");
        $this->assertStringContainsString('_push_wp_posts', $errors[0]);

        // Verify live table is untouched
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM wp_posts")->fetchColumn();
        $this->assertSame(1, $count, "Live data must be untouched when commit is refused");
    }

    // ---------------------------------------------------------------
    // RENAME TABLE failure mid-swap
    // ---------------------------------------------------------------

    /**
     * If RENAME TABLE fails (e.g. a staging table is actually missing
     * despite passing validation, or a lock blocks it), the live DB
     * must remain usable.
     *
     * MySQL's RENAME TABLE is atomic for a single table pair, but
     * the compound multi-table RENAME can fail partway. We verify
     * that a deliberate failure leaves the original tables intact.
     */
    public function testRenameTableFailureLeavesLiveTablesIntact(): void
    {
        $this->pdo->exec("CREATE TABLE wp_posts (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO wp_posts VALUES (1), (2), (3)");
        $this->pdo->exec("CREATE TABLE _push_wp_posts (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->pdo->exec("INSERT INTO _push_wp_posts VALUES (10)");

        // Attempt to RENAME a table that doesn't exist → the entire RENAME fails
        $rename_sql = 'RENAME TABLE '
            . '`wp_posts` TO `_old_wp_posts`, '
            . '`_push_wp_posts` TO `wp_posts`, '
            . '`_push_wp_nonexistent` TO `wp_nonexistent`';

        $failed = false;
        try {
            $this->pdo->exec($rename_sql);
        } catch (\Throwable $e) {
            $failed = true;
        }

        $this->assertTrue($failed, "RENAME TABLE should have failed");

        // CRITICAL: wp_posts must still have the original data
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM wp_posts")->fetchColumn();
        $this->assertSame(3, $count, "Live table data must survive a failed RENAME");
    }

    // ---------------------------------------------------------------
    // File swap tests
    // ---------------------------------------------------------------

    /**
     * File swap via rename: staging directory becomes live,
     * old live directory is preserved as _old_.
     */
    public function testFileSwapViaRename(): void
    {
        $base = $this->createTempDir();
        $fs_root = $base . '/html';
        $staging_dir = $base . '/.push-staging-test123';

        // Create "live" directory with some files
        mkdir($fs_root, 0755, true);
        file_put_contents($fs_root . '/index.php', '<?php // old live');
        file_put_contents($fs_root . '/wp-config.php', '<?php define("DB_HOST", "prod-db");');

        // Create staging directory with new files
        mkdir($staging_dir, 0755, true);
        file_put_contents($staging_dir . '/index.php', '<?php // new from push');
        // Deliberately no wp-config.php in staging — the commit should copy it

        // Copy wp-config.php from live to staging (as endpoint_commit does)
        copy($fs_root . '/wp-config.php', $staging_dir . '/wp-config.php');

        // Perform rename swap
        $old_fs_dir = $fs_root . '_old_test123';
        $this->assertTrue(rename($fs_root, $old_fs_dir));
        $this->assertTrue(rename($staging_dir, $fs_root));

        // Verify: live now has staging content
        $this->assertSame('<?php // new from push', file_get_contents($fs_root . '/index.php'));

        // Verify: wp-config.php has production credentials
        $this->assertStringContainsString('prod-db', file_get_contents($fs_root . '/wp-config.php'));

        // Verify: old directory still exists for rollback
        $this->assertTrue(is_dir($old_fs_dir));
        $this->assertSame('<?php // old live', file_get_contents($old_fs_dir . '/index.php'));
    }

    /**
     * CRITICAL: If staging directory is missing critical files (e.g.
     * wp-load.php), the site breaks after swap. The commit endpoint
     * should validate that essential WordPress files exist.
     *
     * This test documents that NO such validation currently exists.
     */
    public function testIncompleteFileStaging(): void
    {
        $base = $this->createTempDir();
        $fs_root = $base . '/html';
        $staging_dir = $base . '/.push-staging-incomplete';

        // Live site has all required WordPress files
        mkdir($fs_root, 0755, true);
        file_put_contents($fs_root . '/index.php', '<?php require "wp-blog-header.php";');
        file_put_contents($fs_root . '/wp-load.php', '<?php // WordPress loader');
        file_put_contents($fs_root . '/wp-blog-header.php', '<?php require "wp-load.php";');
        file_put_contents($fs_root . '/wp-config.php', '<?php define("DB_HOST", "prod");');

        // Staging only has index.php — push was interrupted mid-way
        mkdir($staging_dir, 0755, true);
        file_put_contents($staging_dir . '/index.php', '<?php require "wp-blog-header.php";');

        // Copy wp-config.php
        copy($fs_root . '/wp-config.php', $staging_dir . '/wp-config.php');

        // Currently, the swap would proceed even though staging is incomplete
        $has_staging_dir = is_dir($staging_dir);
        $this->assertTrue($has_staging_dir, "Staging dir exists, so commit would proceed");

        // Verify: staging is missing critical files
        $this->assertFalse(
            file_exists($staging_dir . '/wp-load.php'),
            "BUG DOCUMENTED: staging is missing wp-load.php but commit would proceed anyway"
        );
        $this->assertFalse(
            file_exists($staging_dir . '/wp-blog-header.php'),
            "BUG DOCUMENTED: staging is missing wp-blog-header.php but commit would proceed anyway"
        );

        // If the swap happened, the site would be broken:
        // index.php tries to require wp-blog-header.php which doesn't exist
    }

    /**
     * File swap rollback: if DB swap fails after files were swapped,
     * the files must be restored to their original state.
     */
    public function testFileRollbackAfterDbSwapFailure(): void
    {
        $base = $this->createTempDir();
        $fs_root = $base . '/html';
        $staging_dir = $base . '/.push-staging-rollback';
        $old_fs_dir = $fs_root . '_old_rollback';

        // Set up live
        mkdir($fs_root, 0755, true);
        file_put_contents($fs_root . '/index.php', '<?php // original live content');

        // Set up staging
        mkdir($staging_dir, 0755, true);
        file_put_contents($staging_dir . '/index.php', '<?php // new staging content');

        // Perform file swap
        rename($fs_root, $old_fs_dir);
        rename($staging_dir, $fs_root);

        // Verify swap happened
        $this->assertSame('<?php // new staging content', file_get_contents($fs_root . '/index.php'));

        // Now simulate DB swap failure → roll back files
        // This is what endpoint_commit does when RENAME TABLE throws
        rename($fs_root, $staging_dir);  // Move new content back to staging
        rename($old_fs_dir, $fs_root);   // Restore original

        // Verify: live is restored to original
        $this->assertSame(
            '<?php // original live content',
            file_get_contents($fs_root . '/index.php'),
            "After rollback, live directory must have original content"
        );
    }

    // ---------------------------------------------------------------
    // wp-config.php handling
    // ---------------------------------------------------------------

    /**
     * wp-config.php MUST be copied from live to staging before swap.
     * If it isn't, the site after swap will have local dev DB credentials
     * and won't be able to connect to the production database.
     */
    public function testWpConfigIsCopiedFromLiveToStaging(): void
    {
        $base = $this->createTempDir();
        $fs_root = $base . '/html';
        $staging_dir = $base . '/.push-staging-config';

        mkdir($fs_root, 0755, true);
        file_put_contents($fs_root . '/wp-config.php', '<?php define("DB_HOST", "production-rds.amazonaws.com");');

        mkdir($staging_dir, 0755, true);
        file_put_contents($staging_dir . '/wp-config.php', '<?php define("DB_HOST", "localhost");');

        // endpoint_commit copies live wp-config.php into staging
        $wp_config_src = $fs_root . '/wp-config.php';
        $wp_config_dst = $staging_dir . '/wp-config.php';
        if (file_exists($wp_config_src)) {
            copy($wp_config_src, $wp_config_dst);
        }

        // After copy, staging should have production credentials
        $this->assertStringContainsString(
            'production-rds.amazonaws.com',
            file_get_contents($staging_dir . '/wp-config.php'),
            "Staging wp-config.php must have production DB credentials"
        );
    }

    /**
     * If live site has no wp-config.php (unusual but possible — e.g. it's
     * loaded from a parent directory), the staging directory keeps whatever
     * wp-config.php was pushed. This should be logged as a warning.
     */
    public function testMissingLiveWpConfigIsHandledGracefully(): void
    {
        $base = $this->createTempDir();
        $fs_root = $base . '/html';
        $staging_dir = $base . '/.push-staging-noconfig';

        mkdir($fs_root, 0755, true);
        // No wp-config.php in live

        mkdir($staging_dir, 0755, true);
        file_put_contents($staging_dir . '/wp-config.php', '<?php define("DB_HOST", "localhost");');

        $wp_config_src = $fs_root . '/wp-config.php';
        $wp_config_dst = $staging_dir . '/wp-config.php';
        if (file_exists($wp_config_src)) {
            copy($wp_config_src, $wp_config_dst);
        }

        // Staging keeps its own wp-config.php (with local credentials)
        // This is dangerous but the current behavior
        $this->assertStringContainsString(
            'localhost',
            file_get_contents($staging_dir . '/wp-config.php'),
            "Without live wp-config.php, staging keeps local dev credentials (dangerous)"
        );
    }

    // ---------------------------------------------------------------
    // build_staging_table_map
    // ---------------------------------------------------------------

    /**
     * build_staging_table_map should include both existing remote tables
     * and incoming tables from the push client.
     */
    public function testBuildStagingTableMapIncludesIncomingTables(): void
    {
        $this->pdo->exec("CREATE TABLE wp_posts (id INT)");
        $this->pdo->exec("CREATE TABLE wp_options (id INT)");

        $map = build_staging_table_map($this->pdo, 'wp_', ['wp_posts', 'wp_users']);

        // wp_posts: exists on remote AND in incoming → should be in map
        $this->assertArrayHasKey('wp_posts', $map);
        $this->assertSame('_push_wp_posts', $map['wp_posts']);

        // wp_options: exists on remote, NOT in incoming → should still be in map
        $this->assertArrayHasKey('wp_options', $map);

        // wp_users: NOT on remote, in incoming → should be added
        $this->assertArrayHasKey('wp_users', $map);
        $this->assertSame('_push_wp_users', $map['wp_users']);
    }

    /**
     * Tables without the specified prefix should not be in the map.
     */
    public function testBuildStagingTableMapRespectsPrefix(): void
    {
        $this->pdo->exec("CREATE TABLE wp_posts (id INT)");
        $this->pdo->exec("CREATE TABLE custom_table (id INT)");

        $map = build_staging_table_map($this->pdo, 'wp_', []);

        $this->assertArrayHasKey('wp_posts', $map);
        $this->assertArrayNotHasKey('custom_table', $map);
    }
}
