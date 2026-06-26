<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FileSyncLocalApplier;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Observability\NullMachineEventEmitter;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Session\VolatileFileTracker;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class FileSyncLocalApplierSecurityTest extends TestCase
{
    private string $tempDir;
    private string $fsRoot;
    private string $stateDir;
    private array $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-sync-local-security-' . uniqid('', true);
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->stateDir = $this->tempDir . '/state';
        mkdir($this->fsRoot, 0755, true);
        mkdir($this->stateDir, 0755, true);
        $this->audit = [];
    }

    protected function tearDown(): void
    {
        $this->removePath($this->tempDir);
        parent::tearDown();
    }

    public function testRemoteErrorChunkWithDotSegmentPathDoesNotDeleteOutsideFsRoot(): void
    {
        $outside = $this->tempDir . '/escape.php';
        file_put_contents($outside, 'keep');

        $this->makeApplier()->handle_error_chunk(
            $this->errorChunk('/../escape.php'),
            'file_fetch',
            new StreamingContext(),
        );

        $this->assertSame('keep', file_get_contents($outside));
        $this->assertAuditContains('Security: refusing remote error path');
    }

    public function testRemoteErrorChunkDoesNotDeleteThroughSymlinkParent(): void
    {
        $outsideDir = $this->tempDir . '/outside';
        mkdir($outsideDir, 0755);
        $outside = $outsideDir . '/escape.php';
        file_put_contents($outside, 'keep');

        if (!@symlink($outsideDir, $this->fsRoot . '/linked')) {
            $this->markTestSkipped('Symlinks are not available on this filesystem.');
        }

        $this->makeApplier()->handle_error_chunk(
            $this->errorChunk('/linked/escape.php'),
            'file_fetch',
            new StreamingContext(),
        );

        $this->assertSame('keep', file_get_contents($outside));
        $this->assertAuditContains('Security: refusing remote error path');
    }

    private function makeApplier(): FileSyncLocalApplier
    {
        $audit = new FileSyncLocalApplierSecurityTestAuditLogger($this->audit);

        return new FileSyncLocalApplier(
            new LocalImportFilesystem(
                $this->fsRoot,
                'error',
                $audit,
            ),
            new IndexStore(
                $this->stateDir . '/.import-index.jsonl',
                $this->stateDir . '/.import-index-updates.jsonl',
            ),
            new VolatileFileTracker($this->stateDir . '/.import-volatile-files.json'),
            new BufferedImportOutput(),
            $this->fsRoot,
            $this->stateDir . '/.import-remote-index.jsonl',
            'error',
            true,
            0,
            null,
            null,
            FilesPullCheckpoint::fresh(),
            $audit,
            new NullMachineEventEmitter(),
        );
    }

    private function errorChunk(string $path): array
    {
        return [
            'body' => json_encode([
                'error_type' => 'file_read',
                'path' => $path,
                'message' => 'remote read failed',
            ]),
        ];
    }

    private function assertAuditContains(string $needle): void
    {
        $this->assertStringContainsString($needle, implode("\n", $this->audit));
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

final class FileSyncLocalApplierSecurityTestAuditLogger implements AuditLogger
{
    private $messages;

    public function __construct(array &$messages)
    {
        $this->messages =& $messages;
    }

    public function record(string $message, bool $to_console = true): void
    {
        $this->messages[] = $message;
    }

    public function path(): string
    {
        return '';
    }
}
