<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/lib/PhpSerializedStringWalker.php';

class PhpSerializedStringWalkerTest extends TestCase
{
    /**
     * Identity callback — returns the value unchanged.
     */
    private function identity(): callable
    {
        return fn(string $v): string => $v;
    }

    /**
     * Uppercasing callback — makes changes easy to spot.
     */
    private function toUpper(): callable
    {
        return fn(string $v): string => strtoupper($v);
    }

    // ---------------------------------------------------------------
    // Scalar types passed through unchanged
    // ---------------------------------------------------------------

    public function testIntegerPassthrough(): void
    {
        $input = serialize(42);
        $this->assertSame($input, PhpSerializedStringWalker::walk_strings($input, $this->identity()));
    }

    public function testNegativeIntegerPassthrough(): void
    {
        $input = serialize(-7);
        $this->assertSame($input, PhpSerializedStringWalker::walk_strings($input, $this->identity()));
    }

    public function testDoublePassthrough(): void
    {
        $input = serialize(3.14);
        $this->assertSame($input, PhpSerializedStringWalker::walk_strings($input, $this->identity()));
    }

    public function testBooleanTruePassthrough(): void
    {
        $input = serialize(true);
        $this->assertSame($input, PhpSerializedStringWalker::walk_strings($input, $this->identity()));
    }

    public function testBooleanFalsePassthrough(): void
    {
        $input = serialize(false);
        $this->assertSame($input, PhpSerializedStringWalker::walk_strings($input, $this->identity()));
    }

    public function testNullPassthrough(): void
    {
        $input = serialize(null);
        $this->assertSame($input, PhpSerializedStringWalker::walk_strings($input, $this->identity()));
    }

    // ---------------------------------------------------------------
    // String callback: same-length and different-length values
    // ---------------------------------------------------------------

    public function testStringCallbackSameLength(): void
    {
        $input = serialize('hello');
        $result = PhpSerializedStringWalker::walk_strings($input, fn($v) => 'HELLO');
        $this->assertSame(serialize('HELLO'), $result);
        $this->assertSame('HELLO', unserialize($result));
    }

    public function testStringCallbackDifferentLength(): void
    {
        $input = serialize('hi');
        $result = PhpSerializedStringWalker::walk_strings($input, fn($v) => 'hello world');
        $this->assertSame(serialize('hello world'), $result);
        $this->assertSame('hello world', unserialize($result));
    }

    public function testStringCallbackShorterValue(): void
    {
        $input = serialize('hello world');
        $result = PhpSerializedStringWalker::walk_strings($input, fn($v) => 'hi');
        $this->assertSame(serialize('hi'), $result);
        $this->assertSame('hi', unserialize($result));
    }

    // ---------------------------------------------------------------
    // Callback called for values, NOT for array keys or property names
    // ---------------------------------------------------------------

    public function testCallbackNotCalledForArrayKeys(): void
    {
        $called_with = [];
        $input = serialize(['key1' => 'value1', 'key2' => 'value2']);
        PhpSerializedStringWalker::walk_strings($input, function (string $v) use (&$called_with): string {
            $called_with[] = $v;
            return $v;
        });

        $this->assertSame(['value1', 'value2'], $called_with);
    }

    public function testCallbackNotCalledForIntegerKeys(): void
    {
        $called_with = [];
        $input = serialize(['alpha', 'beta']);
        PhpSerializedStringWalker::walk_strings($input, function (string $v) use (&$called_with): string {
            $called_with[] = $v;
            return $v;
        });

        $this->assertSame(['alpha', 'beta'], $called_with);
    }

    // ---------------------------------------------------------------
    // Arrays
    // ---------------------------------------------------------------

    public function testEmptyArray(): void
    {
        $input = serialize([]);
        $this->assertSame($input, PhpSerializedStringWalker::walk_strings($input, $this->identity()));
    }

    public function testNumericKeyedArray(): void
    {
        $input = serialize(['a', 'b', 'c']);
        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $this->assertSame(['A', 'B', 'C'], unserialize($result));
    }

    public function testStringKeyedArray(): void
    {
        $input = serialize(['foo' => 'bar', 'baz' => 'qux']);
        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $unserialized = unserialize($result);
        // Keys preserved, values uppercased
        $this->assertSame(['foo' => 'BAR', 'baz' => 'QUX'], $unserialized);
    }

    public function testNestedArray(): void
    {
        $input = serialize([
            'level1' => [
                'level2' => 'deep_value',
            ],
            'flat' => 'shallow',
        ]);
        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $unserialized = unserialize($result);
        $this->assertSame('DEEP_VALUE', $unserialized['level1']['level2']);
        $this->assertSame('SHALLOW', $unserialized['flat']);
    }

    public function testArrayWithMixedValueTypes(): void
    {
        $input = serialize([
            'str' => 'hello',
            'int' => 42,
            'bool' => true,
            'null' => null,
            'float' => 1.5,
        ]);
        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $unserialized = unserialize($result);
        $this->assertSame('HELLO', $unserialized['str']);
        $this->assertSame(42, $unserialized['int']);
        $this->assertTrue($unserialized['bool']);
        $this->assertNull($unserialized['null']);
        $this->assertSame(1.5, $unserialized['float']);
    }

    // ---------------------------------------------------------------
    // Objects
    // ---------------------------------------------------------------

    public function testStdClassObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->url = 'https://example.com';
        $input = serialize($obj);

        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $unserialized = unserialize($result);
        $this->assertSame('TEST', $unserialized->name);
        $this->assertSame('HTTPS://EXAMPLE.COM', $unserialized->url);
    }

    public function testCallbackNotCalledForObjectPropertyNames(): void
    {
        $obj = new \stdClass();
        $obj->propname = 'propvalue';
        $input = serialize($obj);

        $called_with = [];
        PhpSerializedStringWalker::walk_strings($input, function (string $v) use (&$called_with): string {
            $called_with[] = $v;
            return $v;
        });

        $this->assertSame(['propvalue'], $called_with);
    }

    public function testNestedObjectInsideArray(): void
    {
        $obj = new \stdClass();
        $obj->value = 'inner';
        $input = serialize(['wrapper' => $obj]);

        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $unserialized = unserialize($result);
        $this->assertSame('INNER', $unserialized['wrapper']->value);
    }

    // ---------------------------------------------------------------
    // Private/protected property null-byte visibility markers
    // ---------------------------------------------------------------

    public function testPrivatePropertyNullByteMarkers(): void
    {
        // Manually construct serialized data with private property null-byte markers.
        // Private properties are stored as: \0ClassName\0propname
        // This is s:14:"\0MyClass\0secret"; as the property name.
        $input = 'O:7:"MyClass":1:{s:15:"' . "\0" . 'MyClass' . "\0" . 'secret";s:5:"hello";}';

        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $this->assertNotFalse($result);
        // The property name should be unchanged, the value should be uppercased
        $this->assertStringContainsString('HELLO', $result);
        // Property name should still contain null bytes
        $this->assertStringContainsString("\0" . 'MyClass' . "\0" . 'secret', $result);
    }

    public function testProtectedPropertyNullByteMarkers(): void
    {
        // Protected properties are stored as: \0*\0propname
        $input = 'O:7:"MyClass":1:{s:9:"' . "\0" . '*' . "\0" . 'hidden";s:3:"val";}';

        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $this->assertNotFalse($result);
        $this->assertStringContainsString('VAL', $result);
        $this->assertStringContainsString("\0" . '*' . "\0" . 'hidden', $result);
    }

    // ---------------------------------------------------------------
    // References
    // ---------------------------------------------------------------

    public function testValueReferencePassthrough(): void
    {
        // r:N; reference
        $input = 'a:2:{i:0;s:5:"hello";i:1;r:2;}';
        $result = PhpSerializedStringWalker::walk_strings($input, $this->identity());
        $this->assertSame($input, $result);
    }

    public function testPointerReferencePassthrough(): void
    {
        // R:N; reference
        $input = 'a:2:{i:0;s:5:"hello";i:1;R:2;}';
        $result = PhpSerializedStringWalker::walk_strings($input, $this->identity());
        $this->assertSame($input, $result);
    }

    // ---------------------------------------------------------------
    // Custom serializable (C:)
    // ---------------------------------------------------------------

    public function testCustomSerializablePassthrough(): void
    {
        $input = 'C:7:"MyClass":11:{hello world}';
        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        // Custom serializable payload is opaque — passed through unchanged
        $this->assertSame($input, $result);
    }

    // ---------------------------------------------------------------
    // URL rewriting: URL replaced and s:N: updated
    // ---------------------------------------------------------------

    public function testUrlRewritingUpdatesLengthPrefix(): void
    {
        $data = ['siteurl' => 'https://old-site.com'];
        $input = serialize($data);

        $result = PhpSerializedStringWalker::walk_strings($input, function (string $v): string {
            return str_replace('https://old-site.com', 'https://new-site.example.com', $v);
        });

        $this->assertNotFalse($result);
        $unserialized = unserialize($result);
        $this->assertSame('https://new-site.example.com', $unserialized['siteurl']);
        // Verify the s:N: prefix is correct (the new URL is longer)
        $this->assertStringContainsString('s:28:', $result);
    }

    public function testUrlRewritingInSerializedString(): void
    {
        $input = serialize('https://old-site.com/page');

        $result = PhpSerializedStringWalker::walk_strings($input, function (string $v): string {
            return str_replace('https://old-site.com', 'https://new-site.com', $v);
        });

        $this->assertNotFalse($result);
        $this->assertSame('https://new-site.com/page', unserialize($result));
    }

    // ---------------------------------------------------------------
    // Round-trip: unserialize(walk_strings(serialize($data))) matches
    // ---------------------------------------------------------------

    public function testRoundTripWithComplexStructure(): void
    {
        $data = [
            'settings' => [
                'siteurl' => 'https://old-site.com',
                'blogname' => 'My Blog',
                'count' => 42,
                'active' => true,
            ],
            'theme' => 'default',
        ];

        $input = serialize($data);
        $result = PhpSerializedStringWalker::walk_strings($input, function (string $v): string {
            return str_replace('https://old-site.com', 'https://new-site.com', $v);
        });

        $unserialized = unserialize($result);
        $expected = $data;
        $expected['settings']['siteurl'] = 'https://new-site.com';
        $this->assertSame($expected, $unserialized);
    }

    // ---------------------------------------------------------------
    // No-change case is byte-identical
    // ---------------------------------------------------------------

    public function testNoChangeIsByteIdentical(): void
    {
        $data = [
            'key' => 'value with no URLs',
            'nested' => ['inner' => 'also no URLs'],
            'number' => 42,
        ];
        $input = serialize($data);

        $result = PhpSerializedStringWalker::walk_strings($input, $this->identity());
        $this->assertSame($input, $result, 'When no changes are made, output must be byte-identical to input');
    }

    public function testNoChangeWithUrlCallbackThatDoesNotMatch(): void
    {
        $data = ['site' => 'https://unmatched-domain.com'];
        $input = serialize($data);

        // Callback that only replaces a specific domain that's not in the data
        $result = PhpSerializedStringWalker::walk_strings($input, function (string $v): string {
            return str_replace('https://old-site.com', 'https://new-site.com', $v);
        });

        $this->assertSame($input, $result, 'When callback makes no changes, output must be byte-identical');
    }

    // ---------------------------------------------------------------
    // Malformed input returns null
    // ---------------------------------------------------------------

    public function testMalformedInputReturnsFalse(): void
    {
        $this->assertFalse(PhpSerializedStringWalker::walk_strings('not serialized', $this->identity()));
    }

    public function testTruncatedStringReturnsFalse(): void
    {
        $this->assertFalse(PhpSerializedStringWalker::walk_strings('s:10:"short";', $this->identity()));
    }

    public function testMissingClosingBraceReturnsFalse(): void
    {
        $this->assertFalse(PhpSerializedStringWalker::walk_strings('a:1:{i:0;s:3:"foo";', $this->identity()));
    }

    public function testTrailingGarbageReturnsFalse(): void
    {
        $this->assertFalse(PhpSerializedStringWalker::walk_strings('s:3:"foo";GARBAGE', $this->identity()));
    }

    public function testEmptyStringReturnsFalse(): void
    {
        $this->assertFalse(PhpSerializedStringWalker::walk_strings('', $this->identity()));
    }

    // ---------------------------------------------------------------
    // Strings containing quotes, semicolons, null bytes
    // ---------------------------------------------------------------

    public function testStringWithEmbeddedQuotes(): void
    {
        $input = serialize('He said "hello" to her');
        $result = PhpSerializedStringWalker::walk_strings($input, $this->identity());
        $this->assertSame($input, $result);
        $this->assertSame('He said "hello" to her', unserialize($result));
    }

    public function testStringWithEmbeddedSemicolons(): void
    {
        $input = serialize('a:1:{fake;data;}');
        $result = PhpSerializedStringWalker::walk_strings($input, $this->identity());
        $this->assertSame($input, $result);
        $this->assertSame('a:1:{fake;data;}', unserialize($result));
    }

    public function testStringWithNullBytes(): void
    {
        $value = "before\0after";
        $input = serialize($value);
        $result = PhpSerializedStringWalker::walk_strings($input, $this->identity());
        $this->assertSame($input, $result);
        $this->assertSame($value, unserialize($result));
    }

    public function testStringThatLooksLikeSerializedPhp(): void
    {
        // A string value that contains what looks like serialized PHP but is just text
        $value = 's:5:"inner";';
        $input = serialize($value);
        $result = PhpSerializedStringWalker::walk_strings($input, $this->toUpper());
        $this->assertSame('S:5:"INNER";', unserialize($result));
    }
}
