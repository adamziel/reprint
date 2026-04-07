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

    // ---------------------------------------------------------------
    // Conflicting IDs: production has data the source doesn't know about
    // ---------------------------------------------------------------

    /**
     * CRITICAL: Production site has auto-increment IDs that the source doesn't
     * know about. After push + commit, those production rows are destroyed.
     *
     * Scenario: You fork a production DB for local dev. Production keeps getting
     * new posts (IDs 4, 5, 6). You push your local DB (which only has IDs 1-3).
     * After commit, posts 4-6 are gone — DROP TABLE + CREATE TABLE wipes them.
     *
     * This is by design (full replacement), but users who think "push" means
     * "merge" will lose production data.
     */
    public function testPushDestroysProductionRowsNotInSource(): void
    {
        $src = $this->sourcePdo();
        $tgt = $this->targetPdo();

        // Source: local dev copy, frozen at 3 posts
        $src->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200)
        ) ENGINE=InnoDB");
        $src->exec("INSERT INTO wp_posts (id, title) VALUES (1, 'Post One'), (2, 'Post Two'), (3, 'Post Three')");

        // Target: production, has diverged with new content
        $tgt->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200)
        ) ENGINE=InnoDB");
        $tgt->exec("INSERT INTO wp_posts (id, title) VALUES
            (1, 'Post One'),
            (2, 'Post Two'),
            (3, 'Post Three'),
            (4, 'New Production Post'),
            (5, 'Customer Submission'),
            (6, 'Breaking News')
        ");

        // Export source
        $producer = new \WordPress\DataLiberation\MySQLDumpProducer($src, ['create_table_query' => true]);
        $all_sql = '';
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) $all_sql .= $fragment . "\n";
        }

        // The source's CREATE TABLE has AUTO_INCREMENT=4 (next value after 3)
        $this->assertStringContainsString('AUTO_INCREMENT', $all_sql,
            "SHOW CREATE TABLE should include AUTO_INCREMENT counter");

        // Rewrite and execute on target staging
        $table_map = build_staging_table_map($tgt, 'wp_', ['wp_posts']);
        $sql = rewrite_table_names($all_sql, $table_map);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=0");
        $tgt->exec($sql);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=1");

        // Commit: swap staging → live
        $tgt->exec("RENAME TABLE `wp_posts` TO `_old_wp_posts`, `_push_wp_posts` TO `wp_posts`");

        // After commit: only 3 rows remain. Posts 4-6 are gone.
        $count = (int) $tgt->query("SELECT COUNT(*) FROM wp_posts")->fetchColumn();
        $this->assertSame(3, $count,
            "Push replaces the entire table — production-only rows are destroyed"
        );

        // The production-only posts are in _old_ but will be dropped by cleanup
        $old_count = (int) $tgt->query("SELECT COUNT(*) FROM _old_wp_posts")->fetchColumn();
        $this->assertSame(6, $old_count,
            "Original production data survives in _old_ table until cleanup drops it"
        );
    }

    /**
     * CRITICAL: Source and production have DIFFERENT rows with the SAME auto-increment IDs.
     *
     * Scenario: Two copies of the same site diverge. Local dev inserts post ID=4
     * titled "Dev Draft". Production inserts post ID=4 titled "CEO Announcement".
     * After push, post 4 silently becomes "Dev Draft" — the CEO Announcement is
     * gone with no conflict detection or warning.
     */
    public function testConflictingAutoIncrementIdsAreOverwrittenSilently(): void
    {
        $src = $this->sourcePdo();
        $tgt = $this->targetPdo();

        // Shared baseline: both started from the same 3 posts
        $schema = "CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200),
            author VARCHAR(100)
        ) ENGINE=InnoDB";

        // Source: dev added post 4
        $src->exec($schema);
        $src->exec("INSERT INTO wp_posts (id, title, author) VALUES
            (1, 'Welcome', 'admin'),
            (2, 'About', 'admin'),
            (3, 'Contact', 'admin'),
            (4, 'Dev Draft', 'developer')
        ");

        // Target: production added post 4 (different content, same ID!)
        $tgt->exec($schema);
        $tgt->exec("INSERT INTO wp_posts (id, title, author) VALUES
            (1, 'Welcome', 'admin'),
            (2, 'About', 'admin'),
            (3, 'Contact', 'admin'),
            (4, 'CEO Announcement', 'ceo')
        ");

        // Export and push
        $producer = new \WordPress\DataLiberation\MySQLDumpProducer($src, ['create_table_query' => true]);
        $all_sql = '';
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) $all_sql .= $fragment . "\n";
        }

        $table_map = build_staging_table_map($tgt, 'wp_', ['wp_posts']);
        $sql = rewrite_table_names($all_sql, $table_map);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=0");
        $tgt->exec($sql);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=1");

        // Commit
        $tgt->exec("RENAME TABLE `wp_posts` TO `_old_wp_posts`, `_push_wp_posts` TO `wp_posts`");

        // Post 4 is now "Dev Draft" — the CEO Announcement is silently gone
        $title = $tgt->query("SELECT title FROM wp_posts WHERE id=4")->fetchColumn();
        $this->assertSame('Dev Draft', $title,
            "Conflicting ID=4 is silently overwritten — production content is lost"
        );

        // No conflict detection, no warning, no merge — just wholesale replacement
        $author = $tgt->query("SELECT author FROM wp_posts WHERE id=4")->fetchColumn();
        $this->assertSame('developer', $author,
            "The CEO's post is replaced by the dev's post with zero warning"
        );
    }

    /**
     * AUTO_INCREMENT counter after push: if source has a lower counter than
     * production, new rows inserted after push will reuse IDs that existed
     * in the old production data. This can cause FK confusion if any system
     * cached the old IDs.
     */
    public function testAutoIncrementCounterResetAfterPush(): void
    {
        $src = $this->sourcePdo();
        $tgt = $this->targetPdo();

        // Source: 2 posts, AUTO_INCREMENT will be 3
        $src->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200)
        ) ENGINE=InnoDB");
        $src->exec("INSERT INTO wp_posts (id, title) VALUES (1, 'Post A'), (2, 'Post B')");

        // Target: 10 posts, AUTO_INCREMENT is 11
        $tgt->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200)
        ) ENGINE=InnoDB");
        for ($i = 1; $i <= 10; $i++) {
            $tgt->exec("INSERT INTO wp_posts (title) VALUES ('Prod Post {$i}')");
        }

        // Verify target's AUTO_INCREMENT is high
        $pre_push_ai = (int) $tgt->query(
            "SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_posts'"
        )->fetchColumn();
        $this->assertGreaterThan(10, $pre_push_ai);

        // Export and push
        $producer = new \WordPress\DataLiberation\MySQLDumpProducer($src, ['create_table_query' => true]);
        $all_sql = '';
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) $all_sql .= $fragment . "\n";
        }

        $table_map = build_staging_table_map($tgt, 'wp_', ['wp_posts']);
        $sql = rewrite_table_names($all_sql, $table_map);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=0");
        $tgt->exec($sql);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=1");

        // Commit
        $tgt->exec("RENAME TABLE `wp_posts` TO `_old_wp_posts`, `_push_wp_posts` TO `wp_posts`");

        // AUTO_INCREMENT is now 3 (from source), not 11 (from production)
        $post_push_ai = (int) $tgt->query(
            "SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_posts'"
        )->fetchColumn();

        $this->assertLessThan($pre_push_ai, $post_push_ai,
            "AUTO_INCREMENT counter is reset to source value, much lower than production"
        );

        // Insert a new post after push — it gets ID=3, which existed in old production
        $tgt->exec("INSERT INTO wp_posts (title) VALUES ('New Post After Push')");
        $new_id = (int) $tgt->query("SELECT LAST_INSERT_ID()")->fetchColumn();

        $this->assertSame(3, $new_id,
            "New post gets ID=3, which was an existing production post ID — " .
            "any system caching old ID=3 now points to wrong content"
        );
    }

    /**
     * Foreign key integrity after push with conflicting IDs.
     *
     * If wp_postmeta references wp_posts.ID and the push replaces wp_posts
     * with different rows, the FK references may become stale. Since the push
     * replaces ALL tables atomically, FKs within the pushed set are consistent.
     * But if a table outside the pushed set references a pushed table, it breaks.
     */
    public function testForeignKeyIntegrityAcrossPushedTables(): void
    {
        $src = $this->sourcePdo();
        $tgt = $this->targetPdo();

        // Source: posts + postmeta, internally consistent
        $src->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200)
        ) ENGINE=InnoDB");
        $src->exec("INSERT INTO wp_posts VALUES (1, 'Source Post')");

        $src->exec("CREATE TABLE wp_postmeta (
            meta_id INT PRIMARY KEY AUTO_INCREMENT,
            post_id INT NOT NULL,
            meta_key VARCHAR(200),
            meta_value TEXT
        ) ENGINE=InnoDB");
        $src->exec("INSERT INTO wp_postmeta VALUES (1, 1, '_edit_last', 'admin')");

        // Target: different data, both tables exist
        $tgt->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200)
        ) ENGINE=InnoDB");
        $tgt->exec("INSERT INTO wp_posts VALUES (1, 'Prod Post'), (2, 'Another Prod Post')");

        $tgt->exec("CREATE TABLE wp_postmeta (
            meta_id INT PRIMARY KEY AUTO_INCREMENT,
            post_id INT NOT NULL,
            meta_key VARCHAR(200),
            meta_value TEXT
        ) ENGINE=InnoDB");
        $tgt->exec("INSERT INTO wp_postmeta VALUES (1, 1, '_thumbnail_id', '42'), (2, 2, '_edit_last', 'editor')");

        // Export both tables from source
        $producer = new \WordPress\DataLiberation\MySQLDumpProducer($src, ['create_table_query' => true]);
        $all_sql = '';
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) $all_sql .= $fragment . "\n";
        }

        // Rewrite and execute both staging tables
        $table_map = build_staging_table_map($tgt, 'wp_', ['wp_posts', 'wp_postmeta']);
        $sql = rewrite_table_names($all_sql, $table_map);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=0");
        $tgt->exec($sql);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=1");

        // Commit: swap BOTH tables atomically
        $tgt->exec("RENAME TABLE
            `wp_posts` TO `_old_wp_posts`,
            `_push_wp_posts` TO `wp_posts`,
            `wp_postmeta` TO `_old_wp_postmeta`,
            `_push_wp_postmeta` TO `wp_postmeta`
        ");

        // Verify: postmeta references valid posts (internal FK consistency)
        $post_ids = $tgt->query("SELECT id FROM wp_posts")->fetchAll(PDO::FETCH_COLUMN);
        $meta_post_ids = $tgt->query("SELECT DISTINCT post_id FROM wp_postmeta")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($meta_post_ids as $meta_post_id) {
            $this->assertContains($meta_post_id, $post_ids,
                "All post_id references in wp_postmeta must point to existing wp_posts rows"
            );
        }

        // Both tables have source data, not production data
        $title = $tgt->query("SELECT title FROM wp_posts WHERE id=1")->fetchColumn();
        $this->assertSame('Source Post', $title);

        $meta = $tgt->query("SELECT meta_value FROM wp_postmeta WHERE post_id=1")->fetchColumn();
        $this->assertSame('admin', $meta);
    }

    /**
     * CRITICAL: Pushing only SOME tables when they have FK relationships.
     *
     * If you push wp_posts but NOT wp_postmeta, the committed wp_posts
     * has different IDs than what wp_postmeta references. wp_postmeta
     * still has the old production post_ids, but wp_posts now has source
     * IDs. The site shows wrong metadata for posts.
     */
    public function testPartialTablePushBreaksForeignKeyIntegrity(): void
    {
        $src = $this->sourcePdo();
        $tgt = $this->targetPdo();

        // Source: only has 1 post
        $src->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200)
        ) ENGINE=InnoDB");
        $src->exec("INSERT INTO wp_posts VALUES (1, 'Only Source Post')");

        // Target: has 3 posts with metadata
        $tgt->exec("CREATE TABLE wp_posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(200)
        ) ENGINE=InnoDB");
        $tgt->exec("INSERT INTO wp_posts VALUES (1, 'Prod 1'), (2, 'Prod 2'), (3, 'Prod 3')");

        $tgt->exec("CREATE TABLE wp_postmeta (
            meta_id INT PRIMARY KEY AUTO_INCREMENT,
            post_id INT NOT NULL,
            meta_key VARCHAR(200),
            meta_value TEXT
        ) ENGINE=InnoDB");
        $tgt->exec("INSERT INTO wp_postmeta VALUES
            (1, 1, 'thumbnail', 'img1.jpg'),
            (2, 2, 'thumbnail', 'img2.jpg'),
            (3, 3, 'thumbnail', 'img3.jpg')
        ");

        // Push ONLY wp_posts (not wp_postmeta)
        $producer = new \WordPress\DataLiberation\MySQLDumpProducer($src, ['create_table_query' => true]);
        $all_sql = '';
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) $all_sql .= $fragment . "\n";
        }

        $table_map = build_staging_table_map($tgt, 'wp_', ['wp_posts']);
        $sql = rewrite_table_names($all_sql, $table_map);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=0");
        $tgt->exec($sql);
        $tgt->exec("SET FOREIGN_KEY_CHECKS=1");

        // Commit: swap only wp_posts, leave wp_postmeta untouched
        $pushed_tables = ['wp_posts'];
        $existing_tables = [];
        $stmt = $tgt->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_tables[$row['TABLE_NAME']] = true;
        }

        $rename_pairs = [];
        foreach ($pushed_tables as $table) {
            $staging_name = PUSH_STAGING_TABLE_PREFIX . $table;
            $old_name = '_old_' . $table;
            if (isset($existing_tables[$table])) {
                $rename_pairs[] = "`{$table}` TO `{$old_name}`";
                $rename_pairs[] = "`{$staging_name}` TO `{$table}`";
            } else {
                $rename_pairs[] = "`{$staging_name}` TO `{$table}`";
            }
        }

        // Also handle "dropped" tables (tables not in push) — current behavior
        // moves wp_postmeta to _old_ since it's not in pushed_tables!
        $table_prefix = 'wp_';
        foreach ($existing_tables as $name => $_) {
            if (
                str_starts_with($name, $table_prefix) &&
                !in_array($name, $pushed_tables) &&
                !str_starts_with($name, PUSH_STAGING_TABLE_PREFIX) &&
                !str_starts_with($name, '_old_')
            ) {
                $old_name = '_old_' . $name;
                $rename_pairs[] = "`{$name}` TO `{$old_name}`";
            }
        }

        $tgt->exec('RENAME TABLE ' . implode(', ', $rename_pairs));

        // wp_postmeta is now GONE from live (moved to _old_wp_postmeta)
        // because the current commit logic treats unpushed tables as "dropped"
        $live_tables = [];
        $stmt = $tgt->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['TABLE_NAME'];
            if (!str_starts_with($name, '_old_') && !str_starts_with($name, PUSH_STAGING_TABLE_PREFIX)) {
                $live_tables[] = $name;
            }
        }

        $this->assertNotContains('wp_postmeta', $live_tables,
            "BUG DOCUMENTED: pushing only wp_posts silently removes wp_postmeta from live"
        );

        // Even if wp_postmeta survived, it would have dangling references:
        // postmeta rows point to post_ids 1,2,3 but wp_posts only has id=1
    }
}
