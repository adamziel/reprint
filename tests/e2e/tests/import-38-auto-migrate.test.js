/**
 * Test 38: auto-migrate command
 *
 * Verifies that `auto-migrate` runs the full migration pipeline
 * (preflight → files-sync → db-sync → files-delta → db-apply)
 * as a single command, producing the same result as running each
 * step manually.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertSiteMirror, createMysqlConnection,
    compareDatabases, getDbName,
    docrootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: auto-migrate', () => {
    const site = 'basic';
    const importDbName = 'e2e_basic_auto_migrate';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-auto-migrate');

        // Create a fresh import database — db-apply connects to an
        // existing database, it does not create one.
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDbName}\``);
        await conn.query(`CREATE DATABASE \`${importDbName}\``);
        await conn.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        try {
            const conn = await createMysqlConnection();
            await conn.query(`DROP DATABASE IF EXISTS \`${importDbName}\``);
            await conn.end();
        } catch (e) {
            // Ignore cleanup errors
        }
    });

    it('auto-migrate completes the full pipeline', () => {
        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        const result = runImporter(url, tempDir, 'auto-migrate', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            autoResume: false,
            extraArgs: [
                `--target-user=e2e_admin`,
                `--target-pass=e2e_password`,
                `--target-db=${importDbName}`,
                `--target-host=127.0.0.1`,
            ],
            timeout: 120000,
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state shows auto-migrate completed', () => {
        const stateFile = join(tempDir, '.import-state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state['auto-migrate']?.phase, 'complete');
    });

    it('files were downloaded', () => {
        const importedRoot = join(docrootDir(tempDir), getSiteDir(site));
        assertSiteMirror(importedRoot);
    });

    it('downloaded files match source', () => {
        const importedRoot = join(docrootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('database was applied correctly', async () => {
        const sourceDb = getDbName(site);
        const comparison = await compareDatabases(sourceDb, importDbName);

        assert.ok(
            comparison.match,
            `Database mismatch:\n` +
            `  missing tables: ${comparison.missingTables.join(', ')}\n` +
            `  extra tables: ${comparison.extraTables.join(', ')}\n` +
            `  row count mismatches: ${JSON.stringify(
                Object.entries(comparison.rowCounts).filter(([_, v]) => !v.match)
            )}`
        );
    });

    it('db.sql file exists in state dir', () => {
        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');
        const sql = readFileSync(sqlFile, 'utf-8');
        assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
    });
});

describe('Import: auto-migrate without db-apply', () => {
    const site = 'basic';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-auto-migrate-no-db');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    it('auto-migrate completes without --target-user/--target-db', () => {
        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
        const result = runImporter(url, tempDir, 'auto-migrate', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            autoResume: false,
            timeout: 120000,
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state shows complete (db_apply skipped)', () => {
        const stateFile = join(tempDir, '.import-state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state['auto-migrate']?.phase, 'complete');
    });

    it('files were downloaded', () => {
        const importedRoot = join(docrootDir(tempDir), getSiteDir(site));
        assertSiteMirror(importedRoot);
    });

    it('db.sql was downloaded', () => {
        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');
    });
});

describe('Import: auto-migrate --abort', () => {
    const site = 'basic';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-auto-migrate-abort');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    it('abort clears auto-migrate state', () => {
        const url = `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;

        // Run auto-migrate with a very short timeout to interrupt it mid-phase
        runImporter(url, tempDir, 'auto-migrate', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            autoResume: false,
            timeout: 5000,
        });

        // Now abort
        const abortResult = runImporter(url, tempDir, 'auto-migrate', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            autoResume: false,
            extraArgs: ['--abort'],
        });
        assert.equal(abortResult.exitCode, 0, `Expected abort exit 0\nstderr: ${abortResult.stderr}`);

        // Verify state is cleared — after abort, auto-migrate is set to null
        // which is serialized as null in JSON
        const stateFile = join(tempDir, '.import-state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.ok(
            state['auto-migrate'] === null || state['auto-migrate'] === undefined,
            'Expected auto-migrate state to be cleared'
        );
    });
});
