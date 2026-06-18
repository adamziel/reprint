<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Filesystem\PathUtils;
use Reprint\Importer\Index\IndexLineParser;
use Reprint\Importer\Sql\SqlStatementInspector;
use Reprint\Importer\Support\ByteFormatter;
use Reprint\Importer\Support\PathDisplayFormatter;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class ImportPureHelpersTest extends TestCase
{
    public function testByteFormatterUsesExistingUnits(): void
    {
        $this->assertSame('999 B', ByteFormatter::format(999));
        $this->assertSame('1.0 KB', ByteFormatter::format(1024));
        $this->assertSame('1.0 MB', ByteFormatter::format(1048576));
        $this->assertSame('1.0 GB', ByteFormatter::format(1073741824));
    }

    public function testPathUtilsCleanPreflightPathValues(): void
    {
        $this->assertSame('/srv/htdocs', PathUtils::clean_path_value('/srv/htdocs/'));
        $this->assertNull(PathUtils::clean_path_value(''));
        $this->assertNull(PathUtils::clean_path_value('   '));
        $this->assertNull(PathUtils::clean_path_value(['/srv/htdocs']));
    }

    public function testPathUtilsComputeRelativePath(): void
    {
        $this->assertSame('../../d/e', PathUtils::relative_path('/a/b/c', '/a/d/e'));
        $this->assertSame('e', PathUtils::relative_path('/a/d', '/a/d/e'));
        $this->assertSame('.', PathUtils::relative_path('/a/d/e', '/a/d/e'));
    }

    public function testPathDisplayFormatterShortensLongPaths(): void
    {
        $this->assertSame('wp-content/file.txt', PathDisplayFormatter::short_path('/wp-content/file.txt'));
        $this->assertSame(
            '...ong-directory-name/file.txt',
            PathDisplayFormatter::short_path('/wp-content/uploads/long-directory-name/file.txt', 30),
        );
    }

    public function testIndexLineParserParsesJsonlEntry(): void
    {
        $line = json_encode([
            'path' => base64_encode('/wp-content/uploads/image.jpg'),
            'ctime' => 123,
            'size' => 456,
            'type' => 'file',
        ]);

        $this->assertSame([
            'path' => '/wp-content/uploads/image.jpg',
            'ctime' => 123,
            'size' => 456,
            'type' => 'file',
        ], IndexLineParser::parse($line));
    }

    public function testSqlStatementInspectorExtractsInsertContext(): void
    {
        $query = "INSERT INTO `wp_options` VALUES (1,'siteurl',FROM_BASE64('aHR0cHM6Ly9leGFtcGxlLmNvbQ=='),'yes');";
        $offset = strpos($query, 'FROM_BASE64');

        $this->assertSame('wp_options', SqlStatementInspector::extract_insert_table($query));
        $this->assertSame('pk=1', SqlStatementInspector::extract_row_identifier($query, $offset));
        $this->assertSame('siteurl', SqlStatementInspector::extract_option_name($query, $offset));
        $this->assertTrue(
            SqlStatementInspector::starts_with_token(
                "/* comment */ {$query}",
                \WP_MySQL_Lexer::INSERT_SYMBOL
            )
        );
    }
}
