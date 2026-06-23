<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class ImporterFatalRegressionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/importer-fatal-regression-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testCliSizeOptionUsesLoadedExporterParser(): void
    {
        $stateDir = $this->tempDir . '/state';
        $fsRoot = $this->tempDir . '/fs-root';
        mkdir($stateDir, 0755, true);
        mkdir($fsRoot, 0755, true);

        $command = [
            PHP_BINARY,
            __DIR__ . '/../../packages/reprint-importer/bin/reprint-importer',
            'db-pull',
            '-',
            '--state-dir=' . $stateDir,
            '--fs-root=' . $fsRoot,
            '--max-allowed-packet=64M',
        ];
        $result = $this->runCommand($command);

        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('No preflight data found', $result['stderr']);
        $this->assertStringNotContainsString('undefined function parse_size', $result['stderr']);
    }

    public function testGlobalHostDetectorIsLoadedForPreflight(): void
    {
        $this->assertTrue(function_exists('detect_host'));
        $this->assertSame('other', \detect_host([]));
    }

    /**
     * @param array<int, string> $command
     * @return array{exit_code:int, stdout:string, stderr:string}
     */
    private function runCommand(array $command): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            $this->fail('Failed to start subprocess');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->recursiveDelete($path . '/' . $item);
        }
        @rmdir($path);
    }
}
