<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/lib/ContentClassifier.php';

class ContentClassifierTest extends TestCase
{
    // --- Serialized PHP ---

    public function testClassifiesSerializedNull(): void
    {
        $this->assertEquals(ContentClassifier::TYPE_SERIALIZED_PHP, ContentClassifier::classify('N;'));
    }

    public function testClassifiesSerializedString(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_SERIALIZED_PHP,
            ContentClassifier::classify(serialize('hello world'))
        );
    }

    public function testClassifiesSerializedArray(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_SERIALIZED_PHP,
            ContentClassifier::classify(serialize(['key' => 'value', 'num' => 42]))
        );
    }

    public function testClassifiesSerializedInteger(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_SERIALIZED_PHP,
            ContentClassifier::classify(serialize(42))
        );
    }

    public function testClassifiesSerializedDouble(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_SERIALIZED_PHP,
            ContentClassifier::classify(serialize(3.14))
        );
    }

    public function testClassifiesSerializedBoolean(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_SERIALIZED_PHP,
            ContentClassifier::classify(serialize(true))
        );
        $this->assertEquals(
            ContentClassifier::TYPE_SERIALIZED_PHP,
            ContentClassifier::classify(serialize(false))
        );
    }

    public function testClassifiesSerializedObject(): void
    {
        $obj = new stdClass();
        $obj->name = 'test';
        $this->assertEquals(
            ContentClassifier::TYPE_SERIALIZED_PHP,
            ContentClassifier::classify(serialize($obj))
        );
    }

    public function testClassifiesNestedSerializedArray(): void
    {
        $data = serialize([
            'siteurl' => 'https://example.com',
            'nested' => ['a' => 1, 'b' => 2],
        ]);
        $this->assertEquals(ContentClassifier::TYPE_SERIALIZED_PHP, ContentClassifier::classify($data));
    }

    // --- JSON ---

    public function testClassifiesJsonObject(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_JSON,
            ContentClassifier::classify('{"key": "value", "num": 42}')
        );
    }

    public function testClassifiesJsonArray(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_JSON,
            ContentClassifier::classify('[1, 2, 3]')
        );
    }

    public function testClassifiesNestedJson(): void
    {
        $json = json_encode([
            'url' => 'https://example.com',
            'nested' => ['deep' => ['value' => true]],
        ]);
        $this->assertEquals(ContentClassifier::TYPE_JSON, ContentClassifier::classify($json));
    }

    public function testClassifiesEmptyJsonObject(): void
    {
        $this->assertEquals(ContentClassifier::TYPE_JSON, ContentClassifier::classify('{}'));
    }

    public function testClassifiesEmptyJsonArray(): void
    {
        $this->assertEquals(ContentClassifier::TYPE_JSON, ContentClassifier::classify('[]'));
    }

    // --- Text (HTML, block markup, plain text, etc.) ---

    public function testClassifiesPlainText(): void
    {
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify('Hello, world!'));
    }

    public function testClassifiesHtml(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_TEXT,
            ContentClassifier::classify('<p>Visit <a href="https://example.com">our site</a></p>')
        );
    }

    public function testClassifiesBlockMarkup(): void
    {
        $markup = '<!-- wp:image {"src":"https://example.com/img.jpg"} --><figure><img src="https://example.com/img.jpg"/></figure><!-- /wp:image -->';
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify($markup));
    }

    public function testClassifiesUrl(): void
    {
        $this->assertEquals(
            ContentClassifier::TYPE_TEXT,
            ContentClassifier::classify('https://example.com/page')
        );
    }

    public function testClassifiesEmptyString(): void
    {
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify(''));
    }

    // --- Edge cases ---

    public function testStringStartingWithAColonButNotSerialized(): void
    {
        // "a:not-serialized" starts with 'a:' but is not valid serialized PHP
        // It ends with 'd', not ';' or '}'
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify('a:not-serialized'));
    }

    public function testShortStringsAreText(): void
    {
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify('hi'));
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify('abc'));
    }

    public function testInvalidJsonStartingWithBraceIsText(): void
    {
        // Starts with { but is not valid JSON
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify('{not json at all'));
    }

    public function testInvalidJsonStartingWithBracketIsText(): void
    {
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify('[not json'));
    }

    public function testJsonStringScalarIsText(): void
    {
        // A JSON scalar string like "hello" is not classified as JSON
        // because it doesn't start with { or [
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify('"hello"'));
    }

    public function testCssIsText(): void
    {
        $css = 'body { background: url("https://example.com/bg.jpg"); }';
        $this->assertEquals(ContentClassifier::TYPE_TEXT, ContentClassifier::classify($css));
    }
}
