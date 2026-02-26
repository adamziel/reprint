<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../importer/lib/wp-stubs.php';
require_once __DIR__ . '/../../importer/lib/Base64ValueScanner.php';
require_once __DIR__ . '/../../importer/lib/ContentClassifier.php';
require_once __DIR__ . '/../../importer/lib/SqlValueUrlRewriter.php';
require_once __DIR__ . '/../../importer/lib/SqlStatementRewriter.php';

class SqlStatementRewriterTest extends TestCase
{
    private function createRewriter(?array $mapping = null): SqlStatementRewriter
    {
        return new SqlStatementRewriter(
            new SqlValueUrlRewriter($mapping ?? [
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
    }

    public function testRewritesUrlInInsertStatement(): void
    {
        $rewriter = $this->createRewriter();
        $html = '<a href="https://old-site.com/page">Link</a>';
        $encoded = base64_encode($html);
        $sql = "INSERT INTO `wp_posts` VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        // Verify the rewritten SQL contains new-site.com
        $matches = Base64ValueScanner::scan($result);
        $this->assertCount(1, $matches);
        $this->assertStringContainsString('new-site.com', $matches[0]['value']);
        $this->assertStringNotContainsString('old-site.com', $matches[0]['value']);
    }

    public function testPassesThroughDdlStatements(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "CREATE TABLE `wp_posts` (id INT, content TEXT);";
        $this->assertEquals($sql, $rewriter->rewrite($sql));
    }

    public function testPassesThroughStatementsWithNoBase64(): void
    {
        $rewriter = $this->createRewriter();
        $sql = "INSERT INTO `wp_posts` VALUES(1, NULL, 42);";
        $this->assertEquals($sql, $rewriter->rewrite($sql));
    }

    public function testSkipsSerializedPhpValues(): void
    {
        $rewriter = $this->createRewriter();
        $serialized = serialize(['siteurl' => 'https://old-site.com']);
        $encoded = base64_encode($serialized);
        $sql = "INSERT INTO `wp_options` VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        // Should be unchanged — serialized PHP is skipped
        $this->assertEquals($sql, $result);
    }

    public function testRewritesJsonValues(): void
    {
        $rewriter = $this->createRewriter();
        $json = json_encode(['url' => 'https://old-site.com/api'], JSON_UNESCAPED_SLASHES);
        $encoded = base64_encode($json);
        $sql = "INSERT INTO `wp_postmeta` VALUES(1, CONVERT(FROM_BASE64('{$encoded}') USING utf8mb4));";

        $result = $rewriter->rewrite($sql);

        $matches = Base64ValueScanner::scan($result);
        $this->assertCount(1, $matches);
        $this->assertTrue($matches[0]['is_json']);
        $decoded = json_decode($matches[0]['value'], true);
        $this->assertStringContainsString('new-site.com', $decoded['url']);
    }

    public function testHandlesMixedValueTypes(): void
    {
        $rewriter = $this->createRewriter();

        $html = '<p>Visit <a href="https://old-site.com">us</a></p>';
        $serialized = serialize(['url' => 'https://old-site.com']);
        $plain = 'https://old-site.com/about';

        $sql = sprintf(
            "INSERT INTO `t` VALUES(1, FROM_BASE64('%s'), FROM_BASE64('%s'), NULL, FROM_BASE64('%s'));",
            base64_encode($html),
            base64_encode($serialized),
            base64_encode($plain)
        );

        $result = $rewriter->rewrite($sql);

        $matches = Base64ValueScanner::scan($result);
        $this->assertCount(3, $matches);

        // HTML should be rewritten
        $this->assertStringContainsString('new-site.com', $matches[0]['value']);

        // Serialized PHP should be unchanged
        $this->assertEquals($serialized, $matches[1]['value']);

        // Plain text should be rewritten
        $this->assertStringContainsString('new-site.com', $matches[2]['value']);
    }

    public function testValuesWithNoMatchingUrlsAreUnchanged(): void
    {
        $rewriter = $this->createRewriter();
        $text = 'No URLs here, just plain text.';
        $encoded = base64_encode($text);
        $sql = "INSERT INTO `t` VALUES(FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        $matches = Base64ValueScanner::scan($result);
        $this->assertCount(1, $matches);
        $this->assertEquals($text, $matches[0]['value']);
    }

    public function testRewritesMultipleRowInsert(): void
    {
        $rewriter = $this->createRewriter();
        $url1 = 'https://old-site.com/page1';
        $url2 = 'https://old-site.com/page2';

        $sql = sprintf(
            "INSERT INTO `t` VALUES(1, FROM_BASE64('%s')), (2, FROM_BASE64('%s'));",
            base64_encode($url1),
            base64_encode($url2)
        );

        $result = $rewriter->rewrite($sql);

        $matches = Base64ValueScanner::scan($result);
        $this->assertCount(2, $matches);
        $this->assertStringContainsString('new-site.com/page1', $matches[0]['value']);
        $this->assertStringContainsString('new-site.com/page2', $matches[1]['value']);
    }

    public function testResultIsValidSql(): void
    {
        $rewriter = $this->createRewriter();
        $html = '<img src="https://old-site.com/img.jpg"/>';
        $encoded = base64_encode($html);
        $sql = "INSERT INTO `wp_posts` (`id`, `content`) VALUES(1, FROM_BASE64('{$encoded}'));";

        $result = $rewriter->rewrite($sql);

        // Verify the result still has proper SQL structure
        $this->assertStringStartsWith('INSERT INTO', $result);
        $this->assertStringEndsWith(');', $result);
        $this->assertStringContainsString("FROM_BASE64('", $result);
        $this->assertStringContainsString("')", $result);
    }
}
