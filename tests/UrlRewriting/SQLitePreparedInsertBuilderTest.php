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

    public function testCachedShapeKeepsDynamicLiteralsAndPerValueColumns(): void
    {
        $seen = [];
        $callback = function (string $value, string $table, ?string $column) use (&$seen): string {
            $seen[] = [$value, $table, $column];
            return strtoupper($value);
        };

        $first = SQLitePreparedInsertBuilder::build(
            sprintf(
                "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(1, FROM_BASE64('%s'), CONVERT(FROM_BASE64('%s') USING utf8mb4));",
                base64_encode('first title'),
                base64_encode('first body')
            ),
            $callback
        );
        $second = SQLitePreparedInsertBuilder::build(
            sprintf(
                "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(245, FROM_BASE64('%s'), CONVERT(FROM_BASE64('%s') USING utf8mb4));",
                base64_encode('second title'),
                base64_encode('second body')
            ),
            $callback
        );

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`) VALUES(245, ?, ?);",
            $second['sql']
        );
        $this->assertSame(['SECOND TITLE', 'SECOND BODY'], $second['params']);
        $this->assertSame(
            [
                ['first title', 'wp_posts', 'post_title'],
                ['first body', 'wp_posts', 'post_content'],
                ['second title', 'wp_posts', 'post_title'],
                ['second body', 'wp_posts', 'post_content'],
            ],
            $seen
        );
    }

    public function testCachedShapeRejectsMalformedNumericSlots(): void
    {
        $first = SQLitePreparedInsertBuilder::build(
            sprintf(
                "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('%s'));",
                base64_encode('first body')
            )
        );
        $second = SQLitePreparedInsertBuilder::build(
            sprintf(
                "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1e, FROM_BASE64('%s'));",
                base64_encode('second body')
            )
        );

        $this->assertNotNull($first);
        $this->assertNull($second);
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
