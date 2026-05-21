<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

class SQLitePreparedInsertBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        self::clearShapeCache();
    }

    private static function clearShapeCache(): void
    {
        $reflection = new ReflectionClass(SQLitePreparedInsertBuilder::class);
        $property = $reflection->getProperty('shape_cache');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

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

    public function testRewriteCallbackCanBePrefilteredByEncodedPayload(): void
    {
        $sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('plain value'),
            base64_encode('http://old-site.example.com')
        );
        $calls = 0;

        $prepared = SQLitePreparedInsertBuilder::build(
            $sql,
            function (string $value, string $table, ?string $column) use (&$calls): string {
                $calls++;
                return str_replace('old-site', 'new-site', $value);
            },
            function (string $encoded_value): bool {
                return strpos($encoded_value, 'aHR0') !== false;
            }
        );

        $this->assertNotNull($prepared);
        $this->assertSame(1, $calls);
        $this->assertSame(
            ['plain value', 'http://new-site.example.com'],
            $prepared['params']
        );
    }

    public function testRewritePrefilterReceivesDecodedPayloadOnCachedShape(): void
    {
        $make_sql = static function (string $first, string $second): string {
            return sprintf(
                "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
                base64_encode($first),
                base64_encode($second)
            );
        };

        SQLitePreparedInsertBuilder::build($make_sql('seed', 'seed'));

        $calls = 0;
        $seen_decoded = [];
        $prepared = SQLitePreparedInsertBuilder::build(
            $make_sql('plain value', 'http://old-site.example.com'),
            function (string $value, string $table, ?string $column) use (&$calls): string {
                $calls++;
                return str_replace('old-site', 'new-site', $value);
            },
            function (string $encoded_value, string $decoded_value) use (&$seen_decoded): bool {
                unset($encoded_value);
                $seen_decoded[] = $decoded_value;
                return strpos($decoded_value, 'http') !== false;
            }
        );

        $this->assertNotNull($prepared);
        $this->assertSame(['plain value', 'http://old-site.example.com'], $seen_decoded);
        $this->assertSame(1, $calls);
        $this->assertSame(
            ['plain value', 'http://new-site.example.com'],
            $prepared['params']
        );
    }

    public function testCachedShapeRejectsPayloadThatFastScannerWouldReject(): void
    {
        $valid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('seed'),
            base64_encode('value')
        );
        SQLitePreparedInsertBuilder::build($valid_sql);

        $invalid_sql = "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('valid'), FROM_BASE64('not-valid!'));";

        $this->assertNull(SQLitePreparedInsertBuilder::build($invalid_sql));
    }

    public function testUncachedShapeRejectsInvalidBase64PaddingInsteadOfBindingEmptyString(): void
    {
        $sql = "INSERT INTO `wp_options` (`option_value`) VALUES(FROM_BASE64('valid'));";

        $this->assertNull(SQLitePreparedInsertBuilder::build($sql));
    }

    public function testCachedShapeRejectsInvalidBase64PaddingInsteadOfBindingEmptyString(): void
    {
        $valid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_value`) VALUES(FROM_BASE64('%s'));",
            base64_encode('seed')
        );
        SQLitePreparedInsertBuilder::build($valid_sql);

        $invalid_sql = "INSERT INTO `wp_options` (`option_value`) VALUES(FROM_BASE64('valid'));";

        $this->assertNull(SQLitePreparedInsertBuilder::build($invalid_sql));
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
