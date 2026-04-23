/**
 * Test 51: Pull fetch-skipped continuation
 *
 * Verifies that a pull completed with `--filter=essential-files` can later
 * fetch the deferred file tail with `pull --fetch-skipped`.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    assertTreesMatch,
    cleanupTempDir,
    createTempDir,
    fsRootDir,
    getSiteDir,
    getSiteSecret,
    getSiteUrl,
    runImporter,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Pull fetch-skipped continuation', { timeout: 240000 }, () => {
    const site = 'basic';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-pull-fetch-skipped');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('pull with essential-files leaves deferred files pending', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: [
                '--filter=essential-files',
                '--target-engine=sqlite',
                '--runtime=none',
                '--new-site-url=http://127.0.0.1:9998',
            ],
        });

        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull.stage, 'complete');
        assert.equal(state.pull.files_filter, 'essential-files');
        assert.equal(state.pull.skipped_pending, true);
        assert.ok(existsSync(join(tempDir, '.import-download-list-skipped.jsonl')),
            'expected the skipped download list to be preserved on disk');
    });

    it('pull --fetch-skipped downloads the deferred files and clears the pending flag', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            skipPreflight: true,
            timeout: 120000,
            wallTimeout: 180000,
            extraArgs: ['--fetch-skipped'],
        });

        assert.equal(result.exitCode, 0,
            `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.pull.stage, 'complete');
        assert.equal(state.pull.files_filter, 'essential-files');
        assert.equal(state.pull.skipped_pending, false);
        assert.ok(!existsSync(join(tempDir, '.import-download-list-skipped.jsonl')),
            'expected the skipped download list to be cleared after fetch-skipped');

        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        assertTreesMatch(getSiteDir(site), importedRoot);
    });
});
