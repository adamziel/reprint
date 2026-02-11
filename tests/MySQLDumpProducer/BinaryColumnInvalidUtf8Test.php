<?php

require_once __DIR__ . '/MySQLDumpProducerTestBase.php';

/**
 * Tests that BLOB/VARBINARY columns containing invalid UTF-8 sequences
 * survive a dump-and-import round trip without data corruption.
 *
 * Invalid UTF-8 is common in real-world databases: legacy data migrated
 * from latin1, serialized PHP objects, or raw file bytes stored in BLOBs.
 * The producer must hex-encode these values so MySQL can reimport them
 * without charset interpretation mangling the bytes.
 */
class BinaryColumnInvalidUtf8Test extends MySQLDumpProducerTestBase
{
    /**
     * Malformed UTF-8 leader bytes with no continuation: 0xC0, 0xFE, 0xFF
     * are never valid in any UTF-8 sequence. The dump must preserve them.
     */
    public function testIsolatedLeaderBytes(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, data BLOB)");

        $values = [
            "\xC0",            // overlong 2-byte leader, alone
            "\xFE",            // never valid in UTF-8
            "\xFF",            // never valid in UTF-8
            "\xC0\xAF",       // overlong encoding of '/'
            "abc\xFEdef",     // invalid byte embedded in ASCII
        ];

        $stmt = $this->pdo->prepare("INSERT INTO t (id, data) VALUES (?, ?)");
        foreach ($values as $i => $val) {
            $stmt->execute([$i + 1, $val]);
        }

        $sql = $this->getDumpSQL();

        // Binary columns use base64 encoding
        $this->assertSQLContains('FROM_BASE64', $sql);

        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);

        // Verify each value byte-for-byte
        $rows = $importPdo->query("SELECT data FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($values as $i => $expected) {
            $this->assertSame($expected, $rows[$i], "Byte mismatch at row " . ($i + 1));
        }
    }

    /**
     * Truncated multi-byte sequences: a 3-byte leader (0xE0) followed by
     * only one continuation byte, or a 4-byte leader (0xF0) followed by
     * two instead of three.
     */
    public function testTruncatedMultiByteSequences(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, data BLOB)");

        $values = [
            "\xE0\x80",             // 3-byte sequence missing last byte
            "\xF0\x90\x80",         // 4-byte sequence missing last byte
            "hello\xE0world",       // orphan leader mid-string
            "\xF4\x90\x80\x80",    // above U+10FFFF (out of Unicode range)
        ];

        $stmt = $this->pdo->prepare("INSERT INTO t (id, data) VALUES (?, ?)");
        foreach ($values as $i => $val) {
            $stmt->execute([$i + 1, $val]);
        }

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT data FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($values as $i => $expected) {
            $this->assertSame($expected, $rows[$i], "Byte mismatch at row " . ($i + 1));
        }
    }

    /**
     * A VARBINARY column with a mix of valid UTF-8, null bytes, and
     * broken sequences interleaved. This is the kind of mess you find
     * in serialized PHP data or corrupted text fields.
     */
    public function testMixedValidAndInvalidUtf8InVarbinary(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY, data VARBINARY(500))");

        $values = [
            // Valid emoji followed by broken sequence followed by ASCII
            "\xF0\x9F\x98\x80" . "\xC0\xAF" . "ok",
            // Null bytes sandwiching an invalid leader
            "\x00\xFF\x00",
            // Alternating valid/invalid: é (valid 2-byte) then lone continuation
            "\xC3\xA9" . "\x80" . "\xC3\xA9",
            // All 256 byte values in order — the ultimate round-trip stress test
            implode('', array_map('chr', range(0, 255))),
        ];

        $stmt = $this->pdo->prepare("INSERT INTO t (id, data) VALUES (?, ?)");
        foreach ($values as $i => $val) {
            $stmt->execute([$i + 1, $val]);
        }

        $sql = $this->getDumpSQL();
        $importPdo = $this->executeDumpInNewDatabase($sql);
        $this->assertDatabasesEqual($this->pdo, $importPdo, ['t']);

        $rows = $importPdo->query("SELECT HEX(data) FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($values as $i => $expected) {
            $this->assertSame(
                strtoupper(bin2hex($expected)),
                $rows[$i],
                "Hex mismatch at row " . ($i + 1)
            );
        }
    }

    /**
     * Cursor-based reentrancy: pause and resume mid-table while exporting
     * binary data with invalid UTF-8. The cursor itself is JSON, so any
     * stray bytes in the accumulated row buffer must not corrupt it.
     */
    public function testReentrancyWithInvalidUtf8Binary(): void
    {
        $this->pdo->exec("CREATE TABLE t (id INT PRIMARY KEY AUTO_INCREMENT, data BLOB)");

        $values = [];
        $stmt = $this->pdo->prepare("INSERT INTO t (data) VALUES (?)");
        for ($i = 0; $i < 20; $i++) {
            // Each row: some invalid UTF-8 leader bytes + random binary
            $val = "\xFE\xFF\xC0" . random_bytes(50);
            $values[] = $val;
            $stmt->execute([$val]);
        }

        // batch_size=3 forces multiple pauses within the 20 rows
        $options = ['batch_size' => 3];
        $producer = $this->createProducer($options);
        $allFragments = [];

        $iterations = 0;
        while (!$producer->is_finished() && $iterations < 100) {
            // Consume 2 fragments, then save/restore cursor
            $count = 0;
            while ($count < 2 && $producer->next_sql_fragment()) {
                $frag = $producer->get_sql_fragment();
                if ($frag !== null) {
                    $allFragments[] = $frag;
                }
                $count++;
            }
            if ($producer->is_finished()) {
                break;
            }

            $cursor = $producer->get_reentrancy_cursor();
            $this->assertNotNull($cursor, "Cursor must not be null mid-export");

            $options['cursor'] = $cursor;
            $producer = $this->createProducer($options);
            $iterations++;
        }

        $sql = implode("\n", $allFragments);
        $importPdo = $this->executeDumpInNewDatabase($sql);

        $rows = $importPdo->query("SELECT data FROM t ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(20, $rows);
        foreach ($values as $i => $expected) {
            $this->assertSame($expected, $rows[$i], "Binary mismatch at row " . ($i + 1));
        }
    }
}
