<?php

require_once __DIR__ . "/MySQLDumpProducerTestBase.php";

/**
 * Tests edge cases when resuming from cursor with changed data.
 */
class ResumeEdgeCasesTest extends MySQLDumpProducerTestBase
{
    /**
     * Test resuming when no more rows exist after pause.
     * Should not leave a dangling comma.
     */
    public function testResumeWithNoMoreRows(): void
    {
        $this->pdo->exec("
            CREATE TABLE resume_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert 10 rows
        for ($i = 1; $i <= 10; $i++) {
            $this->pdo->exec("INSERT INTO resume_test (data) VALUES ('row_{$i}')");
        }

        $producer = $this->createProducer(["batch_size" => 5]);
        $fragments = [];

        // Process header and CREATE TABLE
        while ($producer->next_sql_fragment()) {
            $sql = $producer->get_sql_fragment();
            $fragments[] = $sql;

            // Stop after first INSERT batch (5 rows)
            if (strpos($sql, ");") !== false) {
                break;
            }
        }

        // Get cursor - should be at row 5
        $cursor = $producer->get_reentrancy_cursor();

        // DELETE remaining rows - simulate data disappearing
        $this->pdo->exec("DELETE FROM resume_test WHERE id > 5");

        // Resume from cursor
        $producer2 = $this->createProducer([
            "batch_size" => 5,
            "cursor" => $cursor,
        ]);

        // Collect remaining fragments
        while ($producer2->next_sql_fragment()) {
            $sql = $producer2->get_sql_fragment();
            if ($sql !== null) {
                $fragments[] = $sql;
            }
        }

        // Verify the complete SQL is valid
        $completeSQL = implode("\n", $fragments);

        // Should not have any dangling commas
        $this->assertStringNotContainsString(",\n\n", $completeSQL);
        $this->assertStringNotContainsString(",\nCOMMIT", $completeSQL);

        // Verify it imports correctly
        // Note: We expect 6 rows because row 6 was cached in the cursor
        // even though rows 6-10 were deleted from the database.
        // The cursor represents a consistent snapshot.
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);
        $count = $importPdo->query("SELECT COUNT(*) FROM resume_test")->fetchColumn();
        $this->assertEquals(6, $count, "Should have 6 rows (5 from first batch + 1 cached row)");
    }

    /**
     * Test resuming when table is dropped after pause.
     * Should handle gracefully.
     */
    public function testResumeWithDroppedTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE temp_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert rows
        for ($i = 1; $i <= 100; $i++) {
            $this->pdo->exec("INSERT INTO temp_table (data) VALUES ('row_{$i}')");
        }

        $producer = $this->createProducer(["batch_size" => 50]);
        $fragments = [];

        // Process until we've emitted first batch
        $fragmentCount = 0;
        while ($producer->next_sql_fragment() && $fragmentCount < 10) {
            $sql = $producer->get_sql_fragment();
            $fragments[] = $sql;
            $fragmentCount++;
        }

        // Get cursor
        $cursor = $producer->get_reentrancy_cursor();

        // Drop the table - simulate table disappearing
        $this->pdo->exec("DROP TABLE temp_table");

        // Create a different table to verify producer can continue with other tables
        $this->pdo->exec("
            CREATE TABLE other_table (
                id INT PRIMARY KEY,
                value VARCHAR(50)
            )
        ");
        $this->pdo->exec("INSERT INTO other_table VALUES (1, 'test')");

        // Resume from cursor - should handle missing table gracefully
        $producer2 = $this->createProducer([
            "batch_size" => 50,
            "cursor" => $cursor,
        ]);

        // The producer detects that the table was dropped (not found in
        // tables_to_process) and gracefully skips to the next table instead
        // of crashing. It should still produce output for other_table.
        $resumedFragments = [];
        while ($producer2->next_sql_fragment()) {
            $sql = $producer2->get_sql_fragment();
            if ($sql !== null) {
                $resumedFragments[] = $sql;
            }
        }

        // Verify the producer continued past the dropped table and
        // processed other_table
        $hasOtherTable = false;
        foreach ($resumedFragments as $fragment) {
            if (str_contains($fragment, 'other_table')) {
                $hasOtherTable = true;
                break;
            }
        }
        $this->assertTrue($hasOtherTable, "Should continue with other tables after dropped table");
    }

    /**
     * Test that INSERT header always includes first row.
     * This prevents dangling "INSERT INTO ... VALUES" with no rows.
     */
    public function testInsertHeaderIncludesFirstRow(): void
    {
        $this->pdo->exec("
            CREATE TABLE first_row_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert just 3 rows
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->exec("INSERT INTO first_row_test (data) VALUES ('row_{$i}')");
        }

        $producer = $this->createProducer(["batch_size" => 5]);
        $fragments = [];

        while ($producer->next_sql_fragment()) {
            $sql = $producer->get_sql_fragment();
            if ($sql !== null) {
                $fragments[] = $sql;
            }
        }

        // Find the INSERT header fragment
        $insertHeaderFragment = null;
        foreach ($fragments as $fragment) {
            if (strpos($fragment, "INSERT INTO") === 0) {
                $insertHeaderFragment = $fragment;
                break;
            }
        }

        $this->assertNotNull($insertHeaderFragment, "Should have INSERT header");

        // The INSERT header should include at least the first row
        // It should contain at least one opening parenthesis for row data
        $this->assertStringContainsString("INSERT INTO", $insertHeaderFragment);
        $this->assertStringContainsString("VALUES", $insertHeaderFragment);

        // Should have at least one complete row in the header fragment
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($insertHeaderFragment, "("),
            "INSERT header should include first row"
        );
    }

    /**
     * Test cursor resume right before table boundary.
     * Ensures we don't leave dangling SQL when transitioning tables.
     */
    public function testResumeAtTableBoundary(): void
    {
        // Create two tables
        $this->pdo->exec("
            CREATE TABLE table_a (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE table_b (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        // Insert data in both
        for ($i = 1; $i <= 10; $i++) {
            $this->pdo->exec("INSERT INTO table_a (data) VALUES ('a_{$i}')");
            $this->pdo->exec("INSERT INTO table_b (data) VALUES ('b_{$i}')");
        }

        $producer = $this->createProducer(["batch_size" => 20]);
        $fragments = [];

        // Process until we finish table_a
        while ($producer->next_sql_fragment()) {
            $sql = $producer->get_sql_fragment();
            $fragments[] = $sql;

            // Stop right after finishing table_a (when we see semicolon for last INSERT)
            if (strpos($sql, ");") !== false && strpos($sql, "a_10") !== false) {
                break;
            }
        }

        // Get cursor at table boundary
        $cursor = $producer->get_reentrancy_cursor();

        // Resume and process table_b
        $producer2 = $this->createProducer([
            "batch_size" => 20,
            "cursor" => $cursor,
        ]);

        while ($producer2->next_sql_fragment()) {
            $sql = $producer2->get_sql_fragment();
            if ($sql !== null) {
                $fragments[] = $sql;
            }
        }

        // Verify complete SQL is valid
        $completeSQL = implode("\n", $fragments);

        // Should have both tables
        $this->assertStringContainsString("table_a", $completeSQL);
        $this->assertStringContainsString("table_b", $completeSQL);

        // Verify no syntax errors
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);
        $countA = $importPdo->query("SELECT COUNT(*) FROM table_a")->fetchColumn();
        $countB = $importPdo->query("SELECT COUNT(*) FROM table_b")->fetchColumn();

        $this->assertEquals(10, $countA);
        $this->assertEquals(10, $countB);
    }

    /**
     * Test resume after all data rows consumed but before footer.
     */
    public function testResumeBeforeFooter(): void
    {
        $this->pdo->exec("
            CREATE TABLE final_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(50)
            )
        ");

        $this->pdo->exec("INSERT INTO final_table (data) VALUES ('only_row')");

        $producer = $this->createProducer(["batch_size" => 10]);
        $fragments = [];

        // Process everything except footer
        // We'll count fragments and stop after we've seen all data
        $fragmentCount = 0;
        while ($producer->next_sql_fragment()) {
            $sql = $producer->get_sql_fragment();
            $fragments[] = $sql;
            $fragmentCount++;

            // Stop after we've emitted all data (before footer)
            // The footer is typically the last fragment
            // We'll process all but the last 1 fragment
            if ($fragmentCount >= 4) {
                // We've processed header, CREATE TABLE, table comment, INSERT
                // Next would be footer
                break;
            }
        }

        // Get cursor after all data
        $cursor = $producer->get_reentrancy_cursor();

        // Resume to get footer
        $producer2 = $this->createProducer([
            "batch_size" => 10,
            "cursor" => $cursor,
        ]);

        while ($producer2->next_sql_fragment()) {
            $sql = $producer2->get_sql_fragment();
            if ($sql !== null) {
                $fragments[] = $sql;
            }
        }

        $completeSQL = implode("\n", $fragments);

        // Should have complete, valid SQL
        $this->assertStringContainsString("SET @OLD_UNIQUE_CHECKS", $completeSQL);
        $this->assertStringContainsString("COMMIT", $completeSQL);
        $this->assertStringContainsString("only_row", $completeSQL);

        // Should be able to import
        $importPdo = $this->executeDumpInNewDatabase($completeSQL);
        $count = $importPdo->query("SELECT COUNT(*) FROM final_table")->fetchColumn();
        $this->assertEquals(1, $count);
    }
}
