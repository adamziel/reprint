/**
 * Test 48: Pull — Crash Recovery and Resume
 *
 * Tests two crash-recovery behaviors of the pull command:
 *
 * 1. Internal retry: a SQL crash (server exit(1) mid-batch) produces
 *    a retryable "missing completion chunk" error. Pull detects this,
 *    sets status=partial, and retries. The hook only fires once so the
 *    second attempt succeeds. The entire pull completes in one run.
 *
 * 2. External resume: after a completed pull, --abort resets the
 *    pipeline state. Re-running pull performs a delta sync from scratch,
 *    testing the resume-from-pipeline-stage path.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch, assertSiteMirror,
    fsRootDir, readAuditLog,
    compareDatabases, createMysqlConnection, getDbName,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Pull Crash Resume', { timeout: 300000 }, () => {
    const site = 'sql-crash';
    const importDb = 'e2e_pull_crash_48';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-pull-crash-resume');
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        // Deploy a hook that crashes once during SQL streaming. The
        // first SQL batch request causes exit(1), producing a truncated
        // multipart response. Pull detects "missing completion chunk"
        // and retries. The hook only fires once (tracked via state file)
        // so the retry succeeds.
        clearHookState(site);
        writeHookState(site, {});
        writeTestHooks(site, [
            `$__crash_state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '',
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            '    global $__crash_state_file;',
            '    $state = file_exists($__crash_state_file)',
            '        ? json_decode(file_get_contents($__crash_state_file), true)',
            '        : [];',
            '    if (empty($state["sql_crashed"])) {',
            '        $state["sql_crashed"] = true;',
            '        file_put_contents($__crash_state_file, json_encode($state));',
            '        exit(1);',
            '    }',
            '}',
        ].join('\n'));
    });

    afterAll(async () => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    const pullArgs = () => [
        `--target-user=e2e_admin`,
        `--target-pass=e2e_password`,
        `--target-db=${importDb}`,
        `--new-site-url=http://localhost:9999`,
    ];

    it('pull completes despite SQL crash (retried internally)', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 300000,
            extraArgs: pullArgs(),
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('SQL crash hook fired', () => {
        const hookState = readHookState(site);
        assert.ok(hookState?.sql_crashed, 'Expected SQL crash hook to have fired');
    });

    it('state shows pull complete after crash recovery', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull.stage, 'complete');
    });

    it('files match source after crash recovery', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('database matches source after crash recovery', async () => {
        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(comparison.match,
            `Database mismatch after crash recovery: ` +
            `missing=${JSON.stringify(comparison.missingTables)}, ` +
            `counts=${JSON.stringify(comparison.rowCounts)}`);
    });

    it('audit log records the SQL crash', () => {
        const audit = readAuditLog(tempDir);
        assert.ok(
            audit.includes('INCOMPLETE RESPONSE') ||
            audit.includes('missing completion chunk') ||
            audit.includes('cURL error'),
            'Expected audit log to record crash detection'
        );
    });

    it('abort + re-pull performs delta sync', () => {
        // Remove crash hooks so re-pull runs cleanly
        removeTestHooks(site);

        // Abort the completed pull
        const abortResult = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            extraArgs: ['--abort'],
        });
        assert.equal(abortResult.exitCode, 0,
            `Expected abort exit 0, got ${abortResult.exitCode}`);

        // Re-run pull → delta sync (files already local, re-index + diff)
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 300000,
            extraArgs: pullArgs(),
        });
        assert.equal(result.exitCode, 0,
            `Expected re-pull exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull.stage, 'complete');
    });
});
