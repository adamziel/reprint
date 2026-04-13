/**
 * Test 48: Pull — Crash Recovery Across Phases
 *
 * Deploys test hooks that crash the server once during each phase of
 * the pull pipeline (file indexing, file download, SQL download).
 * Each crash causes the PHP client to exit with an error. Re-running
 * `reprint pull` resumes from where it left off. After all crashes
 * have fired, the final run completes the entire import.
 *
 * The hooks use a shared state file to track which crashes have
 * already fired, so each crash happens exactly once:
 *
 *   Run 1: preflight ok → file index crash → exit 1
 *   Run 2: file index ok → file download crash → exit 1
 *   Run 3: file download ok → files complete → SQL crash → retried
 *           internally by pull → SQL ok → db-pull complete → pull ok
 *
 * The SQL crash is retryable (the importer detects "missing completion
 * chunk" and sets status=partial), so pull's internal retry loop
 * handles it without the process needing to restart.
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

        // Deploy hooks that crash once per phase. Each hook checks a
        // shared state file and only crashes if it hasn't crashed for
        // that phase yet.
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
            '// Crash once during file indexing (causes non-retryable error)',
            'function test_hook_before_index_batch(&$batch_items, $stack) {',
            '    if (__crash_once("index_crashed")) exit(1);',
            '}',
            '',
            '// Crash once during file download (causes non-retryable error)',
            'function test_hook_before_file_chunk($path, $offset, &$data) {',
            '    if (__crash_once("file_crashed")) exit(1);',
            '}',
            '',
            '// Crash once during SQL download (retryable — pull handles internally)',
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

    it('run 1: crashes during file index', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', pullOpts());
        assert.equal(result.exitCode, 1,
            `Expected exit 1 (crash during index), got ${result.exitCode}\nstderr: ${result.stderr}`);

        // Verify the index hook fired
        const hookState = readHookState(site);
        assert.ok(hookState?.index_crashed, 'Expected index crash hook to have fired');

        // Preflight should have completed, but pull.stage should reflect
        // that files-pull did NOT complete
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull.stage, 'preflight',
            'Expected pull.stage to be preflight (files-pull did not complete)');
    });

    it('run 2: resumes files-pull, crashes during file download', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', pullOpts());
        assert.equal(result.exitCode, 1,
            `Expected exit 1 (crash during file download), got ${result.exitCode}\nstderr: ${result.stderr}`);

        // Verify the file chunk hook fired
        const hookState = readHookState(site);
        assert.ok(hookState?.file_crashed, 'Expected file crash hook to have fired');

        // pull.stage should still be preflight (files-pull still not complete)
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull.stage, 'preflight');
    });

    it('run 3: resumes and completes (SQL crash retried internally)', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            ...pullOpts(),
            autoResume: false,
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0 on final run, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        // All hooks should have fired
        const hookState = readHookState(site);
        assert.ok(hookState?.index_crashed, 'index crash should have fired');
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

    it('audit log records crash and recovery', () => {
        const audit = readAuditLog(tempDir);
        // The SQL crash produces an "INCOMPLETE RESPONSE" entry in the audit log,
        // proving the crash was detected and retried.
        assert.ok(
            audit.includes('INCOMPLETE RESPONSE') ||
            audit.includes('missing completion chunk') ||
            audit.includes('CRASH'),
            'Expected audit log to record crash detection'
        );
    });
});
