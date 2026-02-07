/**
 * Test 10: File Resume via import.php
 * Tests that files-sync-initial can resume after a partial transfer.
 * Uses large-directory site (2000 files) with --max-exec=1 to force short requests.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, compareDirectoryHashes,
} from '../lib/test-helpers.js';

describe('Import: Resume Files', { timeout: 180000 }, () => {
    const site = 'large-directory';
    let tempDir;

    before(() => {
        tempDir = createTempDir('e2e-import-resume-files');
    });

    after(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
    }

    it('files-sync-initial completes via multiple resumable requests', () => {
        // Use --max-exec=1 to force very short server execution times,
        // which means each request only transfers a few files before returning partial.
        // The importer automatically resumes from the cursor.
        const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
            secret: getSiteSecret(site),
            timeout: 120000,
            extraArgs: ['--max-exec=1'],
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state shows complete', () => {
        const stateFile = join(tempDir, '.import-state.json');
        assert.ok(existsSync(stateFile), 'Expected state file to exist');

        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.status, 'complete', 'Expected status to be complete');
    });

    it('all files present and correct after multi-request sync', () => {
        const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
        const sourceHashes = hashDirectory(getSiteDir(site));
        const importedHashes = hashDirectory(importedRoot);

        const comparison = compareDirectoryHashes(sourceHashes, importedHashes);
        assert.ok(comparison.match,
            `File mismatch: missing=${comparison.missing.length}, ` +
            `different=${comparison.different.length}\n` +
            `missing: ${JSON.stringify(comparison.missing.slice(0, 5))}`);
    });
});
