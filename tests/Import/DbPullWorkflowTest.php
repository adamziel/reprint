<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Session\ImportStateSchema;
use Reprint\Importer\Sql\DbPullWorkflow;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class DbPullWorkflowTest extends TestCase
{
    private string $temp_dir;
    private array $saved_states = [];
    private array $audit = [];
    private array $progress = [];
    private array $downloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/db-pull-workflow-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->saved_states = [];
        $this->audit = [];
        $this->progress = [];
        $this->downloads = [];
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testFreshRunIndexesThenDownloadsSqlAndCompletes(): void
    {
        $state = ImportStateSchema::default_state();

        $this->make_workflow()->run($state);

        $this->assertSame('db-pull', $state['command']);
        $this->assertSame('complete', $state['status']);
        $this->assertSame('sql', $state['stage']);
        $this->assertSame(['db-index', 'sql'], $this->downloads);
        $this->assertSame(3, $state['db_index']['tables']);
        $this->assertSame('complete', $this->last_saved_state()['status']);
    }

    public function testPartialDbIndexReturnsWithoutSqlDownload(): void
    {
        $state = ImportStateSchema::default_state();

        $this->make_workflow([
            'db_index' => function (array &$state): void {
                $this->downloads[] = 'db-index';
                $state['status'] = 'partial';
            },
        ])->run($state);

        $this->assertSame('partial', $state['status']);
        $this->assertSame(['db-index'], $this->downloads);
    }

    public function testCompletedFileOutputRequiresAbort(): void
    {
        file_put_contents($this->temp_dir . '/db.sql', '');
        $state = ImportStateSchema::default_state();
        $state['command'] = 'db-pull';
        $state['status'] = 'complete';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db-pull already completed and db.sql exists');

        $this->make_workflow()->run($state);
    }

    /**
     * @param array<string, callable> $callbacks
     */
    private function make_workflow(array $callbacks = []): DbPullWorkflow
    {
        return new DbPullWorkflow(
            $this->temp_dir,
            $this->temp_dir . '/.import-audit.log',
            'file',
            null,
            new BufferedImportOutput(),
            function (array $state): void {
                $this->saved_states[] = $state;
            },
            function (string $message, bool $to_console = true): void {
                $this->audit[] = [$message, $to_console];
            },
            function (array $progress, bool $force = false): void {
                $this->progress[] = [$progress, $force];
            },
            $callbacks['db_index'] ?? function (array &$state): void {
                $this->downloads[] = 'db-index';
                $state['db_index']['tables'] = 3;
            },
            $callbacks['sql'] ?? function (array &$state): void {
                $this->downloads[] = 'sql';
            },
        );
    }

    private function last_saved_state(): array
    {
        return $this->saved_states[count($this->saved_states) - 1];
    }

    private function remove_path(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_link($path) || is_file($path)) {
            return unlink($path);
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->remove_path($path . '/' . $entry)) {
                return false;
            }
        }
        return rmdir($path);
    }
}
