<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for rewrite_table_names() and build_staging_table_map().
 *
 * The table name rewriter is the gatekeeper between pushed SQL and
 * production. If it fails to rewrite a table name, the push writes
 * directly into the live table. If it rewrites too broadly, it
 * corrupts unrelated statements.
 */
final class TableNameRewritingTest extends TestCase
{
    /**
     * Basic rewriting: all four SQL statement types get rewritten.
     */
    public function testRewritesAllFourStatementTypes(): void
    {
        $map = ['wp_posts' => '_push_wp_posts'];

        $cases = [
            'DROP TABLE IF EXISTS `wp_posts`'  => 'DROP TABLE IF EXISTS `_push_wp_posts`',
            'CREATE TABLE `wp_posts`'          => 'CREATE TABLE `_push_wp_posts`',
            'INSERT INTO `wp_posts`'           => 'INSERT INTO `_push_wp_posts`',
            'REFERENCES `wp_posts`'            => 'REFERENCES `_push_wp_posts`',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame(
                $expected,
                rewrite_table_names($input, $map),
                "Failed to rewrite: {$input}"
            );
        }
    }

    /**
     * CRITICAL: Table names that are substrings of each other.
     *
     * If we have both wp_post and wp_posts, a naive str_replace on
     * "wp_post" would corrupt "wp_posts" → "_push_wp_posts" gets the
     * wp_post prefix replaced again. The backtick quoting prevents this.
     */
    public function testSubstringTableNamesDoNotCollide(): void
    {
        $map = [
            'wp_post'     => '_push_wp_post',
            'wp_posts'    => '_push_wp_posts',
            'wp_postmeta' => '_push_wp_postmeta',
        ];

        $sql = implode("\n", [
            'CREATE TABLE `wp_post` (id INT);',
            'CREATE TABLE `wp_posts` (id INT);',
            'CREATE TABLE `wp_postmeta` (id INT);',
        ]);

        $result = rewrite_table_names($sql, $map);

        $this->assertStringContainsString('CREATE TABLE `_push_wp_post`', $result);
        $this->assertStringContainsString('CREATE TABLE `_push_wp_posts`', $result);
        $this->assertStringContainsString('CREATE TABLE `_push_wp_postmeta`', $result);

        // Ensure no double-rewriting: _push__push_ should never appear
        $this->assertStringNotContainsString('_push__push_', $result);
    }

    /**
     * SQL with no matching tables should pass through unchanged.
     */
    public function testUnmatchedTablesPassThroughUnchanged(): void
    {
        $map = ['wp_posts' => '_push_wp_posts'];
        $sql = "INSERT INTO `some_other_table` VALUES (1, 'hello');";

        $this->assertSame($sql, rewrite_table_names($sql, $map));
    }

    /**
     * FK REFERENCES inside CREATE TABLE must also be rewritten,
     * otherwise the staging table references the live table.
     */
    public function testForeignKeyReferencesAreRewritten(): void
    {
        $map = [
            'wp_posts' => '_push_wp_posts',
            'wp_postmeta' => '_push_wp_postmeta',
        ];

        $sql = <<<'SQL'
CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`meta_id`),
  CONSTRAINT `fk_post` FOREIGN KEY (`post_id`) REFERENCES `wp_posts` (`ID`)
) ENGINE=InnoDB;
SQL;

        $result = rewrite_table_names($sql, $map);

        $this->assertStringContainsString('CREATE TABLE `_push_wp_postmeta`', $result);
        $this->assertStringContainsString('REFERENCES `_push_wp_posts`', $result);
    }

    /**
     * Empty table map should return SQL unchanged.
     */
    public function testEmptyMapReturnsUnchanged(): void
    {
        $sql = "CREATE TABLE `wp_posts` (id INT);";
        $this->assertSame($sql, rewrite_table_names($sql, []));
    }

    /**
     * DANGEROUS: Table name appearing in string VALUES should NOT be rewritten.
     *
     * If a row contains the table name as data (e.g. a serialized option that
     * mentions the table name), the rewriter should NOT touch it. Since we
     * only rewrite inside backtick-quoted identifiers, string values are safe.
     */
    public function testTableNameInsideStringValuesIsNotRewritten(): void
    {
        $map = ['wp_posts' => '_push_wp_posts'];

        $sql = "INSERT INTO `wp_posts` VALUES (1, 'The wp_posts table is great');";
        $result = rewrite_table_names($sql, $map);

        // The INSERT INTO target should be rewritten
        $this->assertStringContainsString('INSERT INTO `_push_wp_posts`', $result);
        // But the string value must NOT be touched
        $this->assertStringContainsString("'The wp_posts table is great'", $result);
    }

    /**
     * Multi-statement SQL block should have all statements rewritten.
     */
    public function testMultiStatementBlock(): void
    {
        $map = ['wp_options' => '_push_wp_options'];

        $sql = implode("\n", [
            'DROP TABLE IF EXISTS `wp_options`;',
            'CREATE TABLE `wp_options` (option_id INT);',
            'INSERT INTO `wp_options` VALUES (1);',
            'INSERT INTO `wp_options` VALUES (2);',
        ]);

        $result = rewrite_table_names($sql, $map);

        $this->assertStringContainsString('DROP TABLE IF EXISTS `_push_wp_options`', $result);
        $this->assertStringContainsString('CREATE TABLE `_push_wp_options`', $result);
        $this->assertEquals(2, substr_count($result, 'INSERT INTO `_push_wp_options`'));
    }
}
