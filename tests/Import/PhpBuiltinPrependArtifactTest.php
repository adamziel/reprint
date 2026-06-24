<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-importer/src/lib/target-runtime/load.php';

/**
 * The php-builtin applier writes two runtime artifacts:
 *
 *   - runtime.php          — the standalone `php -S` router (base layers +
 *                            CLI-server routing tail that dispatches WP).
 *   - runtime.prepend.php  — base layers only (constants + SQLite $wpdb shim
 *                            + uploads proxy), for hosts that own request
 *                            dispatch and inject it via auto_prepend_file.
 *
 * The routing tail must never leak into the prepend artifact: dispatching
 * WordPress during the prepend phase bypasses the SQLite shim and falls back
 * to MySQL ("Error establishing a database connection").
 */
class PhpBuiltinPrependArtifactTest extends TestCase
{
    /** Marker the routing tail emits as its first line. */
    private const ROUTING_MARKER = '// CLI-server routing';

    private string $tempDir;
    private string $docRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/php-builtin-prepend-' . uniqid('', true);
        $this->docRoot = $this->tempDir . '/docroot';
        mkdir($this->docRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    /**
     * Apply the php-builtin runtime into a fresh output dir and return it.
     *
     * @param array{plugin_source: string, plugin_dir: string, db_dir: string, db_file: string}|null $sqlite
     */
    private function applyRuntime(string $subdir, ?array $sqlite = null): string
    {
        $outputDir = $this->tempDir . '/' . $subdir;

        $manifest = new \RuntimeManifest('other');
        $manifest->sqlite = $sqlite;

        $applier = new \PhpBuiltinApplier();
        $applier->apply($manifest, $this->docRoot, $outputDir, [
            'wordpress_index' => $this->docRoot . '/index.php',
        ]);

        return $outputDir;
    }

    public function testApplyWritesBothRuntimeAndPrependFiles(): void
    {
        $outputDir = $this->applyRuntime('runtime');

        $this->assertFileExists($outputDir . '/runtime.php');
        $this->assertFileExists($outputDir . '/runtime.prepend.php');
    }

    public function testRuntimePhpEndsWithRoutingTail(): void
    {
        $outputDir = $this->applyRuntime('runtime');

        $runtime = file_get_contents($outputDir . '/runtime.php');
        $this->assertStringContainsString(self::ROUTING_MARKER, $runtime);
    }

    public function testPrependFileOmitsRoutingTailButKeepsBaseLayers(): void
    {
        $outputDir = $this->applyRuntime('runtime');

        $prepend = file_get_contents($outputDir . '/runtime.prepend.php');
        $runtime = file_get_contents($outputDir . '/runtime.php');

        // No dispatch tail in the prepend artifact.
        $this->assertStringNotContainsString(self::ROUTING_MARKER, $prepend);

        // The prepend artifact is exactly the base prefix of runtime.php —
        // runtime.php is the prepend plus the appended routing tail.
        $this->assertStringStartsWith($prepend, $runtime);
        $this->assertNotSame($prepend, $runtime);
    }

    public function testBothArtifactsAreSyntacticallyValid(): void
    {
        $outputDir = $this->applyRuntime('runtime', $this->fakeSqliteConfig());

        foreach (['runtime.php', 'runtime.prepend.php'] as $name) {
            $path = $outputDir . '/' . $name;
            $output = [];
            $code = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
            $this->assertSame(0, $code, "php -l failed for {$name}:\n" . implode("\n", $output));
        }
    }

    /**
     * The behavioral guarantee: inject runtime.prepend.php as auto_prepend_file
     * ahead of an external script (exactly how Studio's native php -S router
     * consumes it). The prepend must install the SQLite $wpdb proxy and must
     * NOT dispatch WordPress, so $GLOBALS['wpdb'] resolves to the SQLite loader
     * — WordPress would see $wpdb already set and never reach MySQL.
     */
    public function testPrependInstallsSqliteProxyWithoutDispatch(): void
    {
        $outputDir = $this->applyRuntime('runtime', $this->fakeSqliteConfig());
        $prependPath = $outputDir . '/runtime.prepend.php';

        // A trivial "host-owned" script that runs after the prepend and reports
        // which $wpdb the prepend installed. It never touches the database, so
        // the lazy SQLite integration plugin is never required.
        $probe = $outputDir . '/probe.php';
        file_put_contents(
            $probe,
            "<?php\n" .
            "echo 'WPDB_CLASS=' . (isset(\$GLOBALS['wpdb']) ? get_class(\$GLOBALS['wpdb']) : 'NONE') . \"\\n\";\n",
        );

        $cmd = escapeshellarg(PHP_BINARY)
            . ' -d auto_prepend_file=' . escapeshellarg($prependPath)
            . ' ' . escapeshellarg($probe)
            . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $joined = implode("\n", $output);

        $this->assertSame(0, $code, "prepend run failed:\n" . $joined);
        $this->assertStringContainsString('WPDB_CLASS=Streaming_SQLite_Loader', $joined);
        $this->assertStringNotContainsString('Error establishing a database connection', $joined);
        $this->assertStringNotContainsString('Fatal error', $joined);
    }

    /**
     * SQLite config whose paths are never resolved by these tests — the proxy
     * loads the integration plugin lazily, only on the first real DB call.
     *
     * @return array{plugin_source: string, plugin_dir: string, db_dir: string, db_file: string}
     */
    private function fakeSqliteConfig(): array
    {
        return [
            'plugin_source' => $this->tempDir . '/sqlite-source',
            'plugin_dir' => $this->tempDir . '/sqlite-plugin',
            'db_dir' => $this->tempDir . '/db',
            'db_file' => 'wp.sqlite',
        ];
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
                continue;
            }

            if (is_dir($path)) {
                $this->recursiveDelete($path);
            }
        }

        rmdir($dir);
    }
}
