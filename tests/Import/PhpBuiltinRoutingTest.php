<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Host\RuntimeManifest;
use Reprint\Importer\TargetRuntime\PhpBuiltinApplier;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/bootstrap.php';

class PhpBuiltinRoutingTest extends TestCase
{
    private string $tempDir;
    private string $fsRoot;
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/php-builtin-routing-' . uniqid();
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->outputDir = $this->tempDir . '/runtime';

        mkdir($this->fsRoot . '/__wp__/wp-admin', 0755, true);
        mkdir($this->outputDir, 0755, true);
        file_put_contents($this->fsRoot . '/__wp__/index.php', "<?php echo 'fallback';\n");
        file_put_contents($this->fsRoot . '/__wp__/wp-admin/install.php', "<?php echo 'install';\n");
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testRoutesCorePhpFilesThroughWpDir(): void
    {
        $manifest = new RuntimeManifest('wpcloud');
        $manifest->server_vars['WP_DIR'] = '{fs-root}/__wp__/';

        (new PhpBuiltinApplier())->apply($manifest, $this->fsRoot, $this->outputDir, [
            'wordpress_index' => $this->fsRoot . '/__wp__/index.php',
        ]);

        $originalServer = $_SERVER;
        $_SERVER['DOCUMENT_ROOT'] = $this->fsRoot;
        $_SERVER['REQUEST_URI'] = '/wp-admin/install.php';
        $bufferLevel = ob_get_level();

        ob_start();
        try {
            include $this->outputDir . '/runtime.php';
            $output = ob_get_clean();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            $_SERVER = $originalServer;
        }

        $this->assertSame('install', $output);
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
