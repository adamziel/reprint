<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FetchCheckpoint;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\Pull\PullCheckpoint;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\ImportStateSchema;
use Reprint\Importer\Session\StatePathCodec;

require_once __DIR__ . '/../../importer/import.php';

class ImportSessionInfrastructureTest extends TestCase
{
    public function testImportPathsDeriveSessionFilePaths(): void
    {
        $paths = new ImportPaths('/tmp/reprint-state/');

        $this->assertSame('/tmp/reprint-state', $paths->state_dir());
        $this->assertSame('/tmp/reprint-state/.reprint', $paths->state_root());
        $this->assertSame('/tmp/reprint-state/.reprint/run.json', $paths->state_file());
        $this->assertSame('/tmp/reprint-state/.reprint/run.json', $paths->run_state_file());
        $this->assertSame('/tmp/reprint-state/.reprint/db-pull/checkpoint.json', $paths->db_pull_checkpoint_file());
        $this->assertSame('/tmp/reprint-state/.reprint/db-apply/checkpoint.json', $paths->db_apply_checkpoint_file());
        $this->assertSame('/tmp/reprint-state/.reprint/files-pull/checkpoint.json', $paths->files_pull_checkpoint_file());
        $this->assertSame('/tmp/reprint-state/.reprint/pull/checkpoint.json', $paths->pull_checkpoint_file());
        $this->assertSame('/tmp/reprint-state/.reprint/runtime/checkpoint.json', $paths->runtime_checkpoint_file());
        $this->assertSame('/tmp/reprint-state/.import-index.jsonl', $paths->index_file());
        $this->assertSame('/tmp/reprint-state/.import-index-updates.jsonl', $paths->index_updates_file());
        $this->assertSame('/tmp/reprint-state/.import-remote-index.jsonl', $paths->remote_index_file());
        $this->assertSame('/tmp/reprint-state/.import-download-list.jsonl', $paths->download_list_file());
        $this->assertSame('/tmp/reprint-state/.import-download-list-skipped.jsonl', $paths->skipped_download_list_file());
        $this->assertSame('/tmp/reprint-state/.import-audit.log', $paths->audit_log());
        $this->assertSame('/tmp/reprint-state/.import-status.json', $paths->status_file());
        $this->assertSame('/tmp/reprint-state/runtime_files', $paths->runtime_files_dir());
        $this->assertSame('/tmp/reprint-state/db.sql', $paths->sql_file());
    }

    public function testStateSchemaNormalizesNestedState(): void
    {
        $state = ImportStateSchema::normalize([
            'command' => 'files-pull',
            'unknown' => 'ignored',
            'pull' => ['stage' => 'files-pull'],
            'diff' => 'invalid',
            'fetch' => ['offset' => 42, 'extra' => 'ignored'],
            'apply' => ['target_engine' => 'sqlite'],
        ]);

        $this->assertSame('files-pull', $state['command']);
        $this->assertArrayNotHasKey('unknown', $state);
        $this->assertArrayNotHasKey('pull', $state);
        $this->assertArrayNotHasKey('diff', $state);
        $this->assertArrayNotHasKey('fetch', $state);
        $this->assertSame('sqlite', $state['apply']['target_engine']);
        $this->assertSame(0, $state['apply']['statements_executed']);
    }

    public function testPullCheckpointNormalizesOrchestrationState(): void
    {
        $checkpoint = PullCheckpoint::from_array([
            'stage' => 'files-pull',
            'files_filter' => 'essential-files',
            'skipped_pending' => true,
        ]);

        $this->assertSame('files-pull', $checkpoint->stage);
        $this->assertSame('essential-files', $checkpoint->files_filter);
        $this->assertTrue($checkpoint->skipped_pending);

        $checkpoint->reset();

        $this->assertNull($checkpoint->stage);
        $this->assertNull($checkpoint->files_filter);
        $this->assertFalse($checkpoint->skipped_pending);
    }

    public function testStatePathCodecRoundTripsByteSensitivePaths(): void
    {
        $codec = new StatePathCodec();
        $state = ImportStateSchema::default_state();
        $state['db_index']['file'] = '/tmp/db-tables.jsonl';
        $state['preflight'] = [
            'data' => [
                'runtime' => ['document_root' => '/srv/htdocs'],
                'filesystem' => [
                    'directories' => [
                        ['path' => '/srv/htdocs/wp-content'],
                    ],
                ],
            ],
        ];

        $encoded = $codec->encode_state_paths($state);
        $this->assertStringStartsWith('base64:', $encoded['preflight']['data']['runtime']['document_root']);

        $decoded = $codec->decode_state_paths($encoded);
        $this->assertSame($state['db_index']['file'], $decoded['db_index']['file']);
        $this->assertSame(
            '/srv/htdocs/wp-content',
            $decoded['preflight']['data']['filesystem']['directories'][0]['path'],
        );
    }

    public function testFilesPullCheckpointPathCodecRoundTripsByteSensitivePaths(): void
    {
        $codec = new StatePathCodec();
        $checkpoint = new FilesPullCheckpoint(
            "in_progress",
            "fetch",
            null,
            0,
            "/var/www/\0site",
            new FetchCheckpoint(0, 0, '/tmp/batch.json'),
            new FetchCheckpoint(0, 0, '/tmp/skipped-batch.json'),
            '/tmp/current',
        );

        $encoded = $checkpoint->to_persisted_array([$codec, 'encode_value']);
        $this->assertStringStartsWith('base64:', $encoded["diff"]["local_after"]);
        $this->assertStringStartsWith('base64:', $encoded["fetch"]["batch_file"]);
        $this->assertStringStartsWith('base64:', $encoded["fetch_skipped"]["batch_file"]);
        $this->assertStringStartsWith('base64:', $encoded["current_file"]);

        $decoded = FilesPullCheckpoint::from_persisted_array(
            $encoded,
            [$codec, 'decode_value'],
        );
        $this->assertSame($checkpoint->status, $decoded->status);
        $this->assertSame($checkpoint->stage, $decoded->stage);
        $this->assertSame($checkpoint->diff_local_after, $decoded->diff_local_after);
        $this->assertSame($checkpoint->fetch->batch_file, $decoded->fetch->batch_file);
        $this->assertSame(
            $checkpoint->fetch_skipped->batch_file,
            $decoded->fetch_skipped->batch_file,
        );
        $this->assertSame($checkpoint->current_file, $decoded->current_file);
    }

    public function testStatePathCodecReportsInvalidEncodedPath(): void
    {
        $warnings = [];
        $codec = new StatePathCodec(function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        $this->assertNull($codec->decode_value('base64:not-valid!!'));
        $this->assertSame(
            ['Warning: invalid base64-encoded state path; resetting field'],
            $warnings,
        );
    }
}
