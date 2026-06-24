<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Observability\FileAuditLogger;
use Reprint\Importer\Output\BufferedImportOutput;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/bootstrap.php';

class FileAuditLoggerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/file-audit-logger-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testCreatesMissingAuditLogDirectory(): void
    {
        $path = $this->tempDir . '/fresh/state/.import-audit.log';
        $logger = new FileAuditLogger($path, new BufferedImportOutput());

        $logger->record('COMMAND | pull', false);

        $this->assertFileExists($path);
        $this->assertStringContainsString('COMMAND | pull', file_get_contents($path));
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->recursiveDelete($path . '/' . $item);
        }

        rmdir($path);
    }
}
