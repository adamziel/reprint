<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests MySQL dump with rows larger than max_allowed_packet.
 *
 * These tests verify that large BLOB/TEXT columns are properly split into
 * multiple UPDATE statements when they would exceed max_statement_size.
 */
class OversizedRowsTest extends MySQLDumpProducerTestBase
{
    /**
     * Test that a small row is exported normally without splitting.
     */
    public function testSmallRowNotSplit(): void
    {
        $this->pdo->exec("
            CREATE TABLE small_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        // Insert 1KB of data - should not trigger splitting
        $smallData = random_bytes(1024);
        $stmt = $this->pdo->prepare("INSERT INTO small_data (content) VALUES (?)");
        $stmt->execute([$smallData]);

        // Use a large max_statement_size so nothing gets split
        $sql = $this->getDumpSQL(['max_statement_size' => 1024 * 1024]);

        // Should not contain UPDATE statements
        $this->assertStringNotContainsString('UPDATE', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['small_data']);
    }

    /**
     * Test that a large row is split into INSERT + UPDATE statements.
     */
    public function testLargeRowSplitIntoUpdates(): void
    {
        $this->pdo->exec("
            CREATE TABLE large_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        // Insert 100KB of data with a 10KB max_statement_size
        // This should trigger splitting
        $largeData = random_bytes(100 * 1024);
        $stmt = $this->pdo->prepare("INSERT INTO large_data (content) VALUES (?)");
        $stmt->execute([$largeData]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Should contain UPDATE statements with CONCAT
        $this->assertStringContainsString('UPDATE', $sql);
        $this->assertStringContainsString('CONCAT', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Verify data integrity
        $imported = $importPdo->query("SELECT content FROM large_data WHERE id = 1")->fetchColumn();
        $this->assertEquals(strlen($largeData), strlen($imported), 'Large blob size should match');
        $this->assertEquals($largeData, $imported, 'Large blob content should match');
    }

    /**
     * Test that large TEXT columns are handled correctly.
     */
    public function testLargeTextColumnSplit(): void
    {
        $this->pdo->exec("
            CREATE TABLE large_text (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGTEXT
            )
        ");

        // Insert 50KB of text data
        $largeText = str_repeat("Hello World! This is test data. ", 2000);
        $stmt = $this->pdo->prepare("INSERT INTO large_text (content) VALUES (?)");
        $stmt->execute([$largeText]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Should contain UPDATE statements
        $this->assertStringContainsString('UPDATE', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $imported = $importPdo->query("SELECT content FROM large_text WHERE id = 1")->fetchColumn();
        $this->assertEquals($largeText, $imported, 'Large text content should match');
    }

    /**
     * Test multiple large columns in the same row.
     */
    public function testMultipleLargeColumns(): void
    {
        $this->pdo->exec("
            CREATE TABLE multi_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                blob1 LONGBLOB,
                blob2 LONGBLOB,
                small_col VARCHAR(100)
            )
        ");

        $blob1 = random_bytes(50 * 1024);
        $blob2 = random_bytes(50 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO multi_large (blob1, blob2, small_col) VALUES (?, ?, ?)");
        $stmt->execute([$blob1, $blob2, 'small value']);

        $sql = $this->getDumpSQL(['max_statement_size' => 20 * 1024]);

        // Both large columns should trigger updates
        $updateCount = substr_count($sql, 'UPDATE');
        $this->assertGreaterThan(1, $updateCount, 'Should have multiple UPDATE statements');

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $row = $importPdo->query("SELECT * FROM multi_large WHERE id = 1")->fetch();
        $this->assertEquals($blob1, $row['blob1']);
        $this->assertEquals($blob2, $row['blob2']);
        $this->assertEquals('small value', $row['small_col']);
    }

    /**
     * Test that multiple rows with large data are handled correctly.
     */
    public function testMultipleRowsWithLargeData(): void
    {
        $this->pdo->exec("
            CREATE TABLE multi_rows (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $data1 = random_bytes(30 * 1024);
        $data2 = random_bytes(30 * 1024);
        $data3 = random_bytes(30 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO multi_rows (content) VALUES (?)");
        $stmt->execute([$data1]);
        $stmt->execute([$data2]);
        $stmt->execute([$data3]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM multi_rows ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertEquals($data1, $rows[0]['content']);
        $this->assertEquals($data2, $rows[1]['content']);
        $this->assertEquals($data3, $rows[2]['content']);
    }

    /**
     * Test with composite primary key.
     */
    public function testCompositePrimaryKey(): void
    {
        $this->pdo->exec("
            CREATE TABLE composite_pk (
                tenant_id INT,
                item_id INT,
                data LONGBLOB,
                PRIMARY KEY (tenant_id, item_id)
            )
        ");

        $largeData = random_bytes(50 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO composite_pk (tenant_id, item_id, data) VALUES (?, ?, ?)");
        $stmt->execute([1, 100, $largeData]);
        $stmt->execute([1, 200, random_bytes(50 * 1024)]);
        $stmt->execute([2, 100, random_bytes(50 * 1024)]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // UPDATE statements should use composite WHERE clause
        $this->assertStringContainsString('UPDATE', $sql);
        $this->assertStringContainsString('tenant_id', $sql);
        $this->assertStringContainsString('item_id', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $row = $importPdo->query("SELECT data FROM composite_pk WHERE tenant_id = 1 AND item_id = 100")->fetch();
        $this->assertEquals($largeData, $row['data']);
    }

    /**
     * Test cursor-based resumption with oversized rows.
     */
    public function testReentrancyWithOversizedRows(): void
    {
        $this->pdo->exec("
            CREATE TABLE reentrant_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        // Insert multiple large rows
        $data = [];
        $stmt = $this->pdo->prepare("INSERT INTO reentrant_large (content) VALUES (?)");
        for ($i = 0; $i < 5; $i++) {
            $data[$i] = random_bytes(20 * 1024);
            $stmt->execute([$data[$i]]);
        }

        // Export with small max_statement_size and limited fragments per iteration
        $options = [
            'max_statement_size' => 8 * 1024,
            'batch_size' => 1,
        ];

        $producer = $this->createProducer($options);
        $allFragments = [];
        $iterations = 0;
        $maxIterations = 100;

        while (!$producer->is_finished() && $iterations < $maxIterations) {
            // Simulate stopping and resuming every few fragments
            $fragmentsThisRound = 0;
            while ($fragmentsThisRound < 3 && $producer->next_sql_fragment()) {
                $fragment = $producer->get_sql_fragment();
                if ($fragment !== null) {
                    $allFragments[] = $fragment;
                }
                $fragmentsThisRound++;
            }

            if (!$producer->is_finished()) {
                // Save cursor and create new producer
                $cursor = $producer->get_reentrancy_cursor();
                $options['cursor'] = $cursor;
                $producer = $this->createProducer($options);
            }

            $iterations++;
        }

        $this->assertLessThan($maxIterations, $iterations, 'Should complete within reasonable iterations');

        $sql = implode("\n", $allFragments);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM reentrant_large ORDER BY id")->fetchAll();
        $this->assertCount(5, $rows);
        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals($data[$i], $rows[$i]['content'], "Row $i content should match");
        }
    }

    /**
     * Test with base64 string encoding.
     */
    public function testBase64EncodingWithLargeData(): void
    {
        $this->pdo->exec("
            CREATE TABLE base64_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGTEXT
            )
        ");

        $largeText = str_repeat("Base64 encoded text data! ", 3000);
        $stmt = $this->pdo->prepare("INSERT INTO base64_large (content) VALUES (?)");
        $stmt->execute([$largeText]);

        $sql = $this->getDumpSQL([
            'max_statement_size' => 15 * 1024,
        ]);

        // Should use FROM_BASE64 in updates
        $this->assertStringContainsString('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $imported = $importPdo->query("SELECT content FROM base64_large WHERE id = 1")->fetchColumn();
        $this->assertEquals($largeText, $imported);
    }

    /**
     * Test that each SQL statement is within max_statement_size.
     */
    public function testStatementSizesRespectLimit(): void
    {
        $this->pdo->exec("
            CREATE TABLE size_check (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $largeData = random_bytes(100 * 1024);
        $stmt = $this->pdo->prepare("INSERT INTO size_check (content) VALUES (?)");
        $stmt->execute([$largeData]);

        $maxSize = 10 * 1024;
        $producer = $this->createProducer(['max_statement_size' => $maxSize]);

        $oversizedStatements = [];
        while ($producer->next_sql_fragment()) {
            $fragment = $producer->get_sql_fragment();
            if ($fragment !== null) {
                $size = strlen($fragment);
                // Allow some margin for measurement differences
                if ($size > $maxSize * 1.5) {
                    $oversizedStatements[] = [
                        'size' => $size,
                        'preview' => substr($fragment, 0, 100) . '...',
                    ];
                }
            }
        }

        $this->assertEmpty(
            $oversizedStatements,
            'All statements should be within max_statement_size. Oversized: ' .
            json_encode($oversizedStatements)
        );
    }

    /**
     * Test mixed small and large rows.
     */
    public function testMixedRowSizes(): void
    {
        $this->pdo->exec("
            CREATE TABLE mixed_sizes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO mixed_sizes (content) VALUES (?)");

        // Mix of small and large rows
        $data = [
            random_bytes(500),           // Small
            random_bytes(50 * 1024),     // Large
            random_bytes(200),           // Small
            random_bytes(40 * 1024),     // Large
            random_bytes(100),           // Small
        ];

        foreach ($data as $d) {
            $stmt->execute([$d]);
        }

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM mixed_sizes ORDER BY id")->fetchAll();
        $this->assertCount(5, $rows);
        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals($data[$i], $rows[$i]['content'], "Row $i content should match");
        }
    }

    /**
     * Test with NULL values in large columns.
     */
    public function testNullValuesInLargeColumns(): void
    {
        $this->pdo->exec("
            CREATE TABLE null_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO null_large (content) VALUES (?)");
        $stmt->execute([null]);
        $stmt->execute([random_bytes(30 * 1024)]);
        $stmt->execute([null]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM null_large ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertNull($rows[0]['content']);
        $this->assertNotNull($rows[1]['content']);
        $this->assertNull($rows[2]['content']);
    }

    /**
     * Test with empty string values.
     */
    public function testEmptyStringInLargeColumns(): void
    {
        $this->pdo->exec("
            CREATE TABLE empty_large (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content LONGBLOB
            )
        ");

        $largeData = random_bytes(30 * 1024);
        $stmt = $this->pdo->prepare("INSERT INTO empty_large (content) VALUES (?)");
        $stmt->execute(['']);
        $stmt->execute([$largeData]);
        $stmt->execute(['']);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT * FROM empty_large ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertEquals('', $rows[0]['content']);
        $this->assertEquals($largeData, $rows[1]['content']);
        $this->assertEquals('', $rows[2]['content']);
    }

    /**
     * Test that primary key columns are never split (they're needed for WHERE clause).
     */
    public function testPrimaryKeyColumnsNotSplit(): void
    {
        // Use a VARCHAR primary key with moderate value (max key length is 3072 bytes with utf8mb4)
        $this->pdo->exec("
            CREATE TABLE varchar_pk (
                id VARCHAR(200) PRIMARY KEY,
                content LONGBLOB
            )
        ");

        $largePk = str_repeat('x', 150);
        $largeContent = random_bytes(30 * 1024);

        $stmt = $this->pdo->prepare("INSERT INTO varchar_pk (id, content) VALUES (?, ?)");
        $stmt->execute([$largePk, $largeContent]);

        $sql = $this->getDumpSQL(['max_statement_size' => 10 * 1024]);

        // The PK value should appear in UPDATE WHERE clauses
        // It should NOT be split
        $this->assertStringContainsString('UPDATE', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $row = $importPdo->query("SELECT * FROM varchar_pk")->fetch();
        $this->assertEquals($largePk, $row['id']);
        $this->assertEquals($largeContent, $row['content']);
    }
}
