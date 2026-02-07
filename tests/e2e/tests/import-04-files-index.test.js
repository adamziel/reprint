/**
 * Test 04: Files Index via import.php
 * Tests files-index command produces .import-remote-index.jsonl.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
} from '../lib/test-helpers.js';

describe('Import: Files Index', () => {
    const site = 'basic';
    let tempDir;

    before(() => {
        tempDir = createTempDir('e2e-import-files-index');
    });

    after(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
    }

    it('files-index produces .import-remote-index.jsonl', () => {
        const result = runImporter(importUrl(), tempDir, 'files-index', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        const indexFile = join(tempDir, '.import-remote-index.jsonl');
        assert.ok(existsSync(indexFile), 'Expected .import-remote-index.jsonl to exist');

        const lines = readFileSync(indexFile, 'utf-8').trim().split('\n').filter(l => l);
        assert.ok(lines.length > 0, 'Expected at least one index entry');

        // Entries should include paths from the site directory
        const entries = lines.map(l => JSON.parse(l));
        const hasPaths = entries.some(e => {
            const path = e.path || e.file || Object.values(e).find(v => typeof v === 'string' && v.includes('/'));
            return typeof path === 'string' && path.length > 0;
        });
        assert.ok(hasPaths, `Expected entries with file paths, got: ${JSON.stringify(entries.slice(0, 2))}`);
    });
});
