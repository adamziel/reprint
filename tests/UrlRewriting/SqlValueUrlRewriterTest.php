<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../importer/lib/ContentClassifier.php';
require_once __DIR__ . '/../../importer/lib/SqlValueUrlRewriter.php';

class SqlValueUrlRewriterTest extends TestCase
{
    private function createRewriter(array $mapping = null): SqlValueUrlRewriter
    {
        return new SqlValueUrlRewriter($mapping ?? [
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

    public function testSerializedPhpIsReturnedUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize([
            'siteurl' => 'https://old-site.com',
            'blogname' => 'My Old Site',
        ]);
        $result = $rewriter->rewrite($input);
        $this->assertEquals($input, $result, 'Serialized PHP should be returned unchanged');
    }

    public function testSerializedStringIsReturnedUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $input = serialize('https://old-site.com');
        $result = $rewriter->rewrite($input);
        $this->assertEquals($input, $result);
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
