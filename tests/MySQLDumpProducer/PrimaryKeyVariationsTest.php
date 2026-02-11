<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests MySQL dump with various primary key configurations.
 */
class PrimaryKeyVariationsTest extends MySQLDumpProducerTestBase
{
    public function testSimplePrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(50) NOT NULL
            )
        ");

        // Insert 1500 rows to test pagination (in batches for speed)
        for ($batch = 0; $batch < 15; $batch++) {
            $values = [];
            for ($i = 1; $i <= 100; $i++) {
                $row = $batch * 100 + $i;
                $values[] = "('user{$row}')";
            }
            $this->pdo->exec("INSERT INTO users (username) VALUES " . implode(',', $values));
        }

        $sql = $this->getDumpSQL(['batch_size' => 250]);

        // Verify SQL contains INSERT statements with base64-encoded data
        $this->assertSQLContains('INSERT INTO', $sql);
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test - this is the real verification
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertEquals(1500, $count, 'Should have all 1500 users after import');

        // Verify first and last users exist
        $first = $importPdo->query("SELECT username FROM users WHERE id = 1")->fetchColumn();
        $last = $importPdo->query("SELECT username FROM users WHERE id = 1500")->fetchColumn();
        $this->assertEquals('user1', $first);
        $this->assertEquals('user1500', $last);
    }

    public function testCompositePrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE order_items (
                order_id INT NOT NULL,
                item_id INT NOT NULL,
                quantity INT NOT NULL,
                PRIMARY KEY (order_id, item_id)
            )
        ");

        // Insert data with composite keys
        $values = [];
        for ($orderId = 1; $orderId <= 10; $orderId++) {
            for ($itemId = 1; $itemId <= 5; $itemId++) {
                $values[] = "({$orderId}, {$itemId}, " . ($orderId * $itemId) . ")";
            }
        }
        $this->pdo->exec("INSERT INTO order_items (order_id, item_id, quantity) VALUES " . implode(',', $values));

        $sql = $this->getDumpSQL();

        // Round-trip test - composite keys must maintain order
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['order_items']);
    }

    public function testThreeColumnCompositePrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE booking_slots (
                venue_id INT NOT NULL,
                date DATE NOT NULL,
                time_slot INT NOT NULL,
                booked_by VARCHAR(50),
                PRIMARY KEY (venue_id, date, time_slot)
            )
        ");

        // Insert various combinations
        $this->pdo->exec("
            INSERT INTO booking_slots (venue_id, date, time_slot, booked_by) VALUES
            (1, '2024-01-15', 1, 'Alice'),
            (1, '2024-01-15', 2, 'Bob'),
            (1, '2024-01-16', 1, 'Charlie'),
            (2, '2024-01-15', 1, 'Dave'),
            (2, '2024-01-15', 2, 'Eve')
        ");

        $sql = $this->getDumpSQL();

        // Verify lexicographic ordering is handled
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['booking_slots']);
    }

    public function testNoPrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE logs (
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                level VARCHAR(20),
                message TEXT
            )
        ");

        // Insert 300 rows (more than one batch)
        for ($i = 1; $i <= 300; $i++) {
            $this->pdo->exec("INSERT INTO logs (level, message) VALUES ('INFO', 'Log entry {$i}')");
        }

        $sql = $this->getDumpSQL(['batch_size' => 100]);

        // Verify rows are exported (strings are base64-encoded)
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        $this->assertEquals(300, $count);
    }

    public function testMultipleTablesWithDifferentPKTypes(): void
    {
        // Table with simple PK
        $this->pdo->exec("
            CREATE TABLE simple_pk (
                id INT PRIMARY KEY,
                data VARCHAR(50)
            )
        ");

        // Table with composite PK
        $this->pdo->exec("
            CREATE TABLE composite_pk (
                part1 INT,
                part2 INT,
                data VARCHAR(50),
                PRIMARY KEY (part1, part2)
            )
        ");

        // Table with no PK
        $this->pdo->exec("
            CREATE TABLE no_pk (
                data VARCHAR(50)
            )
        ");

        // Insert data
        $this->pdo->exec("INSERT INTO simple_pk VALUES (1, 'a'), (2, 'b'), (3, 'c')");
        $this->pdo->exec("INSERT INTO composite_pk VALUES (1, 1, 'x'), (1, 2, 'y'), (2, 1, 'z')");
        $this->pdo->exec("INSERT INTO no_pk VALUES ('data1'), ('data2'), ('data3')");

        $sql = $this->getDumpSQL();

        // Verify all tables and data
        $tables = $this->extractTableNames($sql);
        $this->assertCount(3, $tables);
        $this->assertContains('simple_pk', $tables);
        $this->assertContains('composite_pk', $tables);
        $this->assertContains('no_pk', $tables);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['simple_pk', 'composite_pk', 'no_pk']);
    }

    public function testStringPrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE countries (
                code CHAR(2) PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            )
        ");

        $this->pdo->exec("
            INSERT INTO countries (code, name) VALUES
            ('US', 'United States'),
            ('UK', 'United Kingdom'),
            ('CA', 'Canada'),
            ('AU', 'Australia'),
            ('DE', 'Germany')
        ");

        $sql = $this->getDumpSQL();

        // Verify string PK values are base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['countries']);
    }

    public function testUUIDPrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE sessions (
                id CHAR(36) PRIMARY KEY,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Generate some UUID-like strings
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuid = sprintf(
                '%08x-%04x-%04x-%04x-%012x',
                mt_rand(),
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand()
            );
            $uuids[] = "('{$uuid}', {$i})";
        }
        $this->pdo->exec("INSERT INTO sessions (id, user_id) VALUES " . implode(',', $uuids));

        $sql = $this->getDumpSQL(['batch_size' => 50]);

        // Should produce 2 INSERT statements
        $insertCount = substr_count($sql, 'INSERT INTO `sessions`');
        $this->assertEquals(2, $insertCount);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $this->assertEquals(100, $count);
    }

    public function testNullableCompositePrimaryKey(): void
    {
        // MySQL doesn't allow NULL in primary keys, but we can test with UNIQUE key
        $this->pdo->exec("
            CREATE TABLE temp_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                key1 VARCHAR(50),
                key2 VARCHAR(50),
                value TEXT,
                UNIQUE KEY unique_keys (key1, key2)
            )
        ");

        $this->pdo->exec("
            INSERT INTO temp_data (key1, key2, value) VALUES
            ('a', 'b', 'value1'),
            ('c', 'd', 'value2'),
            (NULL, 'e', 'value3'),
            ('f', NULL, 'value4')
        ");

        $sql = $this->getDumpSQL();

        // Verify NULLs are properly handled (strings are base64-encoded)
        $this->assertMatchesRegularExpression('/NULL,FROM_BASE64\(/', $sql);
        $this->assertMatchesRegularExpression('/FROM_BASE64\([^)]+\),NULL/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['temp_data']);
    }
}
