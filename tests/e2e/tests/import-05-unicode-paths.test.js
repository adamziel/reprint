/**
 * Test 05: Unicode/Emoji Paths via import.php
 * Tests file sync and SQL sync with unicode filenames.
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

describe('Import: Unicode Paths', () => {
    const site = 'emoji-paths';
    let tempDir;

    before(() => {
        tempDir = createTempDir('e2e-import-unicode');
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

    it('files with emoji, accented chars, spaces, and Chinese chars exist', () => {
        const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);

        // Check for files with special names
        const sourceHashes = hashDirectory(getSiteDir(site));
        const importedHashes = hashDirectory(importedRoot);

        // Check some known unicode filenames exist in imported set
        const importedPaths = [...importedHashes.keys()];

        // café.txt (accented)
        assert.ok(
            importedPaths.some(p => p.includes('caf\u00e9')),
            `Expected café.txt in imported files, got paths containing 'caf': ${importedPaths.filter(p => p.includes('caf')).join(', ')}`
        );

        // Chinese characters
        assert.ok(
            importedPaths.some(p => p.includes('\u4e2d\u6587')),
            `Expected Chinese filename in imported files`
        );

        // File with spaces
        assert.ok(
            importedPaths.some(p => p.includes('file with spaces')),
            `Expected 'file with spaces.txt' in imported files`
        );
    });

    it('file hashes match source', () => {
        const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
        const sourceHashes = hashDirectory(getSiteDir(site));
        const importedHashes = hashDirectory(importedRoot);

        const comparison = compareDirectoryHashes(sourceHashes, importedHashes);
        assert.ok(comparison.match,
            `File mismatch: missing=${JSON.stringify(comparison.missing.slice(0, 5))}, ` +
            `different=${JSON.stringify(comparison.different.slice(0, 5))}`);
    });

    it('sql-sync completes with valid dump', () => {
        const sqlDir = createTempDir('e2e-import-unicode-sql');
        try {
            const result = runImporter(importUrl(), sqlDir, 'sql-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}`);

            const sqlFile = join(sqlDir, 'db.sql');
            assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');

            const sql = readFileSync(sqlFile, 'utf-8');
            assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in db.sql');
        } finally {
            cleanupTempDir(sqlDir);
        }
    });
});
