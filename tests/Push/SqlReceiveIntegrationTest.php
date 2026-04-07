<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the SQL receive → commit pipeline.
 *
 * These tests simulate the full push-sql flow: produce SQL from a local
 * database, wrap it in multipart, parse on the receiver side, rewrite
 * table names, execute into staging tables, then commit via RENAME TABLE.
 *
 * Each test creates both a "source" and "target" database to simulate
 * a real push between two sites.
 */
final class SqlReceiveIntegrationTest extends TestCase
{
    private $pdo;
    private $sourceDbName;
    private $targetDbName;

    protected function setUp(): void
    {
        $host = getenv("DB_HOST") ?: "localhost";
        $user = getenv("DB_USER") ?: "root";
        $pass = getenv("DB_PASS") ?: "";
        $base = getenv("DB_NAME") ?: "test_mysql_dump";
        $this->sourceDbName = $base . "_push_src";
        $this->targetDbName = $base . "_push_tgt";

        $dsn = "mysql:host={$host};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Create source and target databases
        foreach ([$this->sourceDbName, $this->targetDbName] as $db) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
            $this->pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            foreach ([$this->sourceDbName, $this->targetDbName] as $db) {
                try {
                    $this->pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
                } catch (PDOException $e) {
                    // Ignore
                }
            }
        }
        $this->pdo = null;
    }

    private function sourcePdo(): PDO
    {
        $host = getenv("DB_HOST") ?: "localhost";
        $user = getenv("DB_USER") ?: "root";
        $pass = getenv("DB_PASS") ?: "";
        return new PDO(
            "mysql:host={$host};dbname={$this->sourceDbName};charset=utf8mb4",
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function targetPdo(): PDO
    {
        $host = getenv("DB_HOST") ?: "localhost";
        $user = getenv("DB_USER") ?: "root";
        $pass = getenv("DB_PASS") ?: "";
        return new PDO(
            "mysql:host={$host};dbname={$this->targetDbName};charset=utf8mb4",
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    // ---------------------------------------------------------------
    // Full pipeline: export → multipart → receive → commit
    // ---------------------------------------------------------------

    /**
     * Full round-trip: export source DB, push SQL to target via
     * multipart stream, rewrite table names, commit via RENAME TABLE.
     */
    public function testFullPushRoundTrip(): void
    {
        $src = $this->sourcePdo();
        $tgt = $this->targetPdo();

        // Set up source database
        $src->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            content TEXT
        ) ENGINE=InnoDB");
        $src->exec("INSERT INTO wp_posts (title, content) VALUES ('Hello', 'World'), ('Foo', 'Bar')");

        $src->exec("CREATE TABLE wp_options (
            option_id INT PRIMARY KEY AUTO_INCREMENT,
            option_name VARCHAR(200) NOT NULL,
            option_value TEXT
        ) ENGINE=InnoDB");
        $src->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'http://local.test')");

        // Export source DB using MySQLDumpProducer
        $producer = new \WordPress\DataLiberation\MySQLDumpProducer($src, [
            'create_table_query' => true,
        ]);

        // Collect all SQL fragments
        $all_sql = '';
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) {
                $all_sql .= $fragment . "\n";
            }
        }

        $this->assertNotEmpty($all_sql, "Producer must generate SQL");

        // Build multipart body (as the push client would)
        $stream = new MultipartBodyStream();
        $stream->write_sql_chunk($all_sql, json_encode(['done' => true]), true);
        $stream->write_completion_chunk('complete', []);
        $stream->finalize();

        // Parse multipart body (as the receiver would)
        $body = $stream->get_contents();
        $chunks = parse_multipart_body($body, $stream->get_boundary());
        $this->assertGreaterThanOrEqual(2, count($chunks), "Must have SQL + completion chunks");

        // Build staging table map on target
        $table_map = build_staging_table_map($tgt, 'wp_', ['wp_posts', 'wp_options']);

        // Execute rewritten SQL on target
        $tgt->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach ($chunks as $chunk) {
            if (($chunk['headers']['x-chunk-type'] ?? '') !== 'sql') {
                continue;
            }
            $sql = rewrite_table_names($chunk['body'], $table_map);

            // Verify staging prefix is present
            $this->assertStringContainsString('_push_wp_posts', $sql);
            $this->assertStringContainsString('_push_wp_options', $sql);

            // Execute the rewritten SQL
            $tgt->exec($sql);
        }
        $tgt->exec("SET FOREIGN_KEY_CHECKS=1");

        // Verify staging tables exist on target
        $tables = [];
        $stmt = $tgt->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tables[] = $row['TABLE_NAME'];
        }
        $this->assertContains('_push_wp_posts', $tables);
        $this->assertContains('_push_wp_options', $tables);

        // Verify staging tables have correct data
        $count = $tgt->query("SELECT COUNT(*) FROM _push_wp_posts")->fetchColumn();
        $this->assertEquals(2, $count, "Staging wp_posts should have 2 rows");

        $count = $tgt->query("SELECT COUNT(*) FROM _push_wp_options")->fetchColumn();
        $this->assertEquals(1, $count, "Staging wp_options should have 1 row");

        // Commit: RENAME TABLE
        $pushed_tables = ['wp_posts', 'wp_options'];
        $rename_pairs = [];
        foreach ($pushed_tables as $table) {
            $staging_name = PUSH_STAGING_TABLE_PREFIX . $table;
            $rename_pairs[] = "`{$staging_name}` TO `{$table}`";
        }
        $tgt->exec('RENAME TABLE ' . implode(', ', $rename_pairs));

        // Verify: live tables now have source data
        $rows = $tgt->query("SELECT title FROM wp_posts ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Hello', 'Foo'], $rows);

        $value = $tgt->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertSame('http://local.test', $value);
    }

    /**
     * Push to a target that already has tables — the old data must be
     * swapped out and the new data must be live.
     */
    public function testPushOverExistingData(): void
    {
        $src = $this->sourcePdo();
        $tgt = $this->targetPdo();

        // Source: new data
        $src->exec("CREATE TABLE wp_posts (id INT PRIMARY KEY, title VARCHAR(200)) ENGINE=InnoDB");
        $src->exec("INSERT INTO wp_posts VALUES (1, 'Updated Post')");

        // Target: old production data
        $tgt->exec("CREATE TABLE wp_posts (id INT PRIMARY KEY, title VARCHAR(200)) ENGINE=InnoDB");
        $tgt->exec("INSERT INTO wp_posts VALUES (1, 'Original Post'), (2, 'Another Post')");

        // Export source
        $producer = new \WordPress\DataLiberation\MySQLDumpProducer($src, ['create_table_query' => true]);
        $all_sql = '';
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) $all_sql .= $fragment . "\n";
        }

        // Rewrite to staging prefix
        $table_map = build_staging_table_map($tgt, 'wp_', ['wp_posts']);
        $sql = rewrite_table_names($all_sql, $table_map);

        // Execute staging SQL on target
        $tgt->exec("SET FOREIGN_KEY_CHECKS=0");
        $tgt->exec($sql);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=1");

        // Commit: swap staging → live, live → _old_
        $tgt->exec("RENAME TABLE `wp_posts` TO `_old_wp_posts`, `_push_wp_posts` TO `wp_posts`");

        // Verify: live table has source data (only 1 row, not 2)
        $rows = $tgt->query("SELECT title FROM wp_posts")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Updated Post'], $rows);

        // Verify: old data is in _old_ table
        $rows = $tgt->query("SELECT title FROM _old_wp_posts ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Original Post', 'Another Post'], $rows);
    }

    /**
     * Special characters in data must survive the full pipeline.
     * This catches encoding bugs that could corrupt production content.
     */
    public function testSpecialCharactersSurviveRoundTrip(): void
    {
        $src = $this->sourcePdo();
        $tgt = $this->targetPdo();

        $src->exec("CREATE TABLE wp_posts (id INT PRIMARY KEY, content TEXT) ENGINE=InnoDB");

        // Insert content with unicode, quotes, backslashes, newlines, NUL-adjacent chars
        $tricky_content = "Hello 'World' \"quoted\" \\backslash\\ \n新しい記事\n🎉 emoji \t tab";
        $stmt = $src->prepare("INSERT INTO wp_posts VALUES (1, ?)");
        $stmt->execute([$tricky_content]);

        // Export
        $producer = new \WordPress\DataLiberation\MySQLDumpProducer($src, ['create_table_query' => true]);
        $all_sql = '';
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) $all_sql .= $fragment . "\n";
        }

        // Rewrite and execute on target
        $table_map = build_staging_table_map($tgt, 'wp_', ['wp_posts']);
        $sql = rewrite_table_names($all_sql, $table_map);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=0");
        $tgt->exec($sql);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=1");

        // Commit
        $tgt->exec("RENAME TABLE `_push_wp_posts` TO `wp_posts`");

        // Verify data integrity
        $result = $tgt->query("SELECT content FROM wp_posts WHERE id=1")->fetchColumn();
        $this->assertSame(
            $tricky_content,
            $result,
            "Special characters must survive export → multipart → rewrite → import"
        );
    }

    /**
     * CRITICAL: Empty source database pushed to a target with data.
     * This effectively wipes the target — make sure it's intentional.
     */
    public function testPushingEmptyDatabaseWipesTarget(): void
    {
        $tgt = $this->targetPdo();

        // Target has production data
        $tgt->exec("CREATE TABLE wp_posts (id INT PRIMARY KEY, title VARCHAR(200)) ENGINE=InnoDB");
        $tgt->exec("INSERT INTO wp_posts VALUES (1, 'Important Post')");
        $tgt->exec("CREATE TABLE wp_options (option_id INT PRIMARY KEY) ENGINE=InnoDB");
        $tgt->exec("INSERT INTO wp_options VALUES (1)");

        // Source has no tables → push includes no tables
        $pushed_tables = [];

        // The commit logic handles tables NOT in pushed_tables:
        // they get renamed to _old_ (silently deleted)
        $existing_tables = [];
        $stmt = $tgt->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_tables[$row['TABLE_NAME']] = true;
        }

        $rename_pairs = [];
        $old_tables = [];
        foreach ($existing_tables as $name => $_) {
            if (str_starts_with($name, 'wp_')) {
                $old_name = '_old_' . $name;
                $rename_pairs[] = "`{$name}` TO `{$old_name}`";
                $old_tables[] = $old_name;
            }
        }

        if (!empty($rename_pairs)) {
            $tgt->exec('RENAME TABLE ' . implode(', ', $rename_pairs));
        }

        // All live tables are gone
        $live_tables = [];
        $stmt = $tgt->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['TABLE_NAME'];
            if (!str_starts_with($name, '_old_')) {
                $live_tables[] = $name;
            }
        }

        $this->assertEmpty(
            $live_tables,
            "BUG DOCUMENTED: pushing an empty database silently wipes ALL production tables"
        );
    }
}
