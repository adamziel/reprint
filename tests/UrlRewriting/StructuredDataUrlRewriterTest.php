<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../importer/lib/ContentClassifier.php';
require_once __DIR__ . '/../../importer/lib/PhpSerializedStringWalker.php';
require_once __DIR__ . '/../../importer/lib/StructuredDataUrlRewriter.php';

class StructuredDataUrlRewriterTest extends TestCase
{
    private function createRewriter(?array $mapping = null): StructuredDataUrlRewriter
    {
        return new StructuredDataUrlRewriter($mapping ?? [
            'https://old-site.com' => 'https://new-site.com',
        ]);
    }

    // --- HTML content ---

    public function testRewritesUrlInHrefAttribute(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://old-site.com/page">Link</a>';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/page', $result);
        $this->assertStringNotContainsString('old-site.com', $result);
    }

    public function testRewritesUrlInImgSrc(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<img src="https://old-site.com/wp-content/uploads/photo.jpg" />';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/wp-content/uploads/photo.jpg', $result);
    }

    public function testRewritesMultipleHtmlAttributes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://old-site.com/page1">Link 1</a><a href="https://old-site.com/page2">Link 2</a>';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/page1', $result);
        $this->assertStringContainsString('https://new-site.com/page2', $result);
    }

    // --- Block markup ---

    public function testRewritesBlockMarkupJsonAttributes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<!-- wp:image {"src":"https://old-site.com/img.jpg"} --><figure><img src="https://old-site.com/img.jpg"/></figure><!-- /wp:image -->';
        $result = $rewriter->rewrite($input);
        $this->assertStringNotContainsString('old-site.com', $result);
        $this->assertStringContainsString('new-site.com', $result);
    }

    // --- Plain text with URLs ---

    public function testRewritesBareUrlInText(): void
    {
        $rewriter = $this->createRewriter();
        $input = 'Visit us at https://old-site.com/about for more info.';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('https://new-site.com/about', $result);
    }

    // --- JSON content ---

    public function testRewritesUrlsInJsonStringValues(): void
    {
        $rewriter = $this->createRewriter();
        $input = json_encode([
            'home' => 'https://old-site.com',
            'logo' => 'https://old-site.com/wp-content/uploads/logo.png',
        ], JSON_UNESCAPED_SLASHES);

        $result = $rewriter->rewrite($input);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertStringContainsString('new-site.com', $decoded['home']);
        $this->assertStringContainsString('new-site.com/wp-content/uploads/logo.png', $decoded['logo']);
    }

    public function testRewritesUrlsInNestedJson(): void
    {
        $rewriter = $this->createRewriter();
        $input = json_encode([
            'settings' => [
                'url' => 'https://old-site.com/api',
                'nested' => [
                    'image' => 'https://old-site.com/img.jpg',
                ],
            ],
            'count' => 42,
            'active' => true,
        ], JSON_UNESCAPED_SLASHES);

        $result = $rewriter->rewrite($input);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertStringContainsString('new-site.com', $decoded['settings']['url']);
        $this->assertStringContainsString('new-site.com', $decoded['settings']['nested']['image']);
        $this->assertEquals(42, $decoded['count']);
        $this->assertTrue($decoded['active']);
    }

    public function testJsonOutputUsesUnescapedSlashes(): void
    {
        $rewriter = $this->createRewriter();
        $input = '{"url":"https://old-site.com/path"}';
        $result = $rewriter->rewrite($input);
        // Should not contain escaped slashes like \/
        $this->assertStringNotContainsString('\\/', $result);
    }

    // --- Serialized PHP ---

    public function testRewritesUrlInSerializedArray(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize([
            'siteurl' => 'https://old-site.com/site',
            'blogname' => 'My Old Site',
        ]);
        $result = $rewriter->rewrite($input);
        $unserialized = unserialize($result);
        $this->assertSame('https://new-site.com/site', $unserialized['siteurl']);
        $this->assertSame('My Old Site', $unserialized['blogname']);
    }

    public function testRewritesUrlInSerializedString(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize('https://old-site.com/page');
        $result = $rewriter->rewrite($input);
        $this->assertSame('https://new-site.com/page', unserialize($result));
    }

    public function testRewritesUrlsInDoubleSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        $inner = serialize(['url' => 'https://old-site.com/deep']);
        $input = serialize($inner);
        $result = $rewriter->rewrite($input);
        $inner_result = unserialize($result);
        $deep_result = unserialize($inner_result);
        $this->assertSame('https://new-site.com/deep', $deep_result['url']);
    }

    public function testRewritesJsonInsideSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        $json_value = json_encode(['link' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $input = serialize(['config' => $json_value]);
        $result = $rewriter->rewrite($input);
        $unserialized = unserialize($result);
        $decoded = json_decode($unserialized['config'], true);
        $this->assertSame('https://new-site.com/api', $decoded['link']);
    }

    public function testSerializedPhpWithNoUrlsIsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize([
            'setting' => 'no urls here',
            'count' => 42,
            'nested' => ['inner' => 'also no urls'],
        ]);
        $result = $rewriter->rewrite($input);
        $this->assertSame($input, $result, 'Serialized PHP with no matching URLs should be byte-identical');
    }

    public function testMalformedSerializedPhpFallsBackToText(): void
    {
        $rewriter = $this->createRewriter();
        // ContentClassifier::is_serialized() triggers on 's:...' ending with ';'
        // but this is truncated/malformed — the walker will return false,
        // falling back to text rewriting
        $input = 's:999:"https://old-site.com";';
        $result = $rewriter->rewrite($input);
        // Should have attempted text rewriting, replacing the URL
        $this->assertStringContainsString('new-site.com', $result);
    }

    // --- Base64 ---

    public function testRewritesBase64EncodedHtml(): void
    {
        $rewriter = $this->createRewriter();
        $html = '<a href="https://old-site.com/page">Link</a>';
        $input = base64_encode($html);
        $result = $rewriter->rewrite($input);
        $decoded = base64_decode($result);
        $this->assertStringContainsString('new-site.com/page', $decoded);
        $this->assertStringNotContainsString('old-site.com', $decoded);
    }

    public function testRewritesBase64EncodedJson(): void
    {
        $rewriter = $this->createRewriter();
        $json = json_encode(['url' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $input = base64_encode($json);
        $result = $rewriter->rewrite($input);
        $decoded = json_decode(base64_decode($result), true);
        $this->assertSame('https://new-site.com/api', $decoded['url']);
    }

    public function testRewritesBase64EncodedSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        $serialized = serialize(['siteurl' => 'https://old-site.com/site']);
        $input = base64_encode($serialized);
        $result = $rewriter->rewrite($input);
        $unserialized = unserialize(base64_decode($result));
        $this->assertSame('https://new-site.com/site', $unserialized['siteurl']);
    }

    public function testRewritesBase64EncodedBlockMarkup(): void
    {
        $rewriter = $this->createRewriter();
        $markup = '<!-- wp:image {"src":"https://old-site.com/img.jpg"} --><figure><img src="https://old-site.com/img.jpg"/></figure><!-- /wp:image -->';
        $input = base64_encode($markup);
        $result = $rewriter->rewrite($input);
        $decoded = base64_decode($result);
        $this->assertStringContainsString('new-site.com', $decoded);
        $this->assertStringNotContainsString('old-site.com', $decoded);
    }

    // --- Combinations: formats nested inside other formats ---

    public function testBase64InsideSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        $html = '<a href="https://old-site.com/page">Link</a>';
        $b64 = base64_encode($html);
        $input = serialize(['encoded_html' => $b64]);
        $result = $rewriter->rewrite($input);
        $unserialized = unserialize($result);
        $decoded = base64_decode($unserialized['encoded_html']);
        $this->assertStringContainsString('new-site.com/page', $decoded);
    }

    public function testSerializedPhpInsideJson(): void
    {
        $rewriter = $this->createRewriter();
        $serialized = serialize(['url' => 'https://old-site.com/deep']);
        $input = json_encode(['data' => $serialized], JSON_UNESCAPED_SLASHES);
        $result = $rewriter->rewrite($input);
        $json_decoded = json_decode($result, true);
        $unserialized = unserialize($json_decoded['data']);
        $this->assertSame('https://new-site.com/deep', $unserialized['url']);
    }

    public function testBase64InsideJsonInsideSerializedPhp(): void
    {
        $rewriter = $this->createRewriter();
        // Three layers: serialized PHP → JSON string → base64 → HTML
        $html = '<img src="https://old-site.com/img.jpg"/>';
        $b64 = base64_encode($html);
        $json = json_encode(['encoded' => $b64], JSON_UNESCAPED_SLASHES);
        $input = serialize(['config' => $json]);

        $result = $rewriter->rewrite($input);

        // Unwind all layers and verify the URL was rewritten
        $unserialized = unserialize($result);
        $json_decoded = json_decode($unserialized['config'], true);
        $decoded_html = base64_decode($json_decoded['encoded']);
        $this->assertStringContainsString('new-site.com/img.jpg', $decoded_html);
        $this->assertStringNotContainsString('old-site.com', $decoded_html);
    }

    public function testBase64WithNoUrlsIsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        // JSON with no matching URLs, base64-encoded
        $json = json_encode(['key' => 'no urls here'], JSON_UNESCAPED_SLASHES);
        $input = base64_encode($json);
        $result = $rewriter->rewrite($input);
        $this->assertSame($input, $result, 'Base64 with no matching URLs should be byte-identical');
    }

    public function testShortBase64LikeStringNotDecoded(): void
    {
        $rewriter = $this->createRewriter();
        // "TRUE" is valid base64 but too short to be treated as encoded data
        $result = $rewriter->rewrite('TRUE');
        $this->assertSame('TRUE', $result);
    }

    // --- No-change cases ---

    public function testValueWithNoUrlsReturnsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $input = 'Just a regular string with no URLs.';
        $result = $rewriter->rewrite($input);
        $this->assertEquals($input, $result);
    }

    public function testEmptyStringReturnsUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $this->assertEquals('', $rewriter->rewrite(''));
    }

    public function testUrlFromDifferentDomainIsNotRewritten(): void
    {
        $rewriter = $this->createRewriter();
        $input = '<a href="https://other-site.com/page">Link</a>';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('other-site.com', $result);
        $this->assertStringNotContainsString('new-site.com', $result);
    }

    // --- Multiple URL mappings ---

    public function testMultipleUrlMappings(): void
    {
        $rewriter = $this->createRewriter([
            'https://old-site.com' => 'https://new-site.com',
            'https://cdn.old-site.com' => 'https://cdn.new-site.com',
        ]);
        $input = '<img src="https://cdn.old-site.com/img.jpg"/><a href="https://old-site.com/page">Link</a>';
        $result = $rewriter->rewrite($input);
        $this->assertStringContainsString('cdn.new-site.com', $result);
        $this->assertStringContainsString('new-site.com/page', $result);
    }
}
