/**
 * Test 15: Volatile Files and Deleted Dirs via import.php
 * Tests that file sync handles edge-case sites gracefully.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory,
    assertFileCount, assertSiteMirror,
} from '../lib/test-helpers.js';

describe('Import: Volatile Files', () => {
    describe('volatile-file site', () => {
        const site = 'volatile-file';
        let tempDir;

        before(() => {
            tempDir = createTempDir('e2e-import-volatile');
        });

        after(() => {
            cleanupTempDir(tempDir);
        });

        it('file sync completes', () => {
            const url = `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-sync-initial', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const stateFile = join(tempDir, '.import-state.json');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.status, 'complete');
        });

        it('files are downloaded', () => {
            const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least some files downloaded');
        });

        it('indexed at least 3000 files from remote', () => {
            assertFileCount(tempDir);
        });

        it('imported files form a valid WordPress site mirror', () => {
            assertSiteMirror(join(tempDir, 'filesystem-root', getSiteDir(site)));
        });
    });

    describe('dir-deleted site', () => {
        const site = 'dir-deleted';
        let tempDir;

        before(() => {
            tempDir = createTempDir('e2e-import-dir-deleted');
        });

        after(() => {
            cleanupTempDir(tempDir);
        });

        it('file sync completes', () => {
            const url = `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
            const result = runImporter(url, tempDir, 'files-sync-initial', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const stateFile = join(tempDir, '.import-state.json');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.status, 'complete');
        });

        it('files are downloaded', () => {
            const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least some files downloaded');

            assert.ok(
                [...hashes.keys()].some(p => p.includes('hello.txt')),
                'Expected hello.txt to be present'
            );
        });
    });
});
