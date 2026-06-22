<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
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
            'diff' => 'invalid',
            'fetch' => ['offset' => 42, 'extra' => 'ignored'],
            'apply' => ['target_engine' => 'sqlite'],
        ]);

        $this->assertSame('files-pull', $state['command']);
        $this->assertArrayNotHasKey('unknown', $state);
        $this->assertSame(['remote_offset' => 0, 'local_after' => null], $state['diff']);
        $this->assertSame(42, $state['fetch']['offset']);
        $this->assertArrayNotHasKey('extra', $state['fetch']);
        $this->assertSame('sqlite', $state['apply']['target_engine']);
        $this->assertSame(0, $state['apply']['statements_executed']);
    }

    public function testStatePathCodecRoundTripsByteSensitivePaths(): void
    {
        $codec = new StatePathCodec();
        $state = ImportStateSchema::default_state();
        $state['diff']['local_after'] = "/var/www/\0site";
        $state['fetch']['batch_file'] = '/tmp/batch.json';
        $state['current_file'] = '/tmp/current';
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
        $this->assertStringStartsWith('base64:', $encoded['diff']['local_after']);
        $this->assertStringStartsWith('base64:', $encoded['preflight']['data']['runtime']['document_root']);

        $decoded = $codec->decode_state_paths($encoded);
        $this->assertSame($state['diff']['local_after'], $decoded['diff']['local_after']);
        $this->assertSame($state['fetch']['batch_file'], $decoded['fetch']['batch_file']);
        $this->assertSame($state['current_file'], $decoded['current_file']);
        $this->assertSame($state['db_index']['file'], $decoded['db_index']['file']);
        $this->assertSame(
            '/srv/htdocs/wp-content',
            $decoded['preflight']['data']['filesystem']['directories'][0]['path'],
        );
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
