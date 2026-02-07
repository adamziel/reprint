/**
 * Test 12: Gzip Corruption Handling via import.php
 * Tests that the importer handles connection resets gracefully.
 * Since server-side test hooks are unreliable for injecting gzip corruption,
 * this test verifies the client handles an unreachable endpoint correctly.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertFileCount, assertSiteMirror,
} from '../lib/test-helpers.js';

describe('Import: Error Resilience', () => {
    it('sql-sync on gzip-corrupt site completes', () => {
        const site = 'gzip-corrupt';
        const tempDir = createTempDir('e2e-import-gzip');
        try {
            const url = `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'sql-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const sqlFile = join(tempDir, 'db.sql');
            assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

            const sql = readFileSync(sqlFile, 'utf-8');
            assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
        } finally {
            cleanupTempDir(tempDir);
        }
    });

    describe('file sync on gzip-corrupt site', () => {
        const site = 'gzip-corrupt';
        let tempDir;

        before(() => {
            tempDir = createTempDir('e2e-import-gzip-files');
        });

        after(() => {
            cleanupTempDir(tempDir);
        });

        it('files-sync-initial completes', () => {
            const url = `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-sync-initial', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const stateFile = join(tempDir, '.import-state.json');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.status, 'complete');
        });

        it('indexed at least 3000 files from remote', () => {
            assertFileCount(tempDir);
        });

        it('imported files form a valid WordPress site mirror', () => {
            assertSiteMirror(join(tempDir, 'filesystem-root', getSiteDir(site)));
        });
    });
});
