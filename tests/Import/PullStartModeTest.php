<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Importer;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Application\PullRuntimeAdapter;
use Reprint\Importer\Pull\Pull;
use Reprint\Importer\TerminalProgress\TerminalProgress;

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

    private function makePull(): Pull
    {
        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);
        return new Pull(
            new PullRuntimeAdapter($client->context(), new ImportServices($client->context())),
            new TerminalProgress(false, STDOUT),
        );
    }

    /**
     * @dataProvider stageProvider
     */
    public function testStagesHonorStartRuntimeSelection(array $options, bool $expectsStart): void
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
            'start-runtime=none skips php-builtin start stage' => [
                ['runtime' => 'php-builtin', 'start_runtime' => 'none'],
                false,
            ],
            'start-runtime=none skips playground-cli start stage' => [
                ['runtime' => 'playground-cli', 'start_runtime' => 'none'],
                false,
            ],
            'explicit playground-cli start is supported' => [
                ['runtime' => 'playground-cli', 'start_runtime' => 'playground-cli'],
                true,
            ],
            'nginx-fpm never adds start stage' => [
                ['runtime' => 'nginx-fpm'],
                false,
            ],
        ];
    }

    public function testStartRuntimeCanSelectRuntimeWhenRuntimeIsOmitted(): void
    {
        $stages = $this->makePull()->stages([
            'start_runtime' => 'playground-cli',
        ]);

        $this->assertContains('apply-runtime', $stages);
        $this->assertContains('start', $stages);
    }

    public function testInvalidStartRuntimeValueIsRejectedBeforeNetworkIO(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid --start-runtime value: later. Valid runtimes: nginx-fpm, php-builtin, playground-cli, none'
        );

        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'start_runtime' => 'later',
        ]);
    }

    public function testUnsupportedStartRuntimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Starting runtime nginx-fpm is not supported yet. Supported start runtimes: php-builtin, playground-cli, none'
        );

        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'runtime' => 'nginx-fpm',
            'start_runtime' => 'nginx-fpm',
        ]);
    }

    public function testMismatchedStartRuntimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '--start-runtime=playground-cli requires matching --runtime=playground-cli, or omit --runtime to use playground-cli for both.'
        );

        $client = new Importer('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'runtime' => 'php-builtin',
            'start_runtime' => 'playground-cli',
        ]);
    }
}
