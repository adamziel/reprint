/**
 * Test 48: Pull — Crash Recovery Across Phases
 *
 * Deploys test hooks that crash the server once during each streaming
 * phase of the pull pipeline (file download, SQL download). Each crash
 * causes a different recovery path:
 *
 *   Run 1: preflight ok → file download crash → exit 1
 *           (file_fetch curl error is non-retryable → process exits)
 *
 *   Run 2: files-pull resumes from cursor → completes → db-pull starts
 *           → SQL crash → pull's internal retry handles it → db-pull ok
 *           → db-apply ok → pull complete
 *
 * The file crash tests external crash+resume (process dies, re-run
 * picks up from saved state). The SQL crash tests internal recovery
 * (pull_run_until_complete detects "partial" and retries in-process).
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
    const site = 'pull-crash';
    const importDb = 'e2e_pull_crash_48';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-pull-crash-resume');
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        // Deploy hooks that crash once per streaming phase. Each hook
        // checks a shared state file and only crashes the first time.
        clearHookState(site);
        writeHookState(site, {});
        writeTestHooks(site, [
            `$__crash_state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '',
            'function __crash_once($key) {',
            '    global $__crash_state_file;',
            '    $state = file_exists($__crash_state_file)',
            '        ? json_decode(file_get_contents($__crash_state_file), true)',
            '        : [];',
            '    if (!empty($state[$key])) return false;',
            '    $state[$key] = true;',
            '    file_put_contents($__crash_state_file, json_encode($state));',
            '    return true;',
            '}',
            '',
            '// Crash once during file download.',
            '// This produces a curl error (partial transfer) which is NOT',
            '// caught by the file download handler — the RuntimeException',
            '// propagates up, causing the pull process to exit with code 1.',
            'function test_hook_before_file_chunk($path, $offset, &$data) {',
            '    if (__crash_once("file_crashed")) exit(1);',
            '}',
            '',
            '// Crash once during SQL download.',
            '// This produces a "missing completion chunk" error which IS',
            '// retryable — pull_run_until_complete catches it as "partial"',
            '// and retries. The second attempt succeeds because the hook',
            '// already fired.',
            'function test_hook_before_sql_batch(&$sql, $cursor) {',
            '    if (__crash_once("sql_crashed")) exit(1);',
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

    const pullOpts = () => ({
        secret: getSiteSecret(site),
        skipPreflight: true,
        autoResume: false,
        timeout: 120000,
        wallTimeout: 180000,
        extraArgs: pullArgs(),
    });

    it('run 1: crashes during file download', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', pullOpts());

        // The file chunk crash causes a curl error (partial transfer)
        // that propagates as a RuntimeException → pull exits code 1.
        assert.equal(result.exitCode, 1,
            `Expected exit 1 (crash during file download), got ${result.exitCode}\nstderr: ${result.stderr}`);

        // Verify the hook fired
        const hookState = readHookState(site);
        assert.ok(hookState?.file_crashed, 'Expected file crash hook to have fired');

        // Preflight completed but files-pull did not
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull.stage, 'preflight',
            'Expected pull.stage = preflight (files-pull did not complete)');
    });

    it('run 2: resumes files-pull, SQL crash retried internally, pull completes', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            ...pullOpts(),
            wallTimeout: 300000,
        });

        // files-pull resumes and completes (file crash already fired).
        // db-pull starts, SQL crash fires → retryable ("missing completion
        // chunk") → pull retries internally → second SQL request succeeds.
        // db-apply runs to completion.
        assert.equal(result.exitCode, 0,
            `Expected exit 0 on resume, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        // Both hooks should have fired
        const hookState = readHookState(site);
        assert.ok(hookState?.file_crashed, 'file crash should have fired');
        assert.ok(hookState?.sql_crashed, 'sql crash should have fired');
    });

    it('state shows pull complete after recovery', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull.stage, 'complete');
    });

    it('files match source after crash recovery', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('imported files form valid WordPress structure', () => {
        assertSiteMirror(join(fsRootDir(tempDir), getSiteDir(site)));
    });

    it('database matches source after crash recovery', async () => {
        const comparison = await compareDatabases(getDbName(site), importDb);
        assert.ok(comparison.match,
            `Database mismatch after crash recovery: ` +
            `missing=${JSON.stringify(comparison.missingTables)}, ` +
            `counts=${JSON.stringify(comparison.rowCounts)}`);
    });

    it('audit log records crash detection', () => {
        const audit = readAuditLog(tempDir);
        // The SQL crash produces an "INCOMPLETE RESPONSE" or "missing
        // completion chunk" entry — proving the crash was detected.
        assert.ok(
            audit.includes('INCOMPLETE RESPONSE') ||
            audit.includes('missing completion chunk') ||
            audit.includes('CRASH') ||
            audit.includes('cURL error'),
            'Expected audit log to record crash detection'
        );
    });
});
