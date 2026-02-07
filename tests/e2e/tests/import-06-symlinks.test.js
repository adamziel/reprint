/**
 * Test 06: Symlinks via import.php
 * Tests that symlinks-outside and circular-symlinks sites sync properly.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory,
} from '../lib/test-helpers.js';

describe('Import: Symlinks', () => {
    describe('symlinks-outside', () => {
        const site = 'symlinks-outside';
        let tempDir;

        before(() => {
            tempDir = createTempDir('e2e-import-symlinks');
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

        it('regular files are downloaded', () => {
            const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least one regular file');
        });

        it('sync completed without error despite symlinks', () => {
            const stateFile = join(tempDir, '.import-state.json');
            assert.ok(existsSync(stateFile), 'Expected .import-state.json to exist');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.status, 'complete', 'Expected status to be complete');
        });
    });

    describe('circular-symlinks', () => {
        const site = 'circular-symlinks';
        let tempDir;

        before(() => {
            tempDir = createTempDir('e2e-import-circular');
        });

        after(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        }

        it('file sync completes within timeout (no infinite loop)', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
                secret: getSiteSecret(site),
                timeout: 60000,
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('regular files are downloaded', () => {
            const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least one regular file');
        });
    });
});
