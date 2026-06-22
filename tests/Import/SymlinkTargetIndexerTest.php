<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\RemoteFileIndexGateway;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\FileSync\Port\SymlinkTargetObserver;
use Reprint\Importer\FileSync\SymlinkTargetIndexer;
use Reprint\Importer\Observability\AuditLogger;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class SymlinkTargetIndexerTest extends TestCase
{
    private string $temp_dir;
    public string $remote_index_file;
    public array $downloaded = [];
    public array $saved_states = [];
    public array $audit = [];
    public array $lifecycle = [];
    public array $progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/symlink-target-indexer-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->remote_index_file = $this->temp_dir . '/remote-index.jsonl';
        $this->downloaded = [];
        $this->saved_states = [];
        $this->audit = [];
        $this->lifecycle = [];
        $this->progress = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->remote_index_file);
        @rmdir($this->temp_dir);
        parent::tearDown();
    }

    public function testIndexesDiscoveredSymlinkTargets(): void
    {
        $this->append_link('/srv/root/link', '/srv/external');
        $this->append_link('/srv/root/covered', '/srv/root/already-covered');
        $this->append_link('/srv/root/intermediate', '/srv/intermediate', true);

        $checkpoint = FilesPullCheckpoint::fresh();
        $checkpoint->index_cursor = 'existing-cursor';

        $this->make_indexer()->discover($checkpoint, ['/srv/root']);

        $this->assertSame(['/srv/external'], $this->downloaded);
        $this->assertNull($checkpoint->index_cursor);
        $this->assertNotEmpty($this->saved_states);
        $this->assertSame('symlink_follow', $this->progress[0]['type']);
        $this->assertStringContainsString('/srv/external', $this->lifecycle[0]);
    }

    public function testServerRejectedTargetIsSkipped(): void
    {
        $this->append_link('/srv/root/rejected', '/srv/rejected');
        $checkpoint = FilesPullCheckpoint::fresh();

        $this->make_indexer([
            '/srv/rejected' => new RuntimeException('dir_outside_root'),
        ])->discover($checkpoint, ['/srv/root']);

        $this->assertSame(['/srv/rejected'], $this->downloaded);
        $this->assertSame('symlink_follow_rejected', $this->progress[1]['type']);
        $this->assertStringContainsString(
            'server rejected /srv/rejected',
            implode("\n", array_column($this->audit, 0)),
        );
    }

    /**
     * @param array<string, RuntimeException> $errors
     */
    private function make_indexer(array $errors = []): SymlinkTargetIndexer
    {
        return new SymlinkTargetIndexer(
            $this->remote_index_file,
            new SymlinkTargetIndexerTestRemoteIndex($this, $errors),
            new SymlinkTargetIndexerTestCheckpointStore($this),
            new SymlinkTargetIndexerTestShutdownToken(),
            new SymlinkTargetIndexerTestAuditLogger($this),
            new SymlinkTargetIndexerTestObserver($this),
        );
    }

    private function append_link(
        string $path,
        string $target,
        bool $intermediate = false
    ): void {
        file_put_contents(
            $this->remote_index_file,
            json_encode([
                'type' => 'link',
                'path' => base64_encode($path),
                'target' => base64_encode($target),
                'intermediate' => $intermediate,
            ], JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND,
        );
    }
}

final class SymlinkTargetIndexerTestRemoteIndex implements RemoteFileIndexGateway
{
    private SymlinkTargetIndexerTest $test;
    private array $errors;

    public function __construct(SymlinkTargetIndexerTest $test, array $errors)
    {
        $this->test = $test;
        $this->errors = $errors;
    }

    public function download(FilesPullCheckpoint $checkpoint, ?string $list_dir_override = null): bool
    {
        $directory = (string) $list_dir_override;
        $this->test->downloaded[] = $directory;
        if (isset($this->errors[$directory])) {
            throw $this->errors[$directory];
        }
        return true;
    }
}

final class SymlinkTargetIndexerTestCheckpointStore implements FilesPullCheckpointStore
{
    private SymlinkTargetIndexerTest $test;

    public function __construct(SymlinkTargetIndexerTest $test)
    {
        $this->test = $test;
    }

    public function get(): FilesPullCheckpoint
    {
        return FilesPullCheckpoint::fresh();
    }

    public function save(FilesPullCheckpoint $checkpoint): void
    {
        $this->test->saved_states[] = clone $checkpoint;
    }
}

final class SymlinkTargetIndexerTestShutdownToken implements ShutdownToken
{
    public function is_shutdown_requested(): bool
    {
        return false;
    }
}

final class SymlinkTargetIndexerTestAuditLogger implements AuditLogger
{
    private SymlinkTargetIndexerTest $test;

    public function __construct(SymlinkTargetIndexerTest $test)
    {
        $this->test = $test;
    }

    public function record(string $message, bool $to_console = true): void
    {
        $this->test->audit[] = [$message, $to_console];
    }

    public function path(): string
    {
        return '';
    }
}

final class SymlinkTargetIndexerTestObserver implements SymlinkTargetObserver
{
    private SymlinkTargetIndexerTest $test;

    public function __construct(SymlinkTargetIndexerTest $test)
    {
        $this->test = $test;
    }

    public function on_following_directory(string $directory): void
    {
        $this->test->lifecycle[] = "Following symlink target: {$directory}\n";
        $this->test->progress[] = [
            'type' => 'symlink_follow',
            'directory' => $directory,
            'message' => "Following symlink target: {$directory}",
        ];
    }

    public function on_rejected_directory(string $directory): void
    {
        $this->test->lifecycle[] = "  Skipped (server rejected): {$directory}\n";
        $this->test->progress[] = [
            'type' => 'symlink_follow_rejected',
            'directory' => $directory,
            'message' => "Skipped (server rejected): {$directory}",
        ];
    }
}
