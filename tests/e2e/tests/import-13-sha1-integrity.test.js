/**
 * Test 13: SHA1 Integrity via import.php
 * Tests that file hashes match after sync, including large binary files.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, compareDirectoryHashes, sha1File,
    assertFileCount, assertSiteMirror,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: SHA1 Integrity', () => {
    const site = 'sha1-verify';
    let tempDir;

    before(async () => {
        await ensureSite(site, {
            files: 'none',
            afterCreate: async (siteDir) => {
                const { execSync } = await import('node:child_process');
                execSync(`sudo mkdir -p "${siteDir}/test-data/deep/nested/path"`);
                for (let i = 1; i <= 20; i++) {
                    execSync(`printf "File content number ${i} with some padding to make it non-trivial\\n" | sudo tee "${siteDir}/test-data/file-${i}.txt" > /dev/null`);
                }
                execSync(`sudo dd if=/dev/urandom of="${siteDir}/test-data/large-binary.bin" bs=1024 count=256 2>/dev/null`);
                execSync(`echo "Deep nested content" | sudo tee "${siteDir}/test-data/deep/nested/path/deep-file.txt" > /dev/null`);
                execSync(`sudo chown -R nginx:nginx "${siteDir}"`);
            },
        });
        tempDir = createTempDir('e2e-import-sha1');
    });

    after(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
    }

    it('files-sync-initial completes', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('all file hashes match source', () => {
        const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
        const sourceHashes = hashDirectory(getSiteDir(site));
        const importedHashes = hashDirectory(importedRoot);

        const comparison = compareDirectoryHashes(sourceHashes, importedHashes);
        assert.ok(comparison.match,
            `File mismatch: missing=${comparison.missing.length}, different=${comparison.different.length}\n` +
            `missing: ${JSON.stringify(comparison.missing.slice(0, 5))}\n` +
            `different: ${JSON.stringify(comparison.different.slice(0, 5))}`);
    });

    it('indexed at least 3000 files from remote', () => {
        assertFileCount(tempDir);
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(tempDir, 'filesystem-root', getSiteDir(site)));
    });

    it('at least 20 files with correct hashes', () => {
        const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
        const importedHashes = hashDirectory(importedRoot);
        assert.ok(importedHashes.size >= 20, `Expected at least 20 files, got ${importedHashes.size}`);
    });

    it('large binary file (256KB) hash matches', () => {
        const sourcePath = join(getSiteDir(site), 'test-data', 'large-binary.bin');
        const importedPath = join(tempDir, 'filesystem-root', getSiteDir(site), 'test-data', 'large-binary.bin');

        assert.ok(existsSync(sourcePath), 'Expected source large-binary.bin to exist');
        assert.ok(existsSync(importedPath), 'Expected imported large-binary.bin to exist');

        const sourceHash = sha1File(sourcePath);
        const importedHash = sha1File(importedPath);
        assert.equal(sourceHash, importedHash, 'Large binary file hash mismatch');
    });
});
