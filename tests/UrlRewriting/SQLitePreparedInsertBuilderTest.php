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

    private static function shapeCacheCount(): int
    {
        $reflection = new ReflectionClass(SQLitePreparedInsertBuilder::class);
        $property = $reflection->getProperty('shape_cache');
        $property->setAccessible(true);

        return count($property->getValue());
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

    public function testCachedShapeReusesProducerLayoutWithChangedNumbersAndPayloads(): void
    {
        $make_sql = static function (
            string $first_id,
            string $first_score,
            string $first_key,
            string $first_value,
            string $second_id,
            string $second_score,
            string $second_key,
            string $second_value
        ): string {
            return sprintf(
                "INSERT INTO `wp_shape` (`id`, `score`, `empty_value`, `maybe_null`, `meta_key`, `meta_value`) VALUES(%s, %s, '', NULL, FROM_BASE64('%s'), CONVERT(FROM_BASE64('%s') USING utf8mb4)),(%s, %s, '', NULL, FROM_BASE64('%s'), CONVERT(FROM_BASE64('%s') USING utf8mb4));",
                $first_id,
                $first_score,
                base64_encode($first_key),
                base64_encode($first_value),
                $second_id,
                $second_score,
                base64_encode($second_key),
                base64_encode($second_value)
            );
        };

        $seed = $make_sql('1', '-2.5', 'alpha', 'http://old.example/alpha', '2', '3e4', 'beta', 'http://old.example/beta');
        $this->assertNotNull(SQLitePreparedInsertBuilder::build($seed));
        $this->assertSame(1, self::shapeCacheCount());

        $sql = $make_sql('1000000', '-4.25e+6', 'third', 'http://old.example/third', '22', '.5', 'fourth', 'http://old.example/fourth');
        $prepared = SQLitePreparedInsertBuilder::build($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_shape` (`id`, `score`, `empty_value`, `maybe_null`, `meta_key`, `meta_value`) VALUES(1000000, -4.25e+6, '', NULL, ?, ?),(22, .5, '', NULL, ?, ?);",
            $prepared['sql']
        );
        $this->assertSame(
            ['third', 'http://old.example/third', 'fourth', 'http://old.example/fourth'],
            $prepared['params']
        );
        $this->assertSame(1, self::shapeCacheCount(), 'matching layout should be served from the existing cache entry');
    }

    public function testCachedShapeKeepsColumnAwareRewriteContext(): void
    {
        $make_sql = static function (string $value): string {
            return sprintf(
                "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('%s'));",
                base64_encode($value)
            );
        };

        $this->assertNotNull(SQLitePreparedInsertBuilder::build($make_sql('seed')));
        $this->assertSame(1, self::shapeCacheCount());

        $seen = [];
        $prepared = SQLitePreparedInsertBuilder::build(
            $make_sql('before'),
            function (string $value, string $table, ?string $column) use (&$seen): string {
                $seen[] = [$value, $table, $column];
                return 'after';
            }
        );

        $this->assertNotNull($prepared);
        $this->assertSame([['before', 'wp_posts', 'post_content']], $seen);
        $this->assertSame(['after'], $prepared['params']);
        $this->assertSame(1, self::shapeCacheCount(), 'matching layout should not add a duplicate shape');
    }

    public function testCachedShapeMismatchFallsBackToFreshScan(): void
    {
        $seed = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `autoload`, `option_value`) VALUES(1, NULL, FROM_BASE64('%s'));",
            base64_encode('seed')
        );
        $this->assertNotNull(SQLitePreparedInsertBuilder::build($seed));
        $this->assertSame(1, self::shapeCacheCount());

        $sql = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `autoload`, `option_value`) VALUES(2, '', FROM_BASE64('%s'));",
            base64_encode('value')
        );
        $prepared = SQLitePreparedInsertBuilder::build($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_options` (`option_id`, `autoload`, `option_value`) VALUES(2, '', ?);",
            $prepared['sql']
        );
        $this->assertSame(['value'], $prepared['params']);
        $this->assertSame(2, self::shapeCacheCount(), 'different literal layout should miss cache and be rescanned');
    }

    public function testCachedShapeRejectsPayloadThatFastScannerWouldReject(): void
    {
        $seed = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('seed')
        );
        $this->assertNotNull(SQLitePreparedInsertBuilder::build($seed));
        $this->assertSame(1, self::shapeCacheCount());

        $invalid_sql = "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(2, FROM_BASE64('not-valid!'));";

        $this->assertNull(SQLitePreparedInsertBuilder::build($invalid_sql));
        $this->assertSame(1, self::shapeCacheCount());
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
