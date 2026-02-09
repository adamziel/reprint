/**
 * Test 24: Large Single File via import.php
 * Tests that a single file larger than the chunk size (5MB default)
 * is correctly transferred in multiple chunks and the SHA1 hash
 * matches after sync.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, statSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, compareDirectoryHashes, sha1File,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Large Single File', { timeout: 180000 }, () => {
    const site = 'import-failures';
    let tempDir;
    const largeName = 'test-data/large-12mb.bin';

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-import-large-file');

        // Create a 12MB random file — spans at least 2 chunks (5MB each)
        const siteDir = getSiteDir(site);
        execSync(`sudo dd if=/dev/urandom of="${siteDir}/${largeName}" bs=1M count=12 2>/dev/null`);
        execSync(`sudo chown nginx:nginx "${siteDir}/${largeName}"`);
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
        const siteDir = getSiteDir(site);
        execSync(`sudo rm -f "${siteDir}/${largeName}" 2>/dev/null || true`);
    });

    function importUrl() {
        return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
    }

    it('files-sync-initial completes', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
            secret: getSiteSecret(site),
            timeout: 180000,
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('large file exists in import output', () => {
        const importedPath = join(tempDir, 'filesystem-root', getSiteDir(site), largeName);
        assert.ok(existsSync(importedPath), `Expected ${largeName} in output`);

        const stat = statSync(importedPath);
        assert.ok(stat.size >= 12 * 1024 * 1024, `Expected file >= 12MB, got ${stat.size}`);
    });

    it('large file SHA1 matches source', () => {
        const sourcePath = join(getSiteDir(site), largeName);
        const importedPath = join(tempDir, 'filesystem-root', getSiteDir(site), largeName);

        const sourceHash = sha1File(sourcePath);
        const importedHash = sha1File(importedPath);
        assert.equal(sourceHash, importedHash, 'Large file SHA1 mismatch');
    });

    it('all downloaded files have correct hashes', () => {
        const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
        const sourceHashes = hashDirectory(getSiteDir(site));
        const importedHashes = hashDirectory(importedRoot);
        const comparison = compareDirectoryHashes(sourceHashes, importedHashes);
        assert.equal(comparison.different.length, 0,
            `File corruption detected: ${JSON.stringify(comparison.different.slice(0, 5))}`);
        assert.ok(importedHashes.size > 100,
            `Expected substantial file download, got ${importedHashes.size}`);
    });
});
