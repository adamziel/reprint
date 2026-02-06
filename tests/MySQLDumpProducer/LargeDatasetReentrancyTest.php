<?php

require_once __DIR__ . "/MySQLDumpProducerTestBase.php";

/**
 * Tests MySQL dump producer with large datasets and cursor reentrancy.
 * This test verifies that:
 * 1. Large datasets (200k rows) can be exported
 * 2. Cursor-based resumption works correctly
 * 3. Data integrity is maintained across many iterations
 * 4. No rows are lost or duplicated during paused/resumed exports
 */
class LargeDatasetReentrancyTest extends MySQLDumpProducerTestBase
{
    /**
     * Test exporting 200k rows with cursor resumption every 200 rows.
     * This results in 1000+ iterations to fully test reentrancy.
     */
    public function testLargeDatasetWithReentrancy(): void
    {
        $totalRows = 200000;
        $resumeEvery = 200; // Rows per iteration

        $this->pdo->exec("
            CREATE TABLE large_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100),
                value INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Insert test data in batches
        $this->insertLargeDataset($totalRows);

        // Export with resumption
        $allFragments = $this->exportWithReentrancy($resumeEvery);

        // Verify we got all the data
        $completeSQL = implode("\n", $allFragments);

        // Count INSERT statements
        $insertCount = substr_count($completeSQL, "INSERT INTO");
        $this->assertGreaterThan(
            0,
            $insertCount,
            "Should have INSERT statements",
        );

        // Import and verify
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        // Verify row count
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM large_table")
            ->fetchColumn();
        $this->assertEquals($totalRows, $count, "All rows should be imported");

        // Verify no duplicates
        $distinctCount = $importPdo
            ->query("SELECT COUNT(DISTINCT id) FROM large_table")
            ->fetchColumn();
        $this->assertEquals(
            $totalRows,
            $distinctCount,
            "All IDs should be unique",
        );

        // Verify data integrity with checksums
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["large_table"]);
    }

    /**
     * Test with composite primary key and reentrancy.
     */
    public function testCompositePrimaryKeyReentrancy(): void
    {
        $this->pdo->exec("
            CREATE TABLE composite_large (
                part1 INT NOT NULL,
                part2 INT NOT NULL,
                data VARCHAR(50),
                PRIMARY KEY (part1, part2)
            )
        ");

        // Insert 10,000 rows with composite key
        $values = [];
        for ($i = 1; $i <= 100; $i++) {
            for ($j = 1; $j <= 100; $j++) {
                $values[] = "({$i}, {$j}, 'data_{$i}_{$j}')";
                if (count($values) >= 1000) {
                    $this->pdo->exec(
                        "INSERT INTO composite_large VALUES " .
                            implode(",", $values),
                    );
                    $values = [];
                }
            }
        }
        if (!empty($values)) {
            $this->pdo->exec(
                "INSERT INTO composite_large VALUES " . implode(",", $values),
            );
        }

        // Export with frequent resumption
        $allFragments = $this->exportWithReentrancy(100);

        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        // Verify all rows
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM composite_large")
            ->fetchColumn();
        $this->assertEquals(10000, $count);

        $this->assertDatabasesEqual($this->pdo, $importPdo, [
            "composite_large",
        ]);
    }

    /**
     * Test with no primary key and offset-based pagination.
     */
    public function testNoPrimaryKeyReentrancy(): void
    {
        $this->pdo->exec("
            CREATE TABLE no_pk_large (
                data VARCHAR(100),
                value INT
            )
        ");

        // Insert 5000 rows without PK
        for ($batch = 0; $batch < 50; $batch++) {
            $values = [];
            for ($i = 0; $i < 100; $i++) {
                $row = $batch * 100 + $i;
                $values[] = "('row_{$row}', {$row})";
            }
            $this->pdo->exec(
                "INSERT INTO no_pk_large VALUES " . implode(",", $values),
            );
        }

        // Export with resumption
        $allFragments = $this->exportWithReentrancy(100);

        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        // Verify row count
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM no_pk_large")
            ->fetchColumn();
        $this->assertEquals(5000, $count);
    }

    /**
     * Test multiple tables with reentrancy.
     */
    public function testMultipleTablesWithReentrancy(): void
    {
        // Create 3 tables
        for ($t = 1; $t <= 3; $t++) {
            $this->pdo->exec("
                CREATE TABLE table_{$t} (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    data VARCHAR(50)
                )
            ");

            // Insert 1000 rows per table
            for ($batch = 0; $batch < 10; $batch++) {
                $values = [];
                for ($i = 0; $i < 100; $i++) {
                    $values[] =
                        "('table_{$t}_row_" . ($batch * 100 + $i) . "')";
                }
                $this->pdo->exec(
                    "INSERT INTO table_{$t} (data) VALUES " .
                        implode(",", $values),
                );
            }
        }

        // Export with frequent resumption
        $allFragments = $this->exportWithReentrancy(50);

        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        // Verify all tables
        for ($t = 1; $t <= 3; $t++) {
            $count = $importPdo
                ->query("SELECT COUNT(*) FROM table_{$t}")
                ->fetchColumn();
            $this->assertEquals(
                1000,
                $count,
                "table_{$t} should have 1000 rows",
            );
        }
    }

    /**
     * Test cursor state across batch boundaries.
     */
    public function testCursorAcrossBatchBoundaries(): void
    {
        $this->pdo->exec("
            CREATE TABLE batch_boundary (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert exactly 500 rows (2 batches of 250)
        for ($i = 1; $i <= 500; $i++) {
            $this->pdo->exec(
                "INSERT INTO batch_boundary (data) VALUES ('row_{$i}')",
            );
        }

        // Export with batch size of 250, resume every 125 rows
        $producer = $this->createProducer(["batch_size" => 250]);
        $fragmentCount = 0;
        $iterationCount = 0;
        $cursor = null;
        $allFragments = [];

        while (!$producer->is_finished()) {
            $rowsInIteration = 0;

            while ($rowsInIteration < 125 && $producer->next_sql_fragment()) {
                $sql = $producer->get_sql_fragment();
                if ($sql !== null) {
                    $allFragments[] = $sql;
                    // Count row fragments (they start with opening parenthesis)
                    $trimmed = ltrim($sql);
                    if ($trimmed !== "" && $trimmed[0] === "(") {
                        $rowsInIteration++;
                    }
                }
                $fragmentCount++;
            }

            $iterationCount++;

            if (!$producer->is_finished()) {
                $cursor = $producer->get_reentrancy_cursor();
                // Resume from cursor
                $producer = $this->createProducer([
                    "batch_size" => 250,
                    "cursor" => $cursor,
                ]);
            }
        }

        // Verify that resumption worked and all data was exported
        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM batch_boundary")
            ->fetchColumn();
        $this->assertEquals(500, $count, "All 500 rows should be exported");

        // Should have multiple iterations due to resumption
        $this->assertGreaterThanOrEqual(
            2,
            $iterationCount,
            "Should have at least 2 iterations with resumption",
        );
    }

    /**
     * Test resumption in middle of accumulated batch.
     */
    public function testResumptionMidBatch(): void
    {
        $this->pdo->exec("
            CREATE TABLE mid_batch (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert 300 rows
        for ($i = 1; $i <= 300; $i++) {
            $this->pdo->exec(
                "INSERT INTO mid_batch (data) VALUES ('row_{$i}')",
            );
        }

        // Start export with batch size 250
        $producer = $this->createProducer(["batch_size" => 250]);

        // Process CREATE TABLE
        $producer->next_sql_fragment();
        $producer->next_sql_fragment(); // Header comment

        // Now we should be accumulating rows
        // Get cursor before first INSERT is emitted
        $cursor = $producer->get_reentrancy_cursor();

        // Create new producer from cursor
        $producer2 = $this->createProducer([
            "batch_size" => 250,
            "cursor" => $cursor,
        ]);

        // Should be able to continue seamlessly
        $fragments = $this->collectAllFragments($producer2);

        // Verify we got INSERTs
        $insertFragments = array_filter($fragments, function ($f) {
            return strpos($f, "INSERT INTO") === 0;
        });

        $this->assertGreaterThan(0, count($insertFragments));
    }

    /**
     * Test very small batch size with many iterations.
     */
    public function testSmallBatchManyIterations(): void
    {
        $this->pdo->exec("
            CREATE TABLE small_batches (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data INT
            )
        ");

        // Insert 1000 rows
        $values = [];
        for ($i = 1; $i <= 1000; $i++) {
            $values[] = "({$i})";
        }
        $this->pdo->exec(
            "INSERT INTO small_batches (data) VALUES " . implode(",", $values),
        );

        // Export with very small batch size (10 rows)
        $allFragments = $this->exportWithReentrancy(10, 10);

        $completeSQL = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);

        $count = $importPdo
            ->query("SELECT COUNT(*) FROM small_batches")
            ->fetchColumn();
        $this->assertEquals(1000, $count);

        // Verify no duplicates or missing rows
        $sum = $importPdo
            ->query("SELECT SUM(data) FROM small_batches")
            ->fetchColumn();
        $expectedSum = (1000 * 1001) / 2; // Sum of 1 to 1000
        $this->assertEquals($expectedSum, $sum);
    }

    /**
     * Test cursor serialization/deserialization integrity.
     */
    public function testCursorSerializationIntegrity(): void
    {
        $this->pdo->exec("
            CREATE TABLE cursor_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        for ($i = 1; $i <= 100; $i++) {
            $this->pdo->exec(
                "INSERT INTO cursor_test (data) VALUES ('row_{$i}')",
            );
        }

        $producer = $this->createProducer(["batch_size" => 25]);

        // Process a few fragments
        for ($i = 0; $i < 5; $i++) {
            $producer->next_sql_fragment();
        }

        // Get cursor
        $cursor = $producer->get_reentrancy_cursor();

        // Verify cursor is valid JSON
        $cursorData = json_decode($cursor, true);
        $this->assertIsArray($cursorData);
        $this->assertArrayHasKey("current_table", $cursorData);
        $this->assertArrayHasKey("state", $cursorData);
        $this->assertArrayHasKey("current_row", $cursorData);

        // Resume from cursor
        $producer2 = $this->createProducer([
            "batch_size" => 25,
            "cursor" => $cursor,
        ]);

        // Should be able to continue
        $this->assertFalse($producer2->is_finished());
        $this->assertTrue($producer2->next_sql_fragment());
    }

    /**
     * Helper: Insert large dataset in batches.
     */
    private function insertLargeDataset(int $totalRows): void
    {
        $batchSize = 1000;
        $batches = ceil($totalRows / $batchSize);

        for ($batch = 0; $batch < $batches; $batch++) {
            $values = [];
            $start = $batch * $batchSize + 1;
            $end = min(($batch + 1) * $batchSize, $totalRows);

            for ($i = $start; $i <= $end; $i++) {
                $values[] = "('data_{$i}', {$i})";
            }

            $this->pdo->exec(
                "INSERT INTO large_table (data, value) VALUES " .
                    implode(",", $values),
            );
        }
    }

    /**
     * Helper: Export with reentrancy, resuming every N rows.
     */
    private function exportWithReentrancy(
        int $resumeEveryRows,
        int $batchSize = 250,
    ): array {
        $allFragments = [];
        $producer = $this->createProducer(["batch_size" => $batchSize]);
        $totalRowsProcessed = 0;
        $iterations = 0;

        while (!$producer->is_finished()) {
            $rowsInIteration = 0;
            $fragmentsInIteration = 0;

            while ($producer->next_sql_fragment()) {
                $sql = $producer->get_sql_fragment();

                if ($sql !== null) {
                    $allFragments[] = $sql;
                    $fragmentsInIteration++;

                    // Count rows in INSERT statements
                    if (strpos($sql, "INSERT INTO") === 0) {
                        $rowsInIteration += substr_count($sql, "(");
                    }
                }

                // Resume after processing enough rows
                if ($rowsInIteration >= $resumeEveryRows) {
                    break;
                }
            }

            $totalRowsProcessed += $rowsInIteration;
            $iterations++;

            // Resume from cursor if not finished
            if (!$producer->is_finished()) {
                $cursor = $producer->get_reentrancy_cursor();
                $producer = $this->createProducer([
                    "batch_size" => $batchSize,
                    "cursor" => $cursor,
                ]);
            }
        }

        // Log for debugging
        fwrite(
            STDERR,
            "Completed export in {$iterations} iterations, processed ~{$totalRowsProcessed} rows\n",
        );

        return $allFragments;
    }

    /**
     * Test export of exactly one row to test edge cases.
     */
    public function testSingleRowExport(): void
    {
        $this->pdo->exec("
            CREATE TABLE single_row (
                id INT PRIMARY KEY,
                data VARCHAR(50)
            )
        ");

        $this->pdo->exec("INSERT INTO single_row VALUES (1, 'only row')");

        $sql = $this->getDumpSQL();

        // Should have CREATE TABLE and one INSERT
        $this->assertSQLContains("CREATE TABLE", $sql);
        $this->assertSQLContains("INSERT INTO", $sql);
        $this->assertSQLContains("only row", $sql);

        // Verify only one INSERT
        $insertCount = substr_count($sql, "INSERT INTO");
        $this->assertEquals(1, $insertCount);
    }

    /**
     * Test export with exactly batch size rows.
     */
    public function testExactBatchSizeRows(): void
    {
        $this->pdo->exec("
            CREATE TABLE exact_batch (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data INT
            )
        ");

        // Insert exactly 250 rows (one batch)
        $values = [];
        for ($i = 1; $i <= 250; $i++) {
            $values[] = "({$i})";
        }
        $this->pdo->exec(
            "INSERT INTO exact_batch (data) VALUES " . implode(",", $values),
        );

        $sql = $this->getDumpSQL(["batch_size" => 250]);

        // Should have exactly one INSERT
        $insertCount = substr_count($sql, "INSERT INTO");
        $this->assertEquals(1, $insertCount);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM exact_batch")
            ->fetchColumn();
        $this->assertEquals(250, $count);
    }
}
