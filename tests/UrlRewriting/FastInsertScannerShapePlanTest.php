<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/url-rewrite/load.php';

class FastInsertScannerShapePlanTest extends TestCase
{
    private function b64(string $value): string
    {
        return base64_encode($value);
    }

    /**
     * @return array{
     *   table: string,
     *   columns: list<string>,
     *   column_map: list<array{int, int, string}>,
     *   base64_entries: list<array{expr_start: int, expr_length: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}>,
     *   value_entries: list<array{kind: string, start: int, end: int, column: string, raw?: string, expr_start?: int, expr_length?: int, quote_start?: int, quote_length?: int, encoded_value?: string}>,
     *   shape_key: string,
     *   shape_plan_cache_hit: bool,
     *   shape_plan: array{
     *     table: string,
     *     columns: list<string>,
     *     column_count: int,
     *     row_count: int,
     *     base64_value_indexes: list<int>,
     *     option_name_value_indexes: list<int|null>,
     *     row_identifier_value_indexes: list<int>
     *   }
     * }
     */
    private function scan(string $sql): array
    {
        $scan = FastInsertScanner::scan_with_reusable_plan($sql, false);
        $this->assertNotNull($scan);
        return $scan;
    }

    public function testReusesPlanForSameTableColumnAndValueShape(): void
    {
        $sql_a = sprintf(
            "INSERT INTO `shape_reuse_posts` (`ID`, `post_content`) VALUES(1, FROM_BASE64('%s')), (2, FROM_BASE64('%s'));",
            $this->b64('alpha'),
            $this->b64('bravo')
        );
        $sql_b = sprintf(
            "INSERT INTO `shape_reuse_posts` (`ID`, `post_content`) VALUES(10, FROM_BASE64('%s')), (20, FROM_BASE64('%s'));",
            $this->b64('charlie'),
            $this->b64('delta')
        );

        $first = $this->scan($sql_a);
        $second = $this->scan($sql_b);

        $this->assertFalse($first['shape_plan_cache_hit']);
        $this->assertTrue($second['shape_plan_cache_hit']);
        $this->assertSame($first['shape_key'], $second['shape_key']);
        $this->assertSame([1, 3], $second['shape_plan']['base64_value_indexes']);
        $this->assertSame([0, 2], $second['shape_plan']['row_identifier_value_indexes']);
    }

    public function testDifferentTablesDoNotReusePlan(): void
    {
        $sql_a = sprintf(
            "INSERT INTO `shape_table_a` (`id`, `payload`) VALUES(1, FROM_BASE64('%s'));",
            $this->b64('alpha')
        );
        $sql_b = sprintf(
            "INSERT INTO `shape_table_b` (`id`, `payload`) VALUES(1, FROM_BASE64('%s'));",
            $this->b64('bravo')
        );

        $first = $this->scan($sql_a);
        $second = $this->scan($sql_b);

        $this->assertFalse($first['shape_plan_cache_hit']);
        $this->assertFalse($second['shape_plan_cache_hit']);
        $this->assertNotSame($first['shape_key'], $second['shape_key']);
    }

    public function testDifferentColumnListsAndOrdersDoNotReusePlan(): void
    {
        $sql_a = sprintf(
            "INSERT INTO `shape_column_order` (`id`, `payload`) VALUES(1, FROM_BASE64('%s'));",
            $this->b64('alpha')
        );
        $sql_b = sprintf(
            "INSERT INTO `shape_column_order` (`payload`, `id`) VALUES(FROM_BASE64('%s'), 1);",
            $this->b64('alpha')
        );

        $first = $this->scan($sql_a);
        $second = $this->scan($sql_b);

        $this->assertFalse($first['shape_plan_cache_hit']);
        $this->assertFalse($second['shape_plan_cache_hit']);
        $this->assertNotSame($first['shape_key'], $second['shape_key']);
        $this->assertSame([0], $second['shape_plan']['base64_value_indexes']);
        $this->assertSame([0], $second['shape_plan']['row_identifier_value_indexes']);
    }

    public function testDifferentValueKindsDoNotReusePlan(): void
    {
        $sql_a = sprintf(
            "INSERT INTO `shape_value_kinds` (`id`, `payload`, `flag`) VALUES(1, FROM_BASE64('%s'), NULL);",
            $this->b64('alpha')
        );
        $sql_b = sprintf(
            "INSERT INTO `shape_value_kinds` (`id`, `payload`, `flag`) VALUES(1, FROM_BASE64('%s'), '');",
            $this->b64('alpha')
        );

        $first = $this->scan($sql_a);
        $second = $this->scan($sql_b);

        $this->assertFalse($first['shape_plan_cache_hit']);
        $this->assertFalse($second['shape_plan_cache_hit']);
        $this->assertNotSame($first['shape_key'], $second['shape_key']);
    }

    public function testEscapedIdentifiersArePartOfReusablePlan(): void
    {
        $sql_a = sprintf(
            "INSERT INTO `shape``escaped` (`id`, `weird``payload`) VALUES(1, FROM_BASE64('%s'));",
            $this->b64('alpha')
        );
        $sql_b = sprintf(
            "INSERT INTO `shape``escaped` (`id`, `weird``payload`) VALUES(2, FROM_BASE64('%s'));",
            $this->b64('bravo')
        );

        $first = $this->scan($sql_a);
        $second = $this->scan($sql_b);

        $this->assertSame('shape`escaped', $first['shape_plan']['table']);
        $this->assertSame(['id', 'weird`payload'], $first['shape_plan']['columns']);
        $this->assertFalse($first['shape_plan_cache_hit']);
        $this->assertTrue($second['shape_plan_cache_hit']);
        $this->assertSame($first['shape_key'], $second['shape_key']);
    }

    public function testOptionsColumnOrderSelectsOptionNameFromPlan(): void
    {
        $sql = sprintf(
            "INSERT INTO `shape_options` (`option_value`, `option_name`, `option_id`) VALUES(FROM_BASE64('%s'), FROM_BASE64('%s'), 1);",
            $this->b64('https://example.test'),
            $this->b64('_transient_cached')
        );

        $scan = $this->scan($sql);

        $this->assertSame([0, 1], $scan['shape_plan']['base64_value_indexes']);
        $this->assertSame([1], $scan['shape_plan']['option_name_value_indexes']);
    }

    public function testUnsupportedShapeStillReturnsNullForFallback(): void
    {
        $supported = sprintf(
            "INSERT INTO `shape_fallback` (`id`, `payload`) VALUES(1, FROM_BASE64('%s'));",
            $this->b64('alpha')
        );
        $unsupported_without_columns = sprintf(
            "INSERT INTO `shape_fallback` VALUES(1, FROM_BASE64('%s'));",
            $this->b64('bravo')
        );
        $unsupported_literal = "INSERT INTO `shape_fallback` (`id`, `payload`) VALUES(1, 'literal');";

        $this->assertNotNull(FastInsertScanner::scan_with_reusable_plan($supported, false));
        $this->assertNull(FastInsertScanner::scan_with_reusable_plan($unsupported_without_columns, false));
        $this->assertNull(FastInsertScanner::scan_with_reusable_plan($unsupported_literal, false));
    }
}
