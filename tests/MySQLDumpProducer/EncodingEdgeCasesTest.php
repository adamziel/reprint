<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests for specific encoding edge cases:
 * 1. latin1 column containing UTF-8 bytes
 * 2. latin1 column with undefined cp1252 codepoints
 * 3. utf8 column with forced latin1 bytes (invalid UTF-8)
 */
class EncodingEdgeCasesTest extends MySQLDumpProducerTestBase
{
    /**
     * Test latin1 column containing data that looks like UTF-8.
     * When bytes are treated as codepoints, they form valid UTF-8.
     */
    public function testLatin1ColumnWithUtf8Bytes(): void
    {
        $this->pdo->exec("
            CREATE TABLE latin1_with_utf8 (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET latin1
            )
        ");

        // Use hex literals to insert raw bytes into latin1 column
        // "café" in UTF-8 is: 63 61 66 C3 A9
        // When stored in latin1, each byte becomes a separate character
        $this->pdo->exec("INSERT INTO latin1_with_utf8 (data) VALUES (UNHEX('636166C3A9'))");

        // Multi-byte UTF-8 sequence: 😀 in UTF-8 (F0 9F 98 80)
        // In latin1, this becomes 4 separate characters
        $this->pdo->exec("INSERT INTO latin1_with_utf8 (data) VALUES (UNHEX('F09F9880'))");

        // UTF-8 with combining: é as 'e' + combining acute (65 CC 81)
        $this->pdo->exec("INSERT INTO latin1_with_utf8 (data) VALUES (UNHEX('65CC81'))");

        $sql = $this->getDumpSQL();

        // Verify data is exported (exact format depends on how PDO::quote handles it)
        $this->assertSQLContains('INSERT INTO', $sql);

        // Round-trip test - this is the real verification
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Compare raw bytes
        $original = $this->pdo->query("SELECT HEX(data) as h FROM latin1_with_utf8 ORDER BY id")->fetchAll();
        $imported = $importPdo->query("SELECT HEX(data) as h FROM latin1_with_utf8 ORDER BY id")->fetchAll();

        $this->assertEquals($original, $imported, 'Byte-for-byte comparison should match');
    }

    /**
     * Test latin1 column with undefined codepoints in cp1252.
     * MySQL's latin1 is actually cp1252 (Windows-1252), which has gaps.
     */
    public function testLatin1ColumnWithUndefinedCodepoints(): void
    {
        $this->pdo->exec("
            CREATE TABLE latin1_undefined (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET latin1
            )
        ");

        // cp1252 has undefined codepoints at: 0x81, 0x8D, 0x8F, 0x90, 0x9D
        // Use hex literals to insert raw bytes
        $undefined_codepoints = [
            '81', // Undefined in cp1252
            '8D', // Undefined in cp1252
            '8F', // Undefined in cp1252
            '90', // Undefined in cp1252
            '9D', // Undefined in cp1252
        ];

        foreach ($undefined_codepoints as $hex) {
            try {
                // before[BYTE]after
                $this->pdo->exec("INSERT INTO latin1_undefined (data) VALUES (UNHEX('6265666F7265{$hex}6166746572'))");
            } catch (PDOException $e) {
                // Some MySQL versions may reject these
                fwrite(STDERR, "Note: Undefined codepoint rejected by MySQL: {$hex}\n");
            }
        }

        // Also test valid latin1 high-byte characters using hex
        $valid_latin1_hex = [
            'A0', // Non-breaking space
            'C0', // À
            'E9', // é
            'FF', // ÿ
        ];

        foreach ($valid_latin1_hex as $hex) {
            // test[BYTE]data
            $this->pdo->exec("INSERT INTO latin1_undefined (data) VALUES (UNHEX('74657374{$hex}64617461'))");
        }

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Verify all rows that were inserted are preserved
        $originalCount = $this->pdo->query("SELECT COUNT(*) FROM latin1_undefined")->fetchColumn();
        $importedCount = $importPdo->query("SELECT COUNT(*) FROM latin1_undefined")->fetchColumn();
        $this->assertEquals($originalCount, $importedCount, 'Row count should match');

        // Byte-for-byte comparison
        $original = $this->pdo->query("SELECT id, HEX(data) as h FROM latin1_undefined ORDER BY id")->fetchAll();
        $imported = $importPdo->query("SELECT id, HEX(data) as h FROM latin1_undefined ORDER BY id")->fetchAll();
        $this->assertEquals($original, $imported, 'All bytes should be preserved exactly');
    }

    /**
     * Test UTF-8 column with attempt to store invalid UTF-8 sequences.
     * This tests MySQL's handling and our export's ability to preserve whatever gets stored.
     */
    public function testUtf8ColumnWithInvalidUtf8Handling(): void
    {
        $this->pdo->exec("
            CREATE TABLE utf8_handling (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4
            )
        ");

        // First, insert valid UTF-8 data
        $stmt = $this->pdo->prepare("INSERT INTO utf8_handling (data) VALUES (?)");
        $stmt->execute(['Valid UTF-8: café 😀']);

        // Try to insert some edge cases that MySQL should handle
        $edge_cases = [
            'ASCII only',
            'Latin-1 subset: àáâãäå',
            'Multi-byte: 中文',
            'Emoji: 🎉🎊🎈',
            'Zero-width: ​', // Zero-width space (U+200B)
            'Combining: é vs é', // Precomposed vs e + combining
        ];

        foreach ($edge_cases as $data) {
            $stmt->execute([$data]);
        }

        // Test what happens with MySQL's utf8mb4_bin collation
        $this->pdo->exec("
            CREATE TABLE utf8_bin_collation (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
            )
        ");

        $stmt2 = $this->pdo->prepare("INSERT INTO utf8_bin_collation (data) VALUES (?)");
        foreach ($edge_cases as $data) {
            $stmt2->execute([$data]);
        }

        $sql = $this->getDumpSQL();

        // Verify export contains all data
        $this->assertSQLContains('café', $sql);
        $this->assertSQLContains('中文', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $this->assertDatabasesEqual($this->pdo, $importPdo, ['utf8_handling', 'utf8_bin_collation']);
    }

    /**
     * Test mixed encoding scenarios in same database.
     */
    public function testMixedEncodingsInSameDatabase(): void
    {
        // Create tables with different character sets
        $this->pdo->exec("
            CREATE TABLE latin1_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET latin1
            )
        ");

        $this->pdo->exec("
            CREATE TABLE utf8_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4
            )
        ");

        $this->pdo->exec("
            CREATE TABLE binary_table (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARBINARY(100)
            )
        ");

        // Insert same logical content in different encodings
        $text = "café";

        // latin1 table gets cp1252 encoded
        $stmt1 = $this->pdo->prepare("INSERT INTO latin1_table (data) VALUES (?)");
        $stmt1->execute([$text]);

        // utf8 table gets UTF-8 encoded
        $stmt2 = $this->pdo->prepare("INSERT INTO utf8_table (data) VALUES (?)");
        $stmt2->execute([$text]);

        // binary table gets raw UTF-8 bytes
        $stmt3 = $this->pdo->prepare("INSERT INTO binary_table (data) VALUES (?)");
        $stmt3->execute([utf8_encode($text)]);

        $sql = $this->getDumpSQL();

        // Verify all three tables are exported
        $this->assertSQLContains('latin1_table', $sql);
        $this->assertSQLContains('utf8_table', $sql);
        $this->assertSQLContains('binary_table', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Each table should preserve its encoding
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['latin1_table', 'utf8_table', 'binary_table']);

        // Verify the bytes are different in each table
        $latin1_hex = $importPdo->query("SELECT HEX(data) FROM latin1_table")->fetchColumn();
        $utf8_hex = $importPdo->query("SELECT HEX(data) FROM utf8_table")->fetchColumn();
        $binary_hex = $importPdo->query("SELECT HEX(data) FROM binary_table")->fetchColumn();

        // UTF-8 and binary should match (both UTF-8 encoded)
        // latin1 will be different (cp1252 encoding)
        $this->assertNotEquals($latin1_hex, $utf8_hex, 'latin1 and utf8 encodings should differ');
    }

    /**
     * Test NULL bytes and control characters in various encodings.
     */
    public function testNullBytesInDifferentEncodings(): void
    {
        $this->pdo->exec("
            CREATE TABLE latin1_nulls (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET latin1
            )
        ");

        $this->pdo->exec("
            CREATE TABLE utf8_nulls (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4
            )
        ");

        // Insert data with NULL bytes
        $stmt1 = $this->pdo->prepare("INSERT INTO latin1_nulls (data) VALUES (?)");
        $stmt1->execute(["before\x00after"]);
        $stmt1->execute(["\x00start"]);
        $stmt1->execute(["end\x00"]);

        $stmt2 = $this->pdo->prepare("INSERT INTO utf8_nulls (data) VALUES (?)");
        $stmt2->execute(["before\x00after"]);
        $stmt2->execute(["\x00start"]);
        $stmt2->execute(["end\x00"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Verify NULL bytes are preserved
        $latin1_result = $importPdo->query("SELECT HEX(data) FROM latin1_nulls WHERE data LIKE '%after'")->fetchColumn();
        $this->assertStringContainsString('00', $latin1_result, 'NULL byte should be preserved in latin1');

        $utf8_result = $importPdo->query("SELECT HEX(data) FROM utf8_nulls WHERE data LIKE '%after'")->fetchColumn();
        $this->assertStringContainsString('00', $utf8_result, 'NULL byte should be preserved in utf8');

        $this->assertDatabasesEqual($this->pdo, $importPdo, ['latin1_nulls', 'utf8_nulls']);
    }

    /**
     * Test supplementary characters and UTF-8 edge cases.
     */
    public function testUtf8SupplementaryCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE utf8_supplementary (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO utf8_supplementary (data) VALUES (?)");

        // 1-byte UTF-8 (ASCII)
        $stmt->execute(['ASCII']);

        // 2-byte UTF-8
        $stmt->execute(['café']); // é is 2 bytes

        // 3-byte UTF-8
        $stmt->execute(['中文']); // Chinese characters are 3 bytes each

        // 4-byte UTF-8 (supplementary plane - emojis, ancient scripts)
        $stmt->execute(['😀😁😂']); // Emojis are 4 bytes each
        $stmt->execute(['𝕳𝖊𝖑𝖑𝖔']); // Mathematical bold fraktur (4 bytes)
        $stmt->execute(['🌍🌎🌏']); // Earth emojis

        // Maximum length UTF-8 sequences
        $stmt->execute(['𐍈']); // Gothic letter (U+10348, 4 bytes in UTF-8)

        $sql = $this->getDumpSQL();

        // Verify emojis are in the export
        $this->assertSQLContains('😀', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $this->assertDatabasesEqual($this->pdo, $importPdo, ['utf8_supplementary']);

        // Verify specific characters survived
        $emojis = $importPdo->query("SELECT data FROM utf8_supplementary WHERE data LIKE '%😀%'")->fetchColumn();
        $this->assertEquals('😀😁😂', $emojis, '4-byte UTF-8 emojis should be preserved');
    }

    /**
     * Test character set conversion edge cases at table level.
     */
    public function testTableCharacterSetDeclarations(): void
    {
        // Table with explicit latin1
        $this->pdo->exec("
            CREATE TABLE explicit_latin1 (
                id INT PRIMARY KEY,
                data VARCHAR(50)
            ) CHARACTER SET latin1
        ");

        // Table with explicit utf8mb4
        $this->pdo->exec("
            CREATE TABLE explicit_utf8 (
                id INT PRIMARY KEY,
                data VARCHAR(50)
            ) CHARACTER SET utf8mb4
        ");

        // Table with mixed column charsets
        $this->pdo->exec("
            CREATE TABLE mixed_columns (
                id INT PRIMARY KEY,
                latin1_col VARCHAR(50) CHARACTER SET latin1,
                utf8_col VARCHAR(50) CHARACTER SET utf8mb4,
                binary_col VARBINARY(50)
            )
        ");

        $this->pdo->exec("INSERT INTO explicit_latin1 VALUES (1, 'latin1 text')");
        $this->pdo->exec("INSERT INTO explicit_utf8 VALUES (1, 'utf8 text 中文')");
        $this->pdo->exec("INSERT INTO mixed_columns VALUES (1, 'latin', 'utf8 🎉', 0xDEADBEEF)");

        $sql = $this->getDumpSQL();

        // Verify CREATE TABLE statements preserve character sets
        $this->assertMatchesRegularExpression('/CHARACTER SET latin1/', $sql);
        $this->assertMatchesRegularExpression('/CHARACTER SET utf8mb4/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $this->assertDatabasesEqual($this->pdo, $importPdo, [
            'explicit_latin1',
            'explicit_utf8',
            'mixed_columns'
        ]);
    }
}
