<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\ImportStateLock;
use Reprint\Importer\Session\RuntimeLifecycle;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class RuntimeLifecycleLockTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/runtime-lifecycle-lock-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testPrepareAcquiresStateLockAndWritesOwnerMetadata(): void
    {
        $stateDir = $this->tempDir . '/state';
        $fsRoot = $this->tempDir . '/fs-root';
        $paths = new ImportPaths($stateDir);
        $lifecycle = $this->makeLifecycle($stateDir, $fsRoot);

        $lifecycle->prepare();

        try {
            $this->assertFileExists($paths->state_lock_file());
            $metadata = json_decode((string) file_get_contents($paths->state_lock_file()), true);

            $this->assertIsArray($metadata);
            $this->assertSame(getmypid(), $metadata['pid']);
            $this->assertIsString($metadata['started_at']);
            $this->assertSame($paths->state_lock_file(), $metadata['lock_file']);
        } finally {
            $lifecycle->cleanup();
        }
    }

    public function testSecondLifecycleCannotPrepareWhileLockIsHeld(): void
    {
        $stateDir = $this->tempDir . '/state';
        $fsRoot = $this->tempDir . '/fs-root';
        $first = $this->makeLifecycle($stateDir, $fsRoot);
        $second = $this->makeLifecycle($stateDir, $fsRoot);

        $first->prepare();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Another importer process is already using this state directory.');

            $second->prepare();
        } finally {
            $first->cleanup();
        }
    }

    public function testCleanupReleasesLockForNextLifecycle(): void
    {
        $stateDir = $this->tempDir . '/state';
        $fsRoot = $this->tempDir . '/fs-root';
        $first = $this->makeLifecycle($stateDir, $fsRoot);
        $second = $this->makeLifecycle($stateDir, $fsRoot);

        $first->prepare();
        $first->cleanup();

        $second->prepare();

        try {
            $this->assertFileExists((new ImportPaths($stateDir))->state_lock_file());
        } finally {
            $second->cleanup();
        }
    }

    private function makeLifecycle(string $stateDir, string $fsRoot): RuntimeLifecycle
    {
        $paths = new ImportPaths($stateDir);

        return new RuntimeLifecycle(
            $stateDir,
            $fsRoot,
            function (): void {
            },
            new ImportStateLock($paths->state_lock_file()),
        );
    }

    private function recursiveDelete(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
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
