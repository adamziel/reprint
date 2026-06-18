<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Command\DbDomainsCommand;
use Reprint\Importer\Command\DbDomainsResult;
use Reprint\Importer\Command\FilesStatsCommand;
use Reprint\Importer\Command\FilesStatsResult;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Command\PreflightAssertCommand;
use Reprint\Importer\Command\PreflightAssertResult;
use Reprint\Importer\ImportClient;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Output\CliImportOutput;

require_once __DIR__ . '/../../importer/import.php';

class ImportCommandResultTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/import-command-result-' . uniqid();
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

    public function testFilesStatsCommandReturnsStructuredResultWithoutFormatting(): void
    {
        $client = new ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->state = [
            'fetch' => ['offset' => 0],
            'fetch_skipped' => ['offset' => 0],
        ];

        file_put_contents(
            $this->stateDir . '/.import-remote-index.jsonl',
            json_encode([
                'path' => base64_encode('/index.php'),
                'ctime' => 123,
                'size' => 42,
                'type' => 'file',
            ]) . "\n",
        );
        file_put_contents(
            $this->stateDir . '/.import-download-list.jsonl',
            json_encode(['path' => base64_encode('/index.php')]) . "\n",
        );

        ob_start();
        $result = (new FilesStatsCommand())->execute($client, []);
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertInstanceOf(ImportCommandResult::class, $result);
        $this->assertInstanceOf(FilesStatsResult::class, $result);
        $this->assertSame('files-stats', $result->type());
        $this->assertSame([
            'indexed' => ['files' => 1, 'bytes' => 42],
            'pending' => ['files' => 1, 'bytes' => 42],
        ], $result->stats());
    }

    public function testDbDomainsCommandReturnsCachedDomainsWithoutFormatting(): void
    {
        $client = new ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $domains = ['https://example.com', 'https://www.example.com'];
        file_put_contents(
            $this->stateDir . '/.import-domains.json',
            json_encode($domains),
        );

        ob_start();
        $result = (new DbDomainsCommand())->execute($client, []);
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertInstanceOf(ImportCommandResult::class, $result);
        $this->assertInstanceOf(DbDomainsResult::class, $result);
        $this->assertSame('db-domains', $result->type());
        $this->assertSame($domains, $result->domains());
    }

    public function testImportClientRunReturnsCommandResultWithoutFormatting(): void
    {
        $client = new ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);

        ob_start();
        $result = $client->run(['command' => 'files-stats']);
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertInstanceOf(FilesStatsResult::class, $result);
        $this->assertSame('files-stats', $result->type());
    }

    public function testImportClientCanReportThroughBufferedOutput(): void
    {
        $output = new BufferedImportOutput();
        $client = new ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot, $output);

        ob_start();
        $client->output_progress(['status' => 'starting', 'message' => 'Starting import'], true);
        $stdout = ob_get_clean();

        $this->assertSame('', $stdout);
        $this->assertSame([
            ['status' => 'starting', 'message' => 'Starting import'],
        ], $output->events());
    }

    public function testImportClientDefaultOutputIsSilent(): void
    {
        $client = new ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);

        ob_start();
        $client->output_progress(['status' => 'starting', 'message' => 'Starting import'], true);
        $stdout = ob_get_clean();

        $this->assertSame('', $stdout);
    }

    public function testCliOutputCanBeCapturedWithoutStdoutPollution(): void
    {
        $progress = fopen('php://temp', 'w+');
        $error = fopen('php://temp', 'w+');
        $output = new CliImportOutput($progress, false, $error);

        ob_start();
        $output->emit_event(['status' => 'starting', 'message' => 'Starting import'], true);
        $stdout = ob_get_clean();

        rewind($progress);
        $this->assertSame('', $stdout);
        $this->assertSame(
            json_encode(['status' => 'starting', 'message' => 'Starting import']) . "\n",
            stream_get_contents($progress),
        );
    }

    public function testPreflightAssertHandlesMalformedPreflightDataWithoutFormatting(): void
    {
        $client = new ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->state = [
            'preflight' => [
                'http_code' => 500,
                'data' => 'not-json',
            ],
        ];

        ob_start();
        $result = (new PreflightAssertCommand())->execute($client, []);
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertInstanceOf(PreflightAssertResult::class, $result);
        $this->assertFalse($result->all_pass());
        $checks = $result->checks();
        $this->assertSame('preflight not ok', $checks[1]['detail']);
        $this->assertSame('filesystem check failed', $checks[3]['detail']);
        $this->assertSame('database check failed', $checks[4]['detail']);
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
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }

        rmdir($dir);
    }
}
