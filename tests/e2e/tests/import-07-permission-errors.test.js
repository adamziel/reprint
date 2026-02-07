/**
 * Test 07: Permission Errors via import.php
 * Tests chmod-denied and mysql-restricted sites complete gracefully.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory,
} from '../lib/test-helpers.js';

describe('Import: Permission Errors', () => {
    describe('chmod-denied', () => {
        const site = 'chmod-denied';
        let tempDir;

        before(() => {
            tempDir = createTempDir('e2e-import-chmod');
        });

        after(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        }

        it('file sync completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('readable files are downloaded', () => {
            const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least one readable file downloaded');

            // hello.txt should be present (it's readable)
            assert.ok(
                [...hashes.keys()].some(p => p.includes('hello.txt')),
                'Expected hello.txt to be downloaded'
            );
        });

    });

    describe('mysql-restricted', () => {
        const site = 'mysql-restricted';
        let tempDir;

        before(() => {
            tempDir = createTempDir('e2e-import-mysql-restricted');
        });

        after(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        }

        it('sql-sync completes', () => {
            const result = runImporter(importUrl(), tempDir, 'sql-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const sqlFile = join(tempDir, 'db.sql');
            assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');
        });

        it('state shows complete', () => {
            const stateFile = join(tempDir, '.import-state.json');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.status, 'complete');
        });
    });
});
