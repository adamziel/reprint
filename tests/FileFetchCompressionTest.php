<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FileFetchCompressionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-fetch-compression-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testFileFetchStreamsIdentityEncodedMultipart(): void
    {
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/hello.txt';
        file_put_contents($filePath, 'file-fetch-body');

        $listPath = $this->tempDir . '/file-list.json';
        file_put_contents($listPath, json_encode([$filePath], JSON_THROW_ON_ERROR));

        $stdout = $this->runFileFetch([
            'directory' => $siteDir,
            'file_list_path' => $listPath,
        ]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'file_fetch output should not be gzip framed');
        $this->assertStringContainsString('file-fetch-body', $stdout);
    }

    private function runFileFetch(array $config): string
    {
        $configPath = $this->tempDir . '/config.json';
        file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR));

        $scriptPath = $this->tempDir . '/run-file-fetch.php';
        file_put_contents(
            $scriptPath,
            sprintf(
                <<<'PHP'
<?php
declare(strict_types=1);
require_once %s;
$config = json_decode(file_get_contents(%s), true, 512, JSON_THROW_ON_ERROR);
$budget = new ResourceBudget(microtime(true), 10, 128 * 1024 * 1024, 0.9);
endpoint_file_fetch($config, $budget);
PHP,
                var_export(dirname(__DIR__) . '/packages/reprint-exporter/src/export.php', true),
                var_export($configPath, true),
            ),
        );

        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath));
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "file_fetch should exit cleanly.\nstderr: {$stderr}");

        return $stdout;
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
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
