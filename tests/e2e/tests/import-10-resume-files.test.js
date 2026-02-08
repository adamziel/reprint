/**
 * Test 10: File Resume via import.php
 * Tests that files-sync-initial can resume after a partial transfer.
 * Uses large-directory site (5000+ files) with --max-exec=5 to force short requests.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, compareDirectoryHashes,
    assertFileCount, assertSiteMirror,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Resume Files', { timeout: 180000 }, () => {
    const site = 'large-directory';
    let tempDir;

    before(async () => {
        await ensureSite(site, {
            files: 'none',
            afterCreate: async (siteDir) => {
                const { execSync } = await import('node:child_process');
                execSync(`sudo mkdir -p "${siteDir}/test-data/many-files"`);
                for (let i = 1; i <= 2000; i++) {
                    const num = String(i).padStart(4, '0');
                    execSync(`printf "content-${num}" | sudo tee "${siteDir}/test-data/many-files/file-${num}.txt" > /dev/null`);
                }
                execSync(`sudo chown -R nginx:nginx "${siteDir}"`);
            },
        });
        tempDir = createTempDir('e2e-import-resume-files');
    });

    after(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
    }

    it('files-sync-initial completes via multiple resumable requests', () => {
        // Use --max-exec=3 to force short server execution times,
        // which means each request only transfers a subset of files before returning partial.
        // The importer automatically resumes from the cursor.
        const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
            secret: getSiteSecret(site),
            timeout: 180000,
            extraArgs: ['--max-exec=3'],
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state shows complete', () => {
        const stateFile = join(tempDir, '.import-state.json');
        assert.ok(existsSync(stateFile), 'Expected state file to exist');

        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.status, 'complete', 'Expected status to be complete');
    });

    it('indexed at least 3000 files from remote', () => {
        assertFileCount(tempDir);
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(tempDir, 'filesystem-root', getSiteDir(site)));
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
