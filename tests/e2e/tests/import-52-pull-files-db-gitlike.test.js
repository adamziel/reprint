/**
 * Test 52: Pull files / pull database — git-pull-like repeated syncs
 *
 * The high-level pull-files and pull-db commands should update the local copy
 * to match the current remote site, not merely expose one low-level stage.
 * Each command is run more than once after mutating the remote source, and the
 * tests assert the local side reflects those changes.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, writeFileSync,
} from 'node:fs';
import { dirname, join } from 'node:path';
import { execSync } from 'node:child_process';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir, createMysqlConnection, getDbName,
    writeTestHooks, removeTestHooks, clearHookState, readHookState, writeHookState,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

function readJson(path) {
    return JSON.parse(readFileSync(path, 'utf-8'));
}

function readJsonLines(path) {
    return readFileSync(path, 'utf-8')
        .trim()
        .split('\n')
        .filter(Boolean)
        .map((line) => JSON.parse(line));
}

describe('Import: pull-files git-pull-like sync', { timeout: 300000 }, () => {
    const site = 'pull-files-gitlike';
    let tempDir;
    let siteDir;
    let remoteRoot;
    let localRoot;

    beforeAll(async () => {
        await ensureSite(site);
        siteDir = getSiteDir(site);
        remoteRoot = siteDir;
        tempDir = createTempDir('e2e-pull-files-gitlike');
        localRoot = join(fsRootDir(tempDir), siteDir);
        removeTestHooks(site);
        clearHookState(site);
        resetRemoteFiles('round-1');
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    function remotePath(relativePath) {
        return join(remoteRoot, relativePath);
    }

    function localPath(relativePath) {
        return join(localRoot, relativePath);
    }

    function writeRemoteFile(relativePath, content) {
        const path = remotePath(relativePath);
        const tempPath = join(tempDir, `remote-${relativePath.replaceAll('/', '-')}`);
        writeFileSync(tempPath, content);
        execSync(`sudo mkdir -p ${JSON.stringify(dirname(path))}`);
        execSync(`sudo cp ${JSON.stringify(tempPath)} ${JSON.stringify(path)}`);
        execSync(`sudo chown nginx:nginx ${JSON.stringify(path)}`);
    }

    function removeRemoteFile(relativePath) {
        execSync(`sudo rm -f ${JSON.stringify(remotePath(relativePath))}`);
    }

    function resetRemoteFiles(marker) {
        execSync(`sudo rm -rf ${JSON.stringify(remotePath('test-data/pull-files'))}`);
        writeRemoteFile('test-data/pull-files/marker.txt', `${marker}\n`);
        writeRemoteFile('test-data/pull-files/local-conflict.txt', 'remote conflict original\n');
        writeRemoteFile('test-data/pull-files/removed-after-first.txt', 'present in round 1\n');
    }

    function runPullFiles(extraArgs = [], options = {}) {
        const result = runImporter(importUrl(), tempDir, 'pull-files', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 300000,
            extraArgs,
            ...options,
        });
        assert.equal(result.exitCode, 0,
            `Expected pull-files exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        return result;
    }

    it('downloads the current remote files on the first run', () => {
        runPullFiles();

        assert.equal(readFileSync(localPath('test-data/pull-files/marker.txt'), 'utf-8'), 'round-1\n');
        assert.equal(readFileSync(localPath('test-data/pull-files/local-conflict.txt'), 'utf-8'), 'remote conflict original\n');
        assert.equal(readFileSync(localPath('test-data/pull-files/removed-after-first.txt'), 'utf-8'), 'present in round 1\n');

        const state = readJson(join(tempDir, '.import-state.json'));
        assert.equal(state.pull_files.stage, 'complete');
        assert.equal(state.pull?.stage ?? null, null, 'pull-files must not advance the full pull cursor');
    });

    it('preserves tracked files with local edits unless overwrite is requested', () => {
        writeFileSync(localPath('test-data/pull-files/local-conflict.txt'), 'locally edited content\n');
        writeRemoteFile('test-data/pull-files/local-conflict.txt', 'remote changed after local edit\n');

        runPullFiles();

        assert.equal(readFileSync(localPath('test-data/pull-files/local-conflict.txt'), 'utf-8'), 'locally edited content\n');
        const conflicts = readJsonLines(join(tempDir, '.import-local-conflicts.jsonl'));
        assert.ok(conflicts.some((conflict) => (
            conflict.path === 'test-data/pull-files/local-conflict.txt' &&
            conflict.action === 'overwrite' &&
            conflict.resolution === 'preserve-local'
        )), 'pull-files should report a structured local conflict');

        runPullFiles(['--on-local-conflict=overwrite']);

        assert.equal(readFileSync(localPath('test-data/pull-files/local-conflict.txt'), 'utf-8'), 'remote changed after local edit\n');
    });

    it('re-running pull-files after remote changes updates, adds, and deletes files', () => {
        writeRemoteFile('test-data/pull-files/marker.txt', 'round-2 with changed size\n');
        writeRemoteFile('test-data/pull-files/added-after-first.txt', 'added in round 2\n');
        removeRemoteFile('test-data/pull-files/removed-after-first.txt');

        runPullFiles();

        assert.equal(readFileSync(localPath('test-data/pull-files/marker.txt'), 'utf-8'), 'round-2 with changed size\n');
        assert.equal(readFileSync(localPath('test-data/pull-files/added-after-first.txt'), 'utf-8'), 'added in round 2\n');
        assert.ok(!existsSync(localPath('test-data/pull-files/removed-after-first.txt')),
            'pull-files should delete files removed from the remote source');
    });

    it('an interrupted pull-files does not corrupt a later full pull', async () => {
        writeRemoteFile('test-data/pull-files/marker.txt', 'round-3 after interruption\n');
        writeHookState(site, { scan_count: 0 });
        writeTestHooks(site, [
            'function test_hook_during_dir_scan($dir, &$entries) {',
            `    $state_file = '/srv/e2e-sites/.e2e-hook-state-${site}';`,
            '    $state = file_exists($state_file)',
            '        ? json_decode(file_get_contents($state_file), true)',
            '        : [];',
            '    $state[\'scan_count\'] = ($state[\'scan_count\'] ?? 0) + 1;',
            '    file_put_contents($state_file, json_encode($state));',
            '    if ($state[\'scan_count\'] === 2) {',
            '        exit(1);',
            '    }',
            '}',
        ].join('\n'));

        const interrupted = runImporter(importUrl(), tempDir, 'pull-files', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            autoResume: false,
            timeout: 120000,
            wallTimeout: 120000,
        });
        assert.notEqual(interrupted.exitCode, 0,
            `Expected interrupted pull-files to fail\nstdout: ${interrupted.stdout}\nstderr: ${interrupted.stderr}`);
        assert.ok((readHookState(site)?.scan_count ?? 0) >= 2, 'test hook should have interrupted file indexing');

        removeTestHooks(site);
        clearHookState(site);

        const importDb = 'e2e_pull_files_after_interrupt_52';
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();

        try {
            const fullPull = runImporter(importUrl(), tempDir, 'pull', {
                secret: getSiteSecret(site),
                skipPreflight: true,
                timeout: 120000,
                wallTimeout: 300000,
                extraArgs: [
                    '--target-user=e2e_admin',
                    '--target-pass=e2e_password',
                    `--target-db=${importDb}`,
                    '--new-site-url=http://localhost:9999',
                    '--runtime=none',
                ],
            });
            assert.equal(fullPull.exitCode, 0,
                `Expected full pull exit 0 after interrupted pull-files, got ${fullPull.exitCode}\n` +
                `stderr: ${fullPull.stderr}\nstdout: ${fullPull.stdout}`);

            const state = readJson(join(tempDir, '.import-state.json'));
            assert.equal(state.pull.stage, 'complete');
            assert.equal(readFileSync(localPath('test-data/pull-files/marker.txt'), 'utf-8'), 'round-3 after interruption\n');
        } finally {
            const cleanup = await createMysqlConnection();
            await cleanup.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await cleanup.end();
        }
    });
});

describe('Import: pull-db git-pull-like sync', { timeout: 300000 }, () => {
    const site = 'pull-db-gitlike';
    const importDb = 'e2e_pull_db_gitlike_52';
    const optionName = 'reprint_pull_db_marker';
    let tempDir;
    let siteDir;

    beforeAll(async () => {
        await ensureSite(site);
        siteDir = getSiteDir(site);
        tempDir = createTempDir('e2e-pull-db-gitlike');

        const source = await createMysqlConnection(getDbName(site));
        await source.query(
            `INSERT INTO wp_options (option_name, option_value, autoload)
             VALUES (?, ?, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)`,
            [optionName, 'db-round-1'],
        );
        await source.end();

        const target = await createMysqlConnection();
        await target.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await target.query(`CREATE DATABASE \`${importDb}\``);
        await target.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    function pullDbArgs() {
        return [
            '--target-user=e2e_admin',
            '--target-pass=e2e_password',
            `--target-db=${importDb}`,
            '--new-site-url=http://localhost:9999',
        ];
    }

    async function sourceSetMarker(value) {
        const conn = await createMysqlConnection(getDbName(site));
        await conn.query(
            'UPDATE wp_options SET option_value = ? WHERE option_name = ?',
            [value, optionName],
        );
        await conn.end();
    }

    async function targetMarker() {
        const conn = await createMysqlConnection(importDb);
        try {
            const [rows] = await conn.query(
                'SELECT option_value FROM wp_options WHERE option_name = ?',
                [optionName],
            );
            return rows[0]?.option_value ?? null;
        } finally {
            await conn.end();
        }
    }

    function runPullDb() {
        const result = runImporter(importUrl(), tempDir, 'pull-db', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 300000,
            extraArgs: pullDbArgs(),
        });
        assert.equal(result.exitCode, 0,
            `Expected pull-db exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        return result;
    }

    it('downloads and applies the current remote database on the first run', async () => {
        runPullDb();

        assert.equal(await targetMarker(), 'db-round-1');
        const state = readJson(join(tempDir, '.import-state.json'));
        assert.equal(state.pull_db.stage, 'complete');
        assert.equal(state.pull?.stage ?? null, null, 'pull-db must not advance the full pull cursor');
    });

    it('re-running pull-db after a remote DB change updates the target database', async () => {
        await sourceSetMarker('db-round-2');

        runPullDb();

        assert.equal(await targetMarker(), 'db-round-2');
    });
});
