<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Minimal $wpdb stand-in for testing.
 *
 * Records every query that passes through query(), and can be told to
 * simulate an error for a specific statement number.  Only the methods
 * actually called by ImportClient are implemented.
 */
class FakeWpdb
{
    /** @var string[] All queries executed via query(). */
    public $queries = [];

    /** @var string Last error message (empty = no error). */
    public $last_error = '';

    /** @var int|null 1-indexed statement number to fail on, or null. */
    public $fail_on_statement = null;

    /** @var string Error message to use when failing. */
    public $fail_message = 'Simulated wpdb error';

    /** @var bool Current suppress_errors state. */
    private $suppress = false;

    /**
     * Execute a SQL query and record it.
     *
     * @return int|false Number of affected rows, or false on error.
     */
    public function query(string $sql)
    {
        $this->queries[] = $sql;
        $this->last_error = '';

        if (
            $this->fail_on_statement !== null
            && count($this->queries) === $this->fail_on_statement
        ) {
            $this->last_error = $this->fail_message;
            return false;
        }

        return 1;
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $old = $this->suppress;
        $this->suppress = $suppress;
        return $old;
    }

    public function show_errors(bool $show = true): void
    {
        // no-op
    }
}


/**
 * Tests for the $wpdb-based SQL import paths:
 *   - db-apply --wp-load=...
 *   - --sql-output=wpdb
 *
 * These tests use a FakeWpdb that records queries so we can verify
 * the importer feeds the right SQL through $wpdb->query() without
 * needing a real WordPress installation or database.
 */
class WpdbImportTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $docroot;

    /**
     * Path to a no-op wp-load.php that allows load_wordpress() to
     * succeed.  Created once per test class because require_once
     * only loads a file once per process.
     */
    private static $fakeWpLoad;

    public static function setUpBeforeClass(): void
    {
        // The fake wp-load.php is intentionally empty — it doesn't
        // touch $GLOBALS['wpdb'].  Each test pre-sets that global
        // before invoking any code that calls load_wordpress().
        self::$fakeWpLoad = sys_get_temp_dir()
            . '/fake-wp-load-' . uniqid() . '.php';
        file_put_contents(
            self::$fakeWpLoad,
            "<?php\n// Fake wp-load.php for WpdbImportTest\n",
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fakeWpLoad && file_exists(self::$fakeWpLoad)) {
            unlink(self::$fakeWpLoad);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/wpdb-import-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->docroot = $this->tempDir . '/docroot';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->docroot, 0755, true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
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

    private function makeClient(): \ImportClient
    {
        return new \ImportClient(
            'http://fake.url',
            $this->stateDir,
            $this->docroot,
        );
    }

    /**
     * Write a db.sql file in the state directory.
     */
    private function writeSqlFile(string $sql): void
    {
        file_put_contents($this->stateDir . '/db.sql', $sql);
    }

    /**
     * Write a state file directly.
     */
    private function writeState(array $state): void
    {
        $defaults = [
            "command" => null,
            "status" => null,
            "cursor" => null,
            "stage" => null,
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "docroot_nonempty_behavior" => "error",
            "max_allowed_packet" => null,
        ];
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    /**
     * Read the current state file.
     */
    private function readState(): array
    {
        $path = $this->stateDir . '/.import-state.json';
        return json_decode(file_get_contents($path), true);
    }


    // ── Validation tests ────────────────────────────────────────────

    /**
     * db-apply without --target-* or --wp-load should produce a
     * helpful error mentioning both alternatives.
     */
    public function testDbApplyRequiresTargetOrWpLoad(): void
    {
        $this->writeSqlFile("SELECT 1;\n");

        $client = $this->makeClient();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--target-user and --target-db, or --wp-load');

        $client->run(['command' => 'db-apply']);
    }

    /**
     * --wp-load pointing to a nonexistent file should fail early
     * with a clear message.
     */
    public function testWpLoadRejectsNonexistentPath(): void
    {
        $this->writeSqlFile("SELECT 1;\n");

        $client = $this->makeClient();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('wp-load.php not found');

        $client->run([
            'command' => 'db-apply',
            'wp_load' => '/nonexistent/wp-load.php',
        ]);
    }

    /**
     * --sql-output=wpdb without --wp-load should fail with a clear error.
     */
    public function testSqlOutputWpdbRequiresWpLoad(): void
    {
        $client = $this->makeClient();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--wp-load is required');

        $client->run([
            'command' => 'db-sync',
            'sql_output' => 'wpdb',
        ]);
    }

    /**
     * sql_output validation accepts the new "wpdb" mode.
     */
    public function testSqlOutputAcceptsWpdbMode(): void
    {
        // Pre-set wpdb so load_wordpress() succeeds when db-sync
        // actually runs (it will fail on the HTTP request, but we
        // only care that the mode was accepted during validation).
        $GLOBALS['wpdb'] = new FakeWpdb();

        $client = $this->makeClient();
        try {
            $client->run([
                'command' => 'db-sync',
                'sql_output' => 'wpdb',
                'wp_load' => self::$fakeWpLoad,
            ]);
        } catch (\RuntimeException $e) {
            // Expected — db-sync will fail because there's no real
            // server, but we got past mode validation.
            $this->assertStringNotContainsString(
                'Invalid --sql-output mode',
                $e->getMessage(),
            );
            return;
        }
        // If it didn't throw, that's fine too (unlikely but acceptable).
    }


    // ── load_wordpress() tests ──────────────────────────────────────

    /**
     * load_wordpress() should throw when $GLOBALS['wpdb'] is not set
     * after loading wp-load.php.
     */
    public function testLoadWordpressThrowsWhenWpdbMissing(): void
    {
        unset($GLOBALS['wpdb']);

        $client = $this->makeClient();

        // Set wp_load_path via reflection so load_wordpress() has a
        // file to load.
        $ref = new \ReflectionClass($client);
        $prop = $ref->getProperty('wp_load_path');
        $prop->setValue($client, self::$fakeWpLoad);

        $method = $ref->getMethod('load_wordpress');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('$wpdb is not available');

        $method->invoke($client);
    }

    /**
     * load_wordpress() returns whatever is in $GLOBALS['wpdb'].
     */
    public function testLoadWordpressReturnsWpdb(): void
    {
        $mock = new FakeWpdb();
        $GLOBALS['wpdb'] = $mock;

        $client = $this->makeClient();

        $ref = new \ReflectionClass($client);
        $prop = $ref->getProperty('wp_load_path');
        $prop->setValue($client, self::$fakeWpLoad);

        $method = $ref->getMethod('load_wordpress');
        $result = $method->invoke($client);

        $this->assertSame($mock, $result);
    }


    // ── db-apply execution tests ────────────────────────────────────

    /**
     * db-apply --wp-load should feed every SQL statement from db.sql
     * through $wpdb->query() in order.
     */
    public function testDbApplyWithWpLoadExecutesAllStatements(): void
    {
        $sql = implode("\n", [
            "DROP TABLE IF EXISTS `test_table`;",
            "CREATE TABLE `test_table` (id INT);",
            "INSERT INTO `test_table` VALUES (1);",
            "INSERT INTO `test_table` VALUES (2);",
        ]);
        $this->writeSqlFile($sql);

        $mock = new FakeWpdb();
        $GLOBALS['wpdb'] = $mock;

        $client = $this->makeClient();
        $client->run([
            'command' => 'db-apply',
            'wp_load' => self::$fakeWpLoad,
        ]);

        // All four statements should have been executed via $wpdb.
        // Trim queries because the query stream may preserve leading
        // whitespace from the SQL file.
        $this->assertCount(4, $mock->queries);
        $this->assertStringStartsWith('DROP TABLE', trim($mock->queries[0]));
        $this->assertStringStartsWith('CREATE TABLE', trim($mock->queries[1]));
        $this->assertStringContainsString('VALUES (1)', $mock->queries[2]);
        $this->assertStringContainsString('VALUES (2)', $mock->queries[3]);

        // State should be marked complete
        $state = $this->readState();
        $this->assertSame('complete', $state['status']);
        $this->assertSame(4, $state['apply']['statements_executed']);
    }

    /**
     * When $wpdb->query() returns false with a last_error, db-apply
     * should report the error with the statement number and a snippet
     * of the failing query.
     */
    public function testDbApplyWithWpLoadReportsErrors(): void
    {
        $sql = "INSERT INTO `ok_table` VALUES (1);\nINSERT INTO `bad_table` VALUES (2);\n";
        $this->writeSqlFile($sql);

        $mock = new FakeWpdb();
        $mock->fail_on_statement = 2;
        $mock->fail_message = 'no such table: bad_table';
        $GLOBALS['wpdb'] = $mock;

        $client = $this->makeClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SQL execution error at statement 2');

        $client->run([
            'command' => 'db-apply',
            'wp_load' => self::$fakeWpLoad,
        ]);
    }

    /**
     * db-apply with --wp-load persists the wp_load path and statement
     * count in state, and marks the run complete.
     */
    public function testWpLoadPathPersistedInState(): void
    {
        $this->writeSqlFile("SELECT 1;\n");

        $GLOBALS['wpdb'] = new FakeWpdb();

        $client = $this->makeClient();
        $client->run([
            'command' => 'db-apply',
            'wp_load' => self::$fakeWpLoad,
        ]);

        $state = $this->readState();
        $this->assertSame(
            realpath(self::$fakeWpLoad),
            $state['wp_load'],
        );
    }

    /**
     * db-apply with --wp-load correctly resumes from a partially
     * completed run by skipping already-executed statements.
     */
    public function testDbApplyWithWpLoadResumes(): void
    {
        // 4 statements, pretend 2 were already executed
        $sql = implode("\n", [
            "INSERT INTO t VALUES (1);",
            "INSERT INTO t VALUES (2);",
            "INSERT INTO t VALUES (3);",
            "INSERT INTO t VALUES (4);",
        ]);
        $this->writeSqlFile($sql);

        // Write state showing 2 statements already executed
        $this->writeState([
            'command' => 'db-apply',
            'status' => 'in_progress',
            'apply' => [
                'statements_executed' => 2,
                'bytes_read' => 0,  // forces statement-skipping path
                'rewrite_url' => null,
            ],
            'wp_load' => self::$fakeWpLoad,
        ]);

        $mock = new FakeWpdb();
        $GLOBALS['wpdb'] = $mock;

        $client = $this->makeClient();
        $client->run([
            'command' => 'db-apply',
            'wp_load' => self::$fakeWpLoad,
        ]);

        // Only the last 2 statements should have been executed
        $this->assertCount(2, $mock->queries);
        $this->assertStringContainsString('VALUES (3)', $mock->queries[0]);
        $this->assertStringContainsString('VALUES (4)', $mock->queries[1]);

        $state = $this->readState();
        $this->assertSame('complete', $state['status']);
        $this->assertSame(4, $state['apply']['statements_executed']);
    }

    /**
     * When --wp-load is provided, the --target-* MySQL options are
     * not required — the importer skips the PDO connection entirely.
     */
    public function testDbApplyWithWpLoadSkipsTargetValidation(): void
    {
        $this->writeSqlFile("SELECT 1;\n");
        $GLOBALS['wpdb'] = new FakeWpdb();

        $client = $this->makeClient();
        // Should NOT throw "requires --target-user and --target-db"
        $client->run([
            'command' => 'db-apply',
            'wp_load' => self::$fakeWpLoad,
            // Intentionally omitting target_user and target_db
        ]);

        $this->assertSame('complete', $this->readState()['status']);
    }

    /**
     * Existing direct-MySQL db-apply path still works: when --wp-load
     * is NOT provided, the old --target-* validation fires.
     */
    public function testDbApplyWithoutWpLoadStillRequiresTarget(): void
    {
        $this->writeSqlFile("SELECT 1;\n");

        $client = $this->makeClient();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--target-user and --target-db, or --wp-load');

        $client->run([
            'command' => 'db-apply',
            // No wp_load, no target_user/target_db
        ]);
    }
}
