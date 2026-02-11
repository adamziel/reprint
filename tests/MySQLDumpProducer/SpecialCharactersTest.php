<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests MySQL dump with special characters: quotes, backslashes, newlines, etc.
 */
class SpecialCharactersTest extends MySQLDumpProducerTestBase
{
    public function testSingleQuotes(): void
    {
        $this->pdo->exec("
            CREATE TABLE quotes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO quotes (text) VALUES (?)");
        $stmt->execute(["It's a beautiful day"]);
        $stmt->execute(["She said 'hello'"]);
        $stmt->execute(["Multiple 'single' 'quotes'"]);

        $sql = $this->getDumpSQL();

        // Verify strings are base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['quotes']);
    }

    public function testDoubleQuotes(): void
    {
        $this->pdo->exec("
            CREATE TABLE quotes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                text VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO quotes (text) VALUES (?)");
        $stmt->execute(['He said "hello"']);
        $stmt->execute(['A "quoted" word']);
        $stmt->execute(['"Start and end"']);

        $sql = $this->getDumpSQL();

        // Round-trip test - the key is data integrity
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['quotes']);

        // Verify exact content
        $rows = $importPdo->query("SELECT text FROM quotes ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals('He said "hello"', $rows[0]);
        $this->assertEquals('A "quoted" word', $rows[1]);
        $this->assertEquals('"Start and end"', $rows[2]);
    }

    public function testBackslashes(): void
    {
        $this->pdo->exec("
            CREATE TABLE paths (
                id INT PRIMARY KEY AUTO_INCREMENT,
                path VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO paths (path) VALUES (?)");
        $stmt->execute(['C:\\Windows\\System32']);
        $stmt->execute(['\\network\\share\\file']);
        $stmt->execute(['escaped\\tcharacter']);
        $stmt->execute(['backslash at end\\']);

        $sql = $this->getDumpSQL();

        // Verify strings are base64-encoded
        $this->assertSQLContains('FROM_BASE64', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['paths']);

        // Verify exact content
        $rows = $importPdo->query("SELECT path FROM paths ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals('C:\\Windows\\System32', $rows[0]);
        $this->assertEquals('\\network\\share\\file', $rows[1]);
    }

    public function testNewlines(): void
    {
        $this->pdo->exec("
            CREATE TABLE multiline (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content TEXT
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO multiline (content) VALUES (?)");
        $stmt->execute(["Line 1\nLine 2"]);
        $stmt->execute(["First\nSecond\nThird"]);
        $stmt->execute(["Windows\r\nstyle"]);
        $stmt->execute(["\nLeading newline"]);
        $stmt->execute(["Trailing newline\n"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['multiline']);

        // Verify newlines are preserved
        $rows = $importPdo->query("SELECT content FROM multiline ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertStringContainsString("\n", $rows[0]);
        $this->assertEquals("Line 1\nLine 2", $rows[0]);
        $this->assertEquals("First\nSecond\nThird", $rows[1]);
    }

    public function testTabs(): void
    {
        $this->pdo->exec("
            CREATE TABLE tabbed (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO tabbed (data) VALUES (?)");
        $stmt->execute(["Column1\tColumn2\tColumn3"]);
        $stmt->execute(["\tLeading tab"]);
        $stmt->execute(["Trailing tab\t"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['tabbed']);

        $rows = $importPdo->query("SELECT data FROM tabbed ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("Column1\tColumn2\tColumn3", $rows[0]);
    }

    public function testNullByte(): void
    {
        $this->pdo->exec("
            CREATE TABLE binary_chars (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO binary_chars (data) VALUES (?)");
        $stmt->execute(["Before\x00After"]);
        $stmt->execute(["\x00Leading null"]);
        $stmt->execute(["Trailing null\x00"]);

        $sql = $this->getDumpSQL();

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['binary_chars']);
    }

    public function testMixedSpecialCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE special (
                id INT PRIMARY KEY AUTO_INCREMENT,
                content TEXT
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO special (content) VALUES (?)");

        // Combination of multiple special chars
        $stmt->execute(["It's \"quoted\" and has a newline\nAnd a backslash\\"]);
        $stmt->execute(["Tab\tthen 'quote' then \"double\" then \\backslash"]);
        $stmt->execute(["Path C:\\Users\\John's Files\\document.txt"]);
        $stmt->execute(["SQL: SELECT * FROM users WHERE name='O\\'Brien'"]);

        $sql = $this->getDumpSQL();

        // Round-trip test is the most important validation
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['special']);

        // Verify complex content is exact
        $rows = $importPdo->query("SELECT content FROM special ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("It's \"quoted\" and has a newline\nAnd a backslash\\", $rows[0]);
        $this->assertEquals("Path C:\\Users\\John's Files\\document.txt", $rows[2]);
    }

    public function testEmptyStringsVsNull(): void
    {
        $this->pdo->exec("
            CREATE TABLE empty_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                nullable_field VARCHAR(100) NULL,
                not_null_field VARCHAR(100) NOT NULL DEFAULT ''
            )
        ");

        $this->pdo->exec("
            INSERT INTO empty_test (nullable_field, not_null_field) VALUES
            (NULL, ''),
            ('', ''),
            ('text', 'text'),
            (NULL, 'text')
        ");

        $sql = $this->getDumpSQL();

        // Verify NULL vs empty string distinction
        // NULL stays as NULL, empty strings stay as ''
        $this->assertMatchesRegularExpression('/\(1,NULL,\'\'\)/', $sql);
        $this->assertMatchesRegularExpression('/\(2,\'\',\'\'\)/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['empty_test']);
    }

    public function testStringLiteralNull(): void
    {
        $this->pdo->exec("
            CREATE TABLE null_strings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(100)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO null_strings (data) VALUES (?)");
        $stmt->execute([null]);           // Actual NULL
        $stmt->execute(['NULL']);         // String "NULL"
        $stmt->execute(['null']);         // String "null"
        $stmt->execute(['']);             // Empty string
        $stmt->execute(['Not NULL']);     // String containing "NULL"

        $sql = $this->getDumpSQL();

        // Verify proper handling: actual NULL stays as NULL, string "NULL" is base64-encoded
        $this->assertMatchesRegularExpression('/\(1,NULL\)/', $sql);
        $this->assertMatchesRegularExpression('/\(2,FROM_BASE64\(/', $sql);
        $this->assertMatchesRegularExpression('/\(3,FROM_BASE64\(/', $sql);

        // Round-trip test
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $rows = $importPdo->query("SELECT data FROM null_strings ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertNull($rows[0]['data']);
        $this->assertEquals('NULL', $rows[1]['data']);
        $this->assertEquals('null', $rows[2]['data']);
        $this->assertEquals('', $rows[3]['data']);
        $this->assertEquals('Not NULL', $rows[4]['data']);
    }

    public function testSQLInjectionStrings(): void
    {
        $this->pdo->exec("
            CREATE TABLE injection_test (
                id INT PRIMARY KEY AUTO_INCREMENT,
                malicious_input TEXT
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO injection_test (malicious_input) VALUES (?)");

        // Various SQL injection attempts (as data, not actual injection)
        $stmt->execute(["'; DROP TABLE users; --"]);
        $stmt->execute(["1' OR '1'='1"]);
        $stmt->execute(["admin'--"]);
        $stmt->execute(["' UNION SELECT * FROM passwords --"]);

        $sql = $this->getDumpSQL();

        // These should all be safely escaped
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['injection_test']);

        // Verify the malicious strings are stored as plain text
        $rows = $importPdo->query("SELECT malicious_input FROM injection_test ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertEquals("'; DROP TABLE users; --", $rows[0]);
        $this->assertEquals("1' OR '1'='1", $rows[1]);
    }

    public function testControlCharacters(): void
    {
        $this->pdo->exec("
            CREATE TABLE control_chars (
                id INT PRIMARY KEY AUTO_INCREMENT,
                data VARCHAR(200)
            )
        ");

        $stmt = $this->pdo->prepare("INSERT INTO control_chars (data) VALUES (?)");

        // Various control characters
        $stmt->execute(["Bell\x07"]);           // BEL
        $stmt->execute(["Backspace\x08"]);      // BS
        $stmt->execute(["Form feed\x0C"]);      // FF
        $stmt->execute(["Carriage return\r"]);  // CR
        $stmt->execute(["Escape\x1B"]);         // ESC

        $sql = $this->getDumpSQL();

        // Round-trip test - control chars should be preserved
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['control_chars']);
    }
}
