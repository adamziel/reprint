<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Session\VolatileFileTracker;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class VolatileFileTrackerTest extends TestCase
{
    private string $file;
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'volatile-file-tracker-');
        @unlink($this->file);
        $this->audit = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testMissingAndInvalidFilesLoadAsEmpty(): void
    {
        $tracker = $this->make_tracker();

        $this->assertSame([], $tracker->load());

        file_put_contents($this->file, '{invalid');
        $this->assertSame([], $tracker->load());
    }

    public function testRecordIncrementsAndPersistsCount(): void
    {
        $tracker = $this->make_tracker();

        $this->assertSame(1, $tracker->record('/wp-content/a.txt'));
        $this->assertSame(2, $tracker->record('/wp-content/a.txt'));

        $this->assertSame([
            '/wp-content/a.txt' => 2,
        ], $tracker->load());
        $this->assertSame([
            'VOLATILE | path=/wp-content/a.txt | count=1',
            'VOLATILE | path=/wp-content/a.txt | count=2',
        ], $this->audit);
    }

    public function testClearRemovesEntryAndDeletesEmptyTrackerFile(): void
    {
        $tracker = $this->make_tracker();
        $tracker->record('/wp-content/a.txt');
        $tracker->record('/wp-content/b.txt');

        $this->assertTrue($tracker->clear('/wp-content/a.txt'));
        $this->assertSame([
            '/wp-content/b.txt' => 1,
        ], $tracker->load());
        $this->assertFileExists($this->file);

        $this->assertTrue($tracker->clear('/wp-content/b.txt'));
        $this->assertSame([], $tracker->load());
        $this->assertFileDoesNotExist($this->file);
    }

    public function testClearMissingPathIsNoop(): void
    {
        $tracker = $this->make_tracker();

        $this->assertFalse($tracker->clear('/missing.txt'));
        $this->assertSame([], $this->audit);
    }

    private function make_tracker(): VolatileFileTracker
    {
        return new VolatileFileTracker(
            $this->file,
            function (string $message): void {
                $this->audit[] = $message;
            },
        );
    }
}
