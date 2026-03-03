<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/lib/url-rewrite/load.php';

class Base64ValueScannerTest extends TestCase
{
    public function testScanFindsSimpleFromBase64(): void
    {
        $value = 'hello world';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(1, FROM_BASE64('{$encoded}'), NULL);";

        $results = Base64ValueScanner::scan($sql);

        $this->assertCount(1, $results);
        $this->assertEquals($value, $results[0]['value']);
        $this->assertFalse($results[0]['is_json']);
    }

    public function testScanFindsConvertWrappedValue(): void
    {
        $value = '{"key": "value"}';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(1, CONVERT(FROM_BASE64('{$encoded}') USING utf8mb4));";

        $results = Base64ValueScanner::scan($sql);

        $this->assertCount(1, $results);
        $this->assertEquals($value, $results[0]['value']);
        $this->assertTrue($results[0]['is_json']);
    }

    public function testScanFindsMixedValueTypes(): void
    {
        $text = 'some text';
        $json = '{"url": "https://example.com"}';
        $text_enc = base64_encode($text);
        $json_enc = base64_encode($json);

        $sql = "INSERT INTO t VALUES(1, FROM_BASE64('{$text_enc}'), NULL, 42, CONVERT(FROM_BASE64('{$json_enc}') USING utf8mb4));";

        $results = Base64ValueScanner::scan($sql);

        $this->assertCount(2, $results);
        $this->assertEquals($text, $results[0]['value']);
        $this->assertFalse($results[0]['is_json']);
        $this->assertEquals($json, $results[1]['value']);
        $this->assertTrue($results[1]['is_json']);
    }

    public function testScanReturnsEmptyForNoBase64(): void
    {
        $sql = "CREATE TABLE t (id INT, name VARCHAR(255));";
        $results = Base64ValueScanner::scan($sql);
        $this->assertCount(0, $results);
    }

    public function testScanReturnsEmptyForNullAndNumeric(): void
    {
        $sql = "INSERT INTO t VALUES(1, NULL, 3.14);";
        $results = Base64ValueScanner::scan($sql);
        $this->assertCount(0, $results);
    }

    public function testScanOffsetsAreCorrect(): void
    {
        $value = 'test';
        $encoded = base64_encode($value);
        $prefix = "INSERT INTO t VALUES(1, ";
        $expr = "FROM_BASE64('{$encoded}')";
        $sql = $prefix . $expr . ", NULL);";

        $results = Base64ValueScanner::scan($sql);

        $this->assertCount(1, $results);
        $this->assertEquals(strlen($prefix), $results[0]['offset']);
        $this->assertEquals(strlen($expr), $results[0]['length']);
        $this->assertEquals($expr, substr($sql, $results[0]['offset'], $results[0]['length']));
    }

    public function testScanConvertOffsetsIncludeWrapper(): void
    {
        $value = '[]';
        $encoded = base64_encode($value);
        $prefix = "INSERT INTO t VALUES(";
        $expr = "CONVERT(FROM_BASE64('{$encoded}') USING utf8mb4)";
        $sql = $prefix . $expr . ");";

        $results = Base64ValueScanner::scan($sql);

        $this->assertCount(1, $results);
        $this->assertEquals(strlen($prefix), $results[0]['offset']);
        $this->assertEquals(strlen($expr), $results[0]['length']);
        $this->assertEquals($expr, substr($sql, $results[0]['offset'], $results[0]['length']));
    }

    public function testScanMultipleValuesInOneStatement(): void
    {
        $v1 = 'alpha';
        $v2 = 'beta';
        $v3 = 'gamma';
        $sql = sprintf(
            "INSERT INTO t VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode($v1),
            base64_encode($v2),
            base64_encode($v3)
        );

        $results = Base64ValueScanner::scan($sql);

        $this->assertCount(3, $results);
        $this->assertEquals($v1, $results[0]['value']);
        $this->assertEquals($v2, $results[1]['value']);
        $this->assertEquals($v3, $results[2]['value']);
    }

    public function testReplaceSimpleValue(): void
    {
        $old = 'old value';
        $new = 'new value';
        $encoded_old = base64_encode($old);
        $sql = "INSERT INTO t VALUES(FROM_BASE64('{$encoded_old}'));";

        $results = Base64ValueScanner::scan($sql);
        $this->assertCount(1, $results);

        $modified = Base64ValueScanner::replace(
            $sql,
            $results[0]['offset'],
            $results[0]['length'],
            $new,
            $results[0]['is_json']
        );

        $expected_encoded = base64_encode($new);
        $this->assertStringContainsString("FROM_BASE64('{$expected_encoded}')", $modified);
        $this->assertStringNotContainsString($encoded_old, $modified);
    }

    public function testReplaceConvertWrappedValue(): void
    {
        $old = '{"old": true}';
        $new = '{"new": true}';
        $encoded_old = base64_encode($old);
        $sql = "INSERT INTO t VALUES(CONVERT(FROM_BASE64('{$encoded_old}') USING utf8mb4));";

        $results = Base64ValueScanner::scan($sql);
        $this->assertCount(1, $results);

        $modified = Base64ValueScanner::replace(
            $sql,
            $results[0]['offset'],
            $results[0]['length'],
            $new,
            $results[0]['is_json']
        );

        $expected_encoded = base64_encode($new);
        $this->assertStringContainsString("CONVERT(FROM_BASE64('{$expected_encoded}') USING utf8mb4)", $modified);
    }

    public function testReplaceInReverseOrderPreservesPositions(): void
    {
        $v1 = 'first';
        $v2 = 'second';
        $sql = sprintf(
            "INSERT INTO t VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode($v1),
            base64_encode($v2)
        );

        $results = Base64ValueScanner::scan($sql);
        $this->assertCount(2, $results);

        // Replace in reverse order to preserve offsets
        $modified = $sql;
        $modified = Base64ValueScanner::replace(
            $modified,
            $results[1]['offset'],
            $results[1]['length'],
            'SECOND_NEW',
            false
        );
        $modified = Base64ValueScanner::replace(
            $modified,
            $results[0]['offset'],
            $results[0]['length'],
            'FIRST_NEW',
            false
        );

        // Verify both replacements worked
        $new_results = Base64ValueScanner::scan($modified);
        $this->assertCount(2, $new_results);
        $this->assertEquals('FIRST_NEW', $new_results[0]['value']);
        $this->assertEquals('SECOND_NEW', $new_results[1]['value']);
    }

    public function testScanHandlesEmptyString(): void
    {
        $value = '';
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(FROM_BASE64('{$encoded}'));";

        $results = Base64ValueScanner::scan($sql);

        $this->assertCount(1, $results);
        $this->assertEquals('', $results[0]['value']);
    }

    public function testScanHandlesBase64WithSpecialChars(): void
    {
        // Value that produces base64 with +, /, and = characters
        $value = str_repeat("\xff\xfe\xfd", 10);
        $encoded = base64_encode($value);
        $sql = "INSERT INTO t VALUES(FROM_BASE64('{$encoded}'));";

        $results = Base64ValueScanner::scan($sql);

        $this->assertCount(1, $results);
        $this->assertEquals($value, $results[0]['value']);
    }
}
