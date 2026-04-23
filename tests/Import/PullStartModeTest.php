<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

class PullStartModeTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-pull-start-' . uniqid('', true);
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

    private function makePull(): \Pull
    {
        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        return new \Pull($client, new \TerminalProgress(false, STDOUT));
    }

    /**
     * @dataProvider stageProvider
     */
    public function testStagesHonorStartMode(array $options, bool $expectsStart): void
    {
        $stages = $this->makePull()->stages($options);

        if ($expectsStart) {
            $this->assertContains('start', $stages);
        } else {
            $this->assertNotContains('start', $stages);
        }
    }

    public static function stageProvider(): array
    {
        return [
            'php-builtin defaults to auto start' => [
                ['runtime' => 'php-builtin'],
                true,
            ],
            'playground-cli defaults to auto start' => [
                ['runtime' => 'playground-cli'],
                true,
            ],
            'manual start skips php-builtin start stage' => [
                ['runtime' => 'php-builtin', 'start' => 'manual'],
                false,
            ],
            'manual start skips playground-cli start stage' => [
                ['runtime' => 'playground-cli', 'start' => 'manual'],
                false,
            ],
            'nginx-fpm never adds start stage' => [
                ['runtime' => 'nginx-fpm'],
                false,
            ],
        ];
    }

    public function testInvalidStartModeIsRejectedBeforeNetworkIO(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid --start value: later. Valid start modes: auto, manual');

        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'start' => 'later',
        ]);
    }
}
