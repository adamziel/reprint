<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

class SQLitePreparedInsertBuilderTest extends TestCase
{
    private function createStatementRewriter(?array $mapping = null): SqlStatementRewriter
    {
        return new SqlStatementRewriter(
            new StructuredDataUrlRewriter($mapping ?? [
                'https://old-site.com' => 'https://new-site.com',
            ])
        );
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

    public function testStructuredInsertPreservesBinaryNullAndEmptyValues(): void
    {
        $bytes = '';
        for ($i = 0; $i <= 255; $i++) {
            $bytes .= chr($i);
        }
        $json = '{"null":null,"empty":""}';

        $sql = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES "
            . "(1, FROM_BASE64('%s'), '', NULL),"
            . "(2, CONVERT(FROM_BASE64('%s') USING utf8mb4), FROM_BASE64('%s'), '');",
            base64_encode('binary_payload'),
            base64_encode($json),
            base64_encode($bytes)
        );

        $prepared = SQLitePreparedInsertBuilder::build_structured($sql);

        $this->assertNotNull($prepared);
        $this->assertSame(
            "INSERT INTO `wp_options` (`option_id`,`option_name`,`option_value`,`autoload`) VALUES (?,?,?,?),(?,?,?,?);",
            $prepared['sql']
        );
        $this->assertSame(
            ['1', 'binary_payload', '', null, '2', $json, $bytes, ''],
            $prepared['params']
        );
        $this->assertSame(
            [
                PDO::PARAM_STR,
                PDO::PARAM_STR,
                PDO::PARAM_STR,
                PDO::PARAM_NULL,
                PDO::PARAM_STR,
                PDO::PARAM_STR,
                PDO::PARAM_STR,
                PDO::PARAM_STR,
            ],
            $prepared['param_types']
        );
        $this->assertSame(2, $prepared['row_count']);
    }

    public function testStructuredInsertShapeIsStableAcrossLiteralValues(): void
    {
        $sql_a = sprintf(
            "INSERT INTO `wp_postmeta` (`meta_id`, `post_id`, `meta_key`, `meta_value`) VALUES "
            . "(1, 10, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('first_key'),
            base64_encode('first_value')
        );
        $sql_b = sprintf(
            "INSERT INTO `wp_postmeta` (`meta_id`, `post_id`, `meta_key`, `meta_value`) VALUES "
            . "(999, 888, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('second_key'),
            base64_encode('second_value')
        );

        $prepared_a = SQLitePreparedInsertBuilder::build_structured($sql_a);
        $prepared_b = SQLitePreparedInsertBuilder::build_structured($sql_b);

        $this->assertNotNull($prepared_a);
        $this->assertNotNull($prepared_b);
        $this->assertSame($prepared_a['sql'], $prepared_b['sql']);
        $this->assertSame(['1', '10', 'first_key', 'first_value'], $prepared_a['params']);
        $this->assertSame(['999', '888', 'second_key', 'second_value'], $prepared_b['params']);
    }

    public function testStructuredUrlRewriteReceivesColumnContextForEveryBase64Value(): void
    {
        $sql = sprintf(
            "INSERT INTO `wp_posts` (`ID`, `post_content`, `post_title`) VALUES "
            . "(1, FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('<a href="https://old-site.example/page">Link</a>'),
            base64_encode('Plain title')
        );

        $calls = [];
        $prepared = SQLitePreparedInsertBuilder::build_structured(
            $sql,
            function (string $value, string $table, ?string $column) use (&$calls): string {
                $calls[] = [$value, $table, $column];
                return str_replace('old-site.example', 'new-site.example', $value);
            }
        );

        $this->assertNotNull($prepared);
        $this->assertSame(
            [
                ['<a href="https://old-site.example/page">Link</a>', 'wp_posts', 'post_content'],
                ['Plain title', 'wp_posts', 'post_title'],
            ],
            $calls
        );
        $this->assertSame(
            ['1', '<a href="https://new-site.example/page">Link</a>', 'Plain title'],
            $prepared['params']
        );
    }

    public function testStructuredSqliteInsertMatchesPreparedRewriteSemanticsForSupportedUrlSpellings(): void
    {
        $cases = [
            'lowercase_url' => [
                'mapping' => ['https://old-site.com' => 'https://new-site.com'],
                'value' => '<a href="https://old-site.com/lowercase">Lowercase</a>',
                'expected' => '<a href="https://new-site.com/lowercase">Lowercase</a>',
                'forbidden' => 'old-site.com',
            ],
            'uppercase_scheme_and_host' => [
                'mapping' => ['https://old-site.com' => 'https://new-site.com'],
                'value' => '<a href="HTTPS://OLD-SITE.COM/case-only">Case</a>',
                'expected' => '<a href="https://new-site.com/case-only">Case</a>',
                'forbidden' => 'old-site.com',
            ],
            'json_escaped_block_attribute' => [
                'mapping' => ['https://old-site.com' => 'https://new-site.com'],
                'value' => '<!-- wp:image {"src":"https:\/\/old-site.com\/escaped.jpg"} -->',
                'expected' => 'https:\/\/new-site.com\/escaped.jpg',
                'forbidden' => 'old-site.com',
            ],
            'serialized_php' => [
                'mapping' => ['https://old-site.com' => 'https://new-site.com'],
                'value' => serialize(['html' => '<a href="https://old-site.com/serialized">Serialized</a>']),
                'expected' => serialize(['html' => '<a href="https://new-site.com/serialized">Serialized</a>']),
                'forbidden' => 'old-site.com',
            ],
            'punycode_idn_host' => [
                'mapping' => ['https://xn--bcher-kva.example' => 'https://new.example'],
                'value' => '<a href="https://xn--bcher-kva.example/punycode">Punycode</a>',
                'expected' => '<a href="https://new.example/punycode">Punycode</a>',
                'forbidden' => 'xn--bcher-kva.example',
            ],
        ];

        foreach ($cases as $label => $case) {
            $rewriter = $this->createStatementRewriter($case['mapping']);
            $sql = sprintf(
                "INSERT INTO `wp_posts` (`ID`, `post_content`, `post_title`) VALUES "
                . "(1, FROM_BASE64('%s'), FROM_BASE64('%s'));",
                base64_encode($case['value']),
                base64_encode('Title without URLs')
            );

            $legacy = $rewriter->build_sqlite_prepared_insert($sql);
            $structured = $rewriter->build_sqlite_structured_insert($sql);

            $this->assertNotNull($legacy, $label);
            $this->assertNotNull($structured, $label);
            $this->assertSame($legacy['params'][0], $structured['params'][1], $label);
            $this->assertStringContainsString($case['expected'], $structured['params'][1], $label);
            $this->assertStringNotContainsString($case['forbidden'], strtolower($structured['params'][1]), $label);
            $this->assertSame('Title without URLs', $structured['params'][2], $label);
        }
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

    public function testRejectsMalformedBase64InsteadOfBindingEmptyString(): void
    {
        $sql = "INSERT INTO `wp_options` (`option_value`) VALUES(FROM_BASE64('valid'));";

        $this->assertNull(SQLitePreparedInsertBuilder::build($sql));
    }

    public function testRejectsMalformedBase64AfterSameShapeWasBuilt(): void
    {
        $valid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('name'),
            base64_encode('value')
        );
        $this->assertNotNull(SQLitePreparedInsertBuilder::build($valid_sql));

        // Guards both the current rescan path and any future same-shape cache.
        $invalid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('valid'));",
            base64_encode('name')
        );

        $this->assertNull(SQLitePreparedInsertBuilder::build($invalid_sql));
    }

    public function testStructuredInsertRejectsFallbackBoundaries(): void
    {
        $valid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('%s'));",
            base64_encode('value')
        );
        $this->assertNotNull(SQLitePreparedInsertBuilder::build_structured($valid_sql));

        $cases = [
            "INSERT INTO `wp_options` VALUES(1, FROM_BASE64('" . base64_encode('value') . "'));",
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1);",
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('not-valid!'));",
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('valid'));",
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1e, FROM_BASE64('" . base64_encode('value') . "'));",
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, 'literal');",
            "INSERT INTO `wp_options` (`option_id`, `option_value`) VALUES(1, FROM_BASE64('" . base64_encode('value') . "')) ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`);",
        ];

        foreach ($cases as $case) {
            $this->assertNull(SQLitePreparedInsertBuilder::build_structured($case), $case);
        }
    }

    public function testRejectsNonAlphabetPayloadAfterSameShapeWasBuilt(): void
    {
        $valid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'));",
            base64_encode('name'),
            base64_encode('value')
        );
        $this->assertNotNull(SQLitePreparedInsertBuilder::build($valid_sql));

        // Guards both the current rescan path and any future same-shape cache.
        $invalid_sql = sprintf(
            "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES(FROM_BASE64('%s'), FROM_BASE64('not-valid!'));",
            base64_encode('name')
        );

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
