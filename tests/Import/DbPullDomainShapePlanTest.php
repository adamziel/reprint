<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

class DbPullDomainShapePlanTest extends TestCase
{
    private string $temp_dir;

    protected function setUp(): void
    {
        $this->temp_dir = sys_get_temp_dir() . '/reprint-domain-plan-' . bin2hex(random_bytes(6));
        mkdir($this->temp_dir, 0777, true);
        mkdir($this->temp_dir . '/fs', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temp_dir);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }

    /**
     * @return array{domains: list<string>, statements: int}
     */
    private function collectDomainsFromSql(string $sql): array
    {
        $client = new ImportClient('https://source.example', $this->temp_dir, $this->temp_dir . '/fs');
        $stream = new WP_MySQL_Naive_Query_Stream();
        $collector = new DomainCollector();

        $stream->append_sql($sql);
        $stream->mark_input_complete();

        $statements = 0;
        $reflection = new ReflectionClass(ImportClient::class);
        $method = $reflection->getMethod('drain_query_stream_for_domains');
        $method->setAccessible(true);
        $args = [$stream, $collector, &$statements];
        $method->invokeArgs($client, $args);

        return [
            'domains' => $collector->get_domains(),
            'statements' => $statements,
        ];
    }

    private function b64(string $value): string
    {
        return base64_encode($value);
    }

    public function testPlannedOptionsShapeUsesColumnNamesForTransientSkip(): void
    {
        $sql = sprintf(
            "INSERT INTO `shape_options` (`option_value`, `option_id`, `option_name`) VALUES" .
            "(FROM_BASE64('%s'), 1, FROM_BASE64('%s'))," .
            "(FROM_BASE64('%s'), 2, FROM_BASE64('%s'));",
            $this->b64('https://transient-media.example/image.jpg'),
            $this->b64('_transient_cached_media'),
            $this->b64('<img src="https://content-media.example/image.jpg">'),
            $this->b64('regular_media')
        );

        $result = $this->collectDomainsFromSql($sql);

        $this->assertSame(1, $result['statements']);
        $this->assertSame(['https://content-media.example'], $result['domains']);
    }

    public function testUnsupportedInsertShapeFallsBackToLexerScanner(): void
    {
        $sql = sprintf(
            "INSERT LOW_PRIORITY IGNORE INTO `shape_postmeta` (`meta_id`, `meta_value`) VALUES(1, FROM_BASE64('%s'));",
            $this->b64('https://fallback.example/path')
        );

        $result = $this->collectDomainsFromSql($sql);

        $this->assertSame(1, $result['statements']);
        $this->assertSame(['https://fallback.example'], $result['domains']);
    }
}
