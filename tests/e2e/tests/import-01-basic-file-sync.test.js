/**
 * Test 01: Basic File Sync via import.php
 * Tests files-sync completes, files match source, and restart behavior.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch,
    assertFileCount, assertSiteMirror,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Basic File Sync', () => {
    const site = 'basic';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-basic-files');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
    }

    it('files-sync completes successfully', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('state file shows complete', () => {
        const stateFile = join(tempDir, '.import-state.json');
        assert.ok(existsSync(stateFile), 'Expected .import-state.json to exist');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.command, 'files-sync');
        assert.equal(state.status, 'complete');
    });

    it('filesystem-root file hashes match source site directory', () => {
        // The importer stores files at filesystem-root/<absolute-path>,
        // so the site dir content ends up at filesystem-root/srv/e2e-sites/<site>/
        const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);

        assertTreesMatch(getSiteDir(site), importedRoot);
    });

    it('.import-index.jsonl has entries', () => {
        const indexFile = join(tempDir, '.import-index.jsonl');
        assert.ok(existsSync(indexFile), 'Expected .import-index.jsonl to exist');
        const lines = readFileSync(indexFile, 'utf-8').trim().split('\n').filter(l => l);
        assert.ok(lines.length > 0, 'Expected at least one index entry');
    });

    it('indexed at least 3000 files from remote', () => {
        assertFileCount(tempDir);
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(tempDir, 'filesystem-root', getSiteDir(site)));
    });

    it('re-running after completion triggers a delta sync', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0 (delta sync)\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('re-running with --restart clears state and exits', () => {
        const restart = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--restart'],
        });
        assert.equal(restart.exitCode, 0, `Expected restart exit 0, got ${restart.exitCode}\nstderr: ${restart.stderr}\nstdout: ${restart.stdout}`);

        // State should be cleared — verify by reading state file
        const stateFile = join(tempDir, '.import-state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf8'));
        assert.notEqual(state.status, 'complete', 'Expected status to be cleared after --restart');
    });

    it('running after --restart starts a fresh initial sync', () => {
        // Clean filesystem-root so fresh sync doesn't fail on non-empty check
        const fsRoot = join(tempDir, 'filesystem-root');
        rmSync(fsRoot, { recursive: true, force: true });

        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });
});
