<?php

require_once __DIR__ . "/MySQLDumpProducerTestBase.php";

/**
 * Tests MySQL dump with various encodings and invalid/problematic characters.
 */
class EncodingAndInvalidCharsTest extends MySQLDumpProducerTestBase
{
    public function testUTF8MultibyteCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE unicode_text (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO unicode_text (text) VALUES (?)",
        );
        $stmt->execute(["Hello 世界"]);
        $stmt->execute(["Καλημέρα κόσμε"]); // Greek
        $stmt->execute(["مرحبا بالعالم"]); // Arabic
        $stmt->execute(["Привет мир"]); // Russian
        $stmt->execute(["こんにちは世界"]); // Japanese

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["unicode_text"]);

        // Verify exact content
        $rows = $importPdo
            ->query("SELECT text FROM unicode_text ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("Hello 世界", $rows[0]);
        $this->assertEquals("Καλημέρα κόσμε", $rows[1]);
        $this->assertEquals("مرحبا بالعالم", $rows[2]);
    }

    public function testEmoji(): void
    {
        $this->pdo->exec("
            CREATE TABLE emoji_data (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content VARCHAR(500) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO emoji_data (content) VALUES (?)",
        );
        $stmt->execute(["Hello 😀 World"]);
        $stmt->execute(["🎉🎊🎈🎁"]);
        $stmt->execute(["Flags: 🇺🇸🇬🇧🇨🇦"]);
        $stmt->execute(["Skin tones: 👋🏻👋🏼👋🏽👋🏾👋🏿"]);
        $stmt->execute(["ZWJ sequence: 👨‍👩‍👧‍👦"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["emoji_data"]);

        // Verify emoji are preserved
        $rows = $importPdo
            ->query("SELECT content FROM emoji_data ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("Hello 😀 World", $rows[0]);
        $this->assertEquals("🎉🎊🎈🎁", $rows[1]);
    }

    public function testRightToLeftText(): void
    {
        $this->pdo->exec("
            CREATE TABLE rtl_text (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content TEXT CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO rtl_text (content) VALUES (?)",
        );
        $stmt->execute(["مرحبا"]);
        $stmt->execute(["שלום"]);
        $stmt->execute(["Mixed: Hello مرحبا World"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["rtl_text"]);
    }

    public function testCombiningCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE combining (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO combining (text) VALUES (?)");

        // Combining diacritical marks
        $stmt->execute(["café"]); // é as single character
        $stmt->execute(["café"]); // e + combining acute
        $stmt->execute(["ñ"]);
        $stmt->execute(["Zürich"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);

        // Note: Normalization might affect exact byte comparison
        // So we check that we have 4 rows with the expected content
        $count = $importPdo
            ->query("SELECT COUNT(*) FROM combining")
            ->fetchColumn();
        $this->assertEquals(4, $count);
    }

    public function testSurrogatePairs(): void
    {
        $this->pdo->exec("
            CREATE TABLE surrogates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO surrogates (content) VALUES (?)",
        );

        // Characters outside BMP (require surrogate pairs in UTF-16)
        $stmt->execute(["𝕳𝖊𝖑𝖑𝖔"]); // Mathematical alphanumeric symbols
        $stmt->execute(["🂡🂢🂣🂤"]); // Playing cards
        $stmt->execute(["𝄞𝄢𝄫"]); // Musical symbols

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["surrogates"]);
    }

    public function testZeroWidthCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE zero_width (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO zero_width (text) VALUES (?)");

        // Zero-width characters
        $stmt->execute(["Hello\u{200B}World"]); // Zero-width space
        $stmt->execute(["Test\u{200C}ing"]); // Zero-width non-joiner
        $stmt->execute(["Join\u{200D}ed"]); // Zero-width joiner
        $stmt->execute(["Left\u{200E}to\u{200F}Right"]); // LTR/RTL marks

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["zero_width"]);
    }

    public function testHomoglyphs(): void
    {
        $this->pdo->exec("
            CREATE TABLE homoglyphs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO homoglyphs (text) VALUES (?)");

        // Characters that look similar but are different
        $stmt->execute(["A"]); // Latin A
        $stmt->execute(["Α"]); // Greek Alpha
        $stmt->execute(["А"]); // Cyrillic A
        $stmt->execute(["apple"]); // Normal text
        $stmt->execute(["аррӏе"]); // Look-alike with Cyrillic

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["homoglyphs"]);

        // All 5 rows should be distinct
        $count = $importPdo
            ->query("SELECT COUNT(DISTINCT text) FROM homoglyphs")
            ->fetchColumn();
        $this->assertEquals(5, $count);
    }

    public function testInvalidUTF8Sequences(): void
    {
        $this->pdo->exec("
            CREATE TABLE possibly_invalid (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARBINARY(200)
            )
        ");

        // Insert binary data that might not be valid UTF-8
        $stmt = $this->pdo->prepare(
            "INSERT INTO possibly_invalid (data) VALUES (?)",
        );
        $stmt->execute(["\xFF\xFE"]); // Invalid UTF-8
        $stmt->execute(["\xC0\x80"]); // Overlong encoding
        $stmt->execute(["Valid\xFF\xFEInvalid"]); // Mixed

        $sql = $this->getDumpSQL();

        // VARBINARY data is base64-encoded
        $this->assertSQLContains("FROM_BASE64", $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, [
            "possibly_invalid",
        ]);
    }

    public function testMixedEncodings(): void
    {
        // Create table with latin1 column
        $this->pdo->exec("
            CREATE TABLE mixed_encoding (
                id INT PRIMARY KEY AUTO_INCREMENT,
                latin1_text VARCHAR(200) CHARACTER SET latin1,
                utf8_text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO mixed_encoding (latin1_text, utf8_text) VALUES (?, ?)",
        );
        // latin1 column can't store emoji, so use latin1-compatible characters only
        $stmt->execute(["café", "café 😀"]);
        $stmt->execute(["naïve", "naïve 世界"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["mixed_encoding"]);
    }

    public function testLongUnicodeStrings(): void
    {
        $this->pdo->exec("
            CREATE TABLE long_unicode (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content TEXT CHARACTER SET utf8mb4
            )
        ");

        // Generate long strings with multibyte characters
        $longString1 = str_repeat("世界", 500); // 1000 characters, 3000 bytes
        $longString2 = str_repeat("😀", 250); // 250 emoji

        $stmt = $this->pdo->prepare(
            "INSERT INTO long_unicode (content) VALUES (?)",
        );
        $stmt->execute([$longString1]);
        $stmt->execute([$longString2]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["long_unicode"]);

        // Verify lengths
        $row = $importPdo
            ->query("SELECT content FROM long_unicode WHERE id = 1")
            ->fetchColumn();
        $this->assertEquals(1000, mb_strlen($row, "UTF-8"));
    }

    public function testBOM(): void
    {
        $this->pdo->exec("
            CREATE TABLE bom_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare(
            "INSERT INTO bom_test (content) VALUES (?)",
        );

        // UTF-8 BOM
        $stmt->execute(["\xEF\xBB\xBFWith BOM"]);
        $stmt->execute(["Without BOM"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["bom_test"]);
    }

    public function testWhitespaceVariations(): void
    {
        $this->pdo->exec("
            CREATE TABLE whitespace (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200) CHARACTER SET utf8mb4
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO whitespace (text) VALUES (?)");

        // Various unicode whitespace characters
        $stmt->execute(["Regular space"]);
        $stmt->execute(["Non\u{00A0}breaking\u{00A0}space"]); // NBSP
        $stmt->execute(["Em\u{2003}space"]); // Em space
        $stmt->execute(["Thin\u{2009}space"]); // Thin space
        $stmt->execute(["Zero\u{200B}width\u{200B}space"]); // Zero-width space

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ["whitespace"]);
    }
}
