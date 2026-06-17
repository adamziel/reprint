<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests \Reprint\Exporter\Site_Export_HTTP_Server::serve() — the one-call convenience
 * entry point that loads export.php, constructs the server, and
 * dispatches the current request.
 *
 * Tests run in subprocesses because serve() requires export.php, which
 * registers shutdown and error handlers at module level.
 */
final class HttpServerServeTest extends TestCase
{
    private const CLASS_PATH = __DIR__ . '/../packages/reprint-exporter/src/class-http-server.php';
    private const EXPORT_PATH = __DIR__ . '/../packages/reprint-exporter/src/export.php';

    public function testServeLoadsExportPhpWhenNotYetLoaded(): void
    {
        $script = <<<PHP
        \$_GET['endpoint'] = 'preflight';
        \$_GET['directory'] = sys_get_temp_dir();

        require '{$this->classPath()}';

        // REPRINT_EXPORTER_PROTOCOL_VERSION is defined by export.php; it must
        // not exist before serve() is called.
        if (defined('REPRINT_EXPORTER_PROTOCOL_VERSION')) {
            echo "FAIL: export runtime loaded before serve()";
            exit;
        }

        \Reprint\Exporter\Site_Export_HTTP_Server::serve();

        // serve() should have required export.php, which defines the protocol constant.
        echo defined('REPRINT_EXPORTER_PROTOCOL_VERSION') ? "OK" : "FAIL: export runtime not loaded";
        PHP;

        $output = $this->runScript($script);

        $this->assertStringContainsString('OK', $output);
        $this->assertStringNotContainsString('FAIL', $output);
    }

    public function testServeDoesNotRedundantlyReloadExportPhp(): void
    {
        $script = <<<PHP
        \$_GET['endpoint'] = 'preflight';
        \$_GET['directory'] = sys_get_temp_dir();

        require '{$this->classPath()}';
        require '{$this->exportPath()}';

        // Track require side-effect: if export.php was re-required, unguarded
        // top-level definitions would fail.
        \Reprint\Exporter\Site_Export_HTTP_Server::serve();
        echo "OK";
        PHP;

        $output = $this->runScript($script);
        $this->assertStringContainsString('OK', $output);
    }

    public function testServeForwardsOptionsToConstructor(): void
    {
        $script = <<<PHP
        \$_GET['endpoint'] = 'custom';

        require '{$this->classPath()}';

        // Override handlers to verify the options were passed through to the constructor.
        \Reprint\Exporter\Site_Export_HTTP_Server::serve([
            'handlers' => [
                'custom' => function (array \$config, \$budget): void {
                    echo "handler-called:" . \$config['endpoint'];
                },
            ],
        ]);
        PHP;

        $output = $this->runScript($script);
        $this->assertStringContainsString('handler-called:custom', $output);
    }

    private function classPath(): string
    {
        $path = realpath(self::CLASS_PATH);
        $this->assertNotFalse($path);
        return $path;
    }

    private function exportPath(): string
    {
        $path = realpath(self::EXPORT_PATH);
        $this->assertNotFalse($path);
        return $path;
    }

    private function runScript(string $body): string
    {
        $tmp_dir = sys_get_temp_dir() . '/serve-test-' . uniqid();
        mkdir($tmp_dir, 0755, true);

        try {
            $php_code = "<?php\n" . $body . "\n";
            file_put_contents($tmp_dir . '/run.php', $php_code);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(
                [PHP_BINARY, $tmp_dir . '/run.php'],
                $descriptors,
                $pipes,
                $tmp_dir
            );

            if (!is_resource($process)) {
                $this->fail('Failed to spawn PHP subprocess');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return ($stdout ?: '') . ($stderr ?: '');
        } finally {
            array_map('unlink', glob($tmp_dir . '/*') ?: []);
            rmdir($tmp_dir);
        }
    }
}
