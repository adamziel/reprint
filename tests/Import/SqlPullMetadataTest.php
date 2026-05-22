<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class SqlPullMetadataTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sql-pull-metadata-test-' . uniqid();
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

    public function testCompleteMetadataRoundTripsWithValidatedFingerprint(): void
    {
        $sqlFile = $this->writeSql("SELECT 1;\nSELECT 2;\n");

        \SqlPullMetadata::write_complete(
            \SqlPullMetadata::path($this->stateDir),
            $sqlFile,
            ['https://Example.test:8443', 'http://alpha.test'],
            2,
        );

        $reason = null;
        $metadata = \SqlPullMetadata::read_complete(
            \SqlPullMetadata::path($this->stateDir),
            $sqlFile,
            $reason,
        );

        $this->assertSame([
            'http://alpha.test',
            'https://example.test:8443',
        ], $metadata['domains'] ?? null);
        $this->assertSame(2, $metadata['statements_total'] ?? null);
        $this->assertNull($reason);
    }

    public function testAbsentAndStaleMetadataAreIgnored(): void
    {
        $sqlFile = $this->writeSql("SELECT 1;\n");

        $reason = null;
        $this->assertNull(\SqlPullMetadata::read_complete(
            \SqlPullMetadata::path($this->stateDir),
            $sqlFile,
            $reason,
        ));
        $this->assertSame('metadata file missing', $reason);

        \SqlPullMetadata::write_complete(
            \SqlPullMetadata::path($this->stateDir),
            $sqlFile,
            ['https://fresh.example'],
            1,
        );

        // Same byte size as "SELECT 1;\n", forcing validation to rely on the hash.
        file_put_contents($sqlFile, "SELECT 2;\n");

        $reason = null;
        $this->assertNull(\SqlPullMetadata::read_complete(
            \SqlPullMetadata::path($this->stateDir),
            $sqlFile,
            $reason,
        ));
        $this->assertSame('sql file hash mismatch', $reason);
    }

    public function testAdversarialMetadataValidationRejectsUnsafeShapes(): void
    {
        $sqlFile = $this->writeSql("SELECT 1;\n");
        $metadataFile = \SqlPullMetadata::path($this->stateDir);
        \SqlPullMetadata::write_complete($metadataFile, $sqlFile, ['https://valid.example'], 1);
        $valid = json_decode((string) file_get_contents($metadataFile), true);

        $cases = [
            'bad version' => function (array $payload): array {
                $payload['version'] = 999;
                return $payload;
            },
            'domain with path' => function (array $payload): array {
                $payload['domains']['origins'] = ['https://valid.example/path'];
                return $payload;
            },
            'string statement count' => function (array $payload): array {
                $payload['statements']['total'] = '1';
                return $payload;
            },
            'negative statement count' => function (array $payload): array {
                $payload['statements']['total'] = -1;
                return $payload;
            },
        ];

        foreach ($cases as $label => $mutate) {
            file_put_contents($metadataFile, json_encode($mutate($valid)) . "\n");
            $reason = null;
            $this->assertNull(
                \SqlPullMetadata::read_complete($metadataFile, $sqlFile, $reason),
                $label,
            );
            $this->assertNotNull($reason, $label);
        }
    }

    public function testDbDomainsFallsBackToStructuredSqlScanWhenMetadataIsStale(): void
    {
        $sqlFile = $this->writeSql("SELECT 1;\n");
        \SqlPullMetadata::write_complete(
            \SqlPullMetadata::path($this->stateDir),
            $sqlFile,
            ['https://stale.example'],
            1,
        );

        file_put_contents($sqlFile, $this->adversarialSqlFixture());

        $client = $this->makeClient();
        $method = (new \ReflectionClass($client))->getMethod('run_db_domains');
        $method->setAccessible(true);

        ob_start();
        try {
            $method->invoke($client);
            $output = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $domains = array_values(array_filter(explode("\n", trim($output))));
        sort($domains);
        $this->assertSame([
            'https://cdn.example.test',
            'https://fresh.example.test',
        ], $domains);
        $this->assertNotContains('https://stale.example', $domains);
        $this->assertNotContains('https://external.example.test', $domains);
        $this->assertNotContains('https://raw-sql-string.example.test', $domains);
        $this->assertNotContains('https://updated.example.test', $domains);

        $reason = null;
        $metadata = \SqlPullMetadata::read_complete(
            \SqlPullMetadata::path($this->stateDir),
            $sqlFile,
            $reason,
        );
        $this->assertSame(5, $metadata['statements_total'] ?? null);
        $this->assertSame($domains, $metadata['domains'] ?? null);
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient(
            'https://source.example.test/?site-export-api',
            $this->stateDir,
            $this->fsRoot,
        );
    }

    private function writeSql(string $sql): string
    {
        $sqlFile = $this->stateDir . '/db.sql';
        file_put_contents($sqlFile, $sql);
        return $sqlFile;
    }

    private function adversarialSqlFixture(): string
    {
        $siteUrl = base64_encode('https://fresh.example.test');
        $cdnMarkup = base64_encode(
            '<a href="https://external.example.test/page">external</a>' .
            '<img src="https://cdn.example.test/image.jpg">'
        );
        $updated = base64_encode('https://updated.example.test');

        return implode("\n", [
            'SET NAMES utf8mb4;',
            sprintf(
                "INSERT INTO `wp_options` (`option_id`,`option_name`,`option_value`,`autoload`) VALUES " .
                "(1, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'));",
                base64_encode('siteurl'),
                $siteUrl,
                base64_encode('yes'),
            ),
            sprintf(
                "INSERT INTO `wp_posts` (`ID`,`post_content`) VALUES " .
                "(1, FROM_BASE64('%s'));",
                $cdnMarkup,
            ),
            sprintf(
                "UPDATE `wp_options` SET `option_value` = FROM_BASE64('%s') WHERE `option_name` = 'home';",
                $updated,
            ),
            "INSERT INTO `wp_posts` (`ID`,`post_content`) VALUES (2, 'https://raw-sql-string.example.test');",
        ]) . "\n";
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
}
