<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Command\DbDomainsResult;
use Reprint\Importer\Command\FilesStatsResult;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Command\PreflightAssertResult;
use Reprint\Importer\Importer;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Application\UseCase\PreflightAssertHandler;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Output\CliImportOutput;
use Reprint\Importer\Session\PreflightCheckpoint;

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
        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);

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
        $result = $client->run(['command' => 'files-stats']);
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
        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);
        $domains = ['https://example.com', 'https://www.example.com'];
        file_put_contents(
            $this->stateDir . '/.import-domains.json',
            json_encode($domains),
        );

        ob_start();
        $result = $client->run(['command' => 'db-domains']);
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertInstanceOf(ImportCommandResult::class, $result);
        $this->assertInstanceOf(DbDomainsResult::class, $result);
        $this->assertSame('db-domains', $result->type());
        $this->assertSame($domains, $result->domains());
    }

    public function testImporterRunReturnsCommandResultWithoutFormatting(): void
    {
        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);

        ob_start();
        $result = $client->run(['command' => 'files-stats']);
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertInstanceOf(FilesStatsResult::class, $result);
        $this->assertSame('files-stats', $result->type());
    }

    public function testImporterConstructorDoesNotCreateRuntimeDirectories(): void
    {
        $stateDir = $this->tempDir . '/lazy-state';
        $fsRoot = $this->tempDir . '/lazy-fs-root';

        new Importer('http://example.invalid', $stateDir, $fsRoot);

        $this->assertDirectoryDoesNotExist($stateDir);
        $this->assertDirectoryDoesNotExist($fsRoot);
    }

    public function testImporterRunCreatesRuntimeDirectoriesWhenNeeded(): void
    {
        $stateDir = $this->tempDir . '/runtime-state';
        $fsRoot = $this->tempDir . '/runtime-fs-root';
        $client = new Importer('http://example.invalid', $stateDir, $fsRoot);

        $result = $client->run(['command' => 'files-stats']);

        $this->assertInstanceOf(FilesStatsResult::class, $result);
        $this->assertDirectoryExists($stateDir);
        $this->assertDirectoryExists($fsRoot);
    }

    public function testImporterRunRestoresSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('pcntl signal handler inspection is unavailable.');
        }

        $previousIntHandler = pcntl_signal_get_handler(SIGINT);
        $previousTermHandler = pcntl_signal_get_handler(SIGTERM);
        $intHandler = static function (): void {
        };
        $termHandler = static function (): void {
        };

        pcntl_signal(SIGINT, $intHandler);
        pcntl_signal(SIGTERM, $termHandler);

        try {
            $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);
            $client->run(['command' => 'files-stats']);

            $this->assertSame($intHandler, pcntl_signal_get_handler(SIGINT));
            $this->assertSame($termHandler, pcntl_signal_get_handler(SIGTERM));
        } finally {
            pcntl_signal(SIGINT, $previousIntHandler);
            pcntl_signal(SIGTERM, $previousTermHandler);
        }
    }

    public function testImporterCanReportThroughBufferedOutput(): void
    {
        $output = new BufferedImportOutput();
        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot, $output);

        ob_start();
        $client->output_progress(['status' => 'starting', 'message' => 'Starting import'], true);
        $stdout = ob_get_clean();

        $this->assertSame('', $stdout);
        $this->assertSame([
            ['status' => 'starting', 'message' => 'Starting import'],
        ], $output->events());
    }

    public function testImporterDefaultOutputIsSilent(): void
    {
        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);

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
        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->save_preflight_checkpoint(new PreflightCheckpoint(
            [
                'http_code' => 500,
                'data' => 'not-json',
            ],
        ));

        ob_start();
        $result = (new PreflightAssertHandler())->execute(
            $client->context(),
            new ImportServices($client->context()),
            [],
        );
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
