<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

class SQLitePreparedInsertBuilderTest extends TestCase
{
    public function testBuildsPreparedInsertForArbitraryBytes(): void
    {
        $bytes = '';
        for ($i = 0; $i <= 255; $i++) {
            $bytes .= chr($i);
        }

        $sql = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode($bytes)
        );

        $prepared = SQLitePreparedInsertBuilder::build($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, ?);",
            $prepared['sql']
        );
        $this->assertSame([$bytes], $prepared['params']);
    }

    public function testBuildsPreparedInsertForConvertWrappedPayload(): void
    {
        $value = '{"url":"https://example.com"}';
        $sql = sprintf(
            "INSERT INTO `wp_postmeta` (`meta_value`) VALUES(CONVERT(FROM_BASE64('%s') USING utf8mb4));",
            base64_encode($value)
        );

        $prepared = SQLitePreparedInsertBuilder::build($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_postmeta` (`meta_value`) VALUES(?);",
            $prepared['sql']
        );
        $this->assertSame([$value], $prepared['params']);
    }

    public function testRewriteCallbackReceivesTableAndColumn(): void
    {
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('before')
        );

        $prepared = SQLitePreparedInsertBuilder::build(
            $sql,
            function (string $value, string $table, ?string $column): string {
                $this->assertSame('before', $value);
                $this->assertSame('wp_posts', $table);
                $this->assertSame('post_content', $column);
                return 'after';
            }
        );

        $this->assertNotNull($prepared);
        $this->assertSame(['after'], $prepared['params']);
    }

    public function testRewritePredicateSkipsCallbackForDecodedNonUrlPayloads(): void
    {
        $false_positive = 'httle and tattle';
        $url_value = '<a href="https://old-site.com/page">Link</a>';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(1, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode($false_positive),
            base64_encode($url_value)
        );

        $this->assertStringContainsString('aHR0', base64_encode($false_positive));

        $calls = [];
        $prepared = SQLitePreparedInsertBuilder::build(
            $sql,
            function (string $value, string $table, ?string $column) use (&$calls): string {
                $calls[] = [$value, $table, $column];
                return str_replace('https://old-site.com', 'https://new-site.com', $value);
            },
            static function (string $encoded_value, string $decoded_value): bool {
                unset($encoded_value);
                return strpos($decoded_value, 'http') !== false
                    && stripos($decoded_value, 'old-site.com') !== false;
            }
        );

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(1, ?, ?);",
            $prepared['sql']
        );
        $this->assertSame([$false_positive, '<a href="https://new-site.com/page">Link</a>'], $prepared['params']);
        $this->assertSame([[$url_value, 'wp_posts', 'post_content']], $calls);
    }

    public function testSqlStatementRewriterBuildsPreparedInsertWithMixedUrlAndNonUrlPayloads(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
        $false_positive = 'httle and tattle';
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(1, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode($false_positive),
            base64_encode('<a href="https://old-site.com/page">Link</a>')
        );

        $prepared = $rewriter->build_sqlite_prepared_insert($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(1, ?, ?);",
            $prepared['sql']
        );
        $this->assertSame($false_positive, $prepared['params'][0]);
        $this->assertSame('<a href="https://new-site.com/page">Link</a>', $prepared['params'][1]);
    }

    public function testUnknownInsertShapeReturnsNull(): void
    {
        $sql = sprintf(
            "INSERT INTO `wp_options` VALUES(1, FROM_BASE64('%s'));",
            base64_encode('value')
        );

        $this->assertNull(SQLitePreparedInsertBuilder::build($sql));
    }

    public function testSqlStatementRewriterBuildsPreparedInsertWithRewrittenParam(): void
    {
        $rewriter = new SqlStatementRewriter(
            new StructuredDataUrlRewriter([
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('<a href="https://old-site.com/page">Link</a>')
        );

        $prepared = $rewriter->build_sqlite_prepared_insert($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, ?);",
            $prepared['sql']
        );
        $this->assertSame('<a href="https://new-site.com/page">Link</a>', $prepared['params'][0]);
    }
}
