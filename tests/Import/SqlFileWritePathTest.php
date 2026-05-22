<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class SqlFileWritePathTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sql-file-write-path-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function writeState(array $state): void
    {
        $defaults = [
            'command' => 'db-pull',
            'status' => 'in_progress',
            'cursor' => null,
            'stage' => 'sql',
            'preflight' => ['data' => ['ok' => true], 'http_code' => 200],
            'remote_protocol_version' => null,
            'remote_protocol_min_version' => null,
            'version' => null,
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'preserve-local',
            'max_allowed_packet' => null,
            'db_index' => [
                'file' => $this->stateDir . '/db-tables.jsonl',
                'tables' => 1,
                'rows_estimated' => 1,
                'bytes' => 1,
                'updated_at' => time(),
            ],
        ];

        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT) . "\n",
        );
    }

    private function prepareClient(array $chunks, ?int $failAfterChunks = null): array
    {
        $client = new SqlFileWritePathClient(
            'https://source.example/export',
            $this->stateDir,
            $this->fsRoot,
        );
        $client->sqlChunks = $chunks;
        $client->failAfterChunks = $failAfterChunks;

        $reflection = new \ReflectionClass(\ImportClient::class);

        $stateProperty = $reflection->getProperty('state');
        $loadState = $reflection->getMethod('load_state');
        $stateProperty->setValue($client, $loadState->invoke($client));

        $ttyProperty = $reflection->getProperty('is_tty');
        $ttyProperty->setValue($client, false);

        $modeProperty = $reflection->getProperty('sql_output_mode');
        $modeProperty->setValue($client, 'file');

        return [$client, $reflection];
    }

    public function testSqlFileOutputPreservesExactBytesAndMetadata(): void
    {
        $this->writeState([]);
        file_put_contents($this->stateDir . '/db.sql', "STALE SQL THAT MUST BE TRUNCATED\n");

        $sql = [
            "CREATE TABLE `wp_options` (`option_id` bigint unsigned NOT NULL, `option_name` varchar(191), `option_value` longtext);\n",
            "INSERT INTO `wp_options` VALUES (1,'siteurl',FROM_BASE64('aHR0cHM6Ly9leGFtcGxlLmNvbS9wYXRo'));\n",
            "INSERT INTO `wp_options` VALUES (2,'plain',FROM_BASE64('bm8tdXJs'));\n",
        ];

        [$client, $reflection] = $this->prepareClient([
            ['body' => $sql[0], 'cursor' => 'cursor-create'],
            ['body' => $sql[1], 'cursor' => 'cursor-siteurl'],
            ['body' => $sql[2], 'cursor' => 'cursor-plain'],
        ]);

        $downloadSql = $reflection->getMethod('download_sql');
        $downloadSql->invoke($client);

        $this->assertSame(
            implode('', $sql),
            file_get_contents($this->stateDir . '/db.sql'),
        );

        $stats = json_decode(
            file_get_contents($this->stateDir . '/.import-sql-stats.json'),
            true,
        );
        $this->assertSame(3, $stats['statements_total']);

        $domains = json_decode(
            file_get_contents($this->stateDir . '/.import-domains.json'),
            true,
        );
        $this->assertContains('https://example.com', $domains);
        $this->assertContains('https://source.example', $domains);
    }

    public function testResumeTruncatesUncheckpointedBytesBeforeAppending(): void
    {
        $prefix = "CREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL, `post_title` text);\n";
        $suffix = "INSERT INTO `wp_posts` VALUES (1,FROM_BASE64('aGVsbG8gd29ybGQ='));\n";

        $this->writeState([
            'cursor' => 'cursor-after-prefix',
            'sql_bytes' => strlen($prefix),
            'sql_statements_counted' => 1,
        ]);
        file_put_contents($this->stateDir . '/db.sql', $prefix . "UNCOMMITTED-TAIL");

        [$client, $reflection] = $this->prepareClient([
            ['body' => $suffix, 'cursor' => 'cursor-after-suffix'],
        ]);

        $downloadSql = $reflection->getMethod('download_sql');
        $downloadSql->invoke($client);

        $this->assertSame(
            $prefix . $suffix,
            file_get_contents($this->stateDir . '/db.sql'),
        );

        $stats = json_decode(
            file_get_contents($this->stateDir . '/.import-sql-stats.json'),
            true,
        );
        $this->assertSame(2, $stats['statements_total']);
    }

    public function testResumeRefusesSqlFileShorterThanCheckpoint(): void
    {
        $prefix = "CREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL, `post_title` text);\n";

        $this->writeState([
            'cursor' => 'cursor-after-prefix',
            'sql_bytes' => strlen($prefix),
            'sql_statements_counted' => 1,
        ]);
        file_put_contents($this->stateDir . '/db.sql', substr($prefix, 0, 12));

        [$client, $reflection] = $this->prepareClient([
            ['body' => "INSERT INTO `wp_posts` VALUES (1,FROM_BASE64('aGVsbG8='));\n", 'cursor' => 'cursor-after-suffix'],
        ]);

        $downloadSql = $reflection->getMethod('download_sql');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db.sql is smaller than the saved checkpoint');
        $downloadSql->invoke($client);
    }

    public function testRetryablePartialResponseFlushesCheckpointedBytesForResume(): void
    {
        $first = "CREATE TABLE `wp_comments` (`comment_ID` bigint unsigned NOT NULL);\n";
        $second = "INSERT INTO `wp_comments` VALUES (1);\n";

        $this->writeState([]);

        [$firstClient, $firstReflection] = $this->prepareClient([
            ['body' => $first, 'cursor' => 'cursor-after-first'],
            ['body' => $second, 'cursor' => 'cursor-after-second'],
        ], 1);

        $downloadSql = $firstReflection->getMethod('download_sql');
        $downloadSql->invoke($firstClient);

        $this->assertSame($first, file_get_contents($this->stateDir . '/db.sql'));

        $state = json_decode(
            file_get_contents($this->stateDir . '/.import-state.json'),
            true,
        );
        $this->assertSame('partial', $state['status']);
        $this->assertSame(strlen($first), $state['sql_bytes']);
        $this->assertSame(1, $state['sql_statements_counted']);

        [$secondClient, $secondReflection] = $this->prepareClient([
            ['body' => $second, 'cursor' => 'cursor-after-second'],
        ]);

        $downloadSql = $secondReflection->getMethod('download_sql');
        $downloadSql->invoke($secondClient);

        $this->assertSame($first . $second, file_get_contents($this->stateDir . '/db.sql'));
    }
}

class SqlFileWritePathClient extends \ImportClient
{
    /** @var array<int, array{body: string, cursor: string, query_complete?: bool}> */
    public array $sqlChunks = [];

    /** @var int|null */
    public ?int $failAfterChunks = null;

    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        \StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        foreach ($this->sqlChunks as $index => $chunk) {
            ($context->on_chunk)([
                'headers' => [
                    'x-chunk-type' => 'sql',
                    'x-query-complete' => ($chunk['query_complete'] ?? true) ? '1' : '0',
                    'x-cursor' => $chunk['cursor'],
                ],
                'body' => $chunk['body'],
            ]);

            if ($this->failAfterChunks !== null && $index + 1 >= $this->failAfterChunks) {
                throw new \RuntimeException('missing completion chunk');
            }
        }

        ($context->on_chunk)([
            'headers' => [
                'x-chunk-type' => 'completion',
                'x-status' => 'complete',
                'x-batches-processed' => (string) count($this->sqlChunks),
            ],
            'body' => '',
        ]);
    }
}
