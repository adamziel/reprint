/**
 * Test 16: Custom WP Content via import.php
 * Tests file sync and SQL sync for site with non-standard wp-content directory.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, mkdirSync, copyFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, compareDirectoryHashes,
    assertFileCount, assertSiteMirror,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Custom WP Content', () => {
    const site = 'custom-wp-content';
    let tempDir;

    before(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                const customPlugin = join(siteDir, 'custom-content', 'plugins', 'site-export', 'generic');
                const srcPlugin = join(siteDir, 'wp-content', 'plugins', 'site-export');
                mkdirSync(customPlugin, { recursive: true });
                copyFileSync(join(srcPlugin, 'api.php'), join(customPlugin, '..', 'api.php'));
                for (const f of readdirSync(join(srcPlugin, 'generic')).filter(f => f.endsWith('.php'))) {
                    copyFileSync(join(srcPlugin, 'generic', f), join(customPlugin, f));
                }
                copyFileSync(join(srcPlugin, 'secret.php'), join(customPlugin, '..', 'secret.php'));
            },
        });
        tempDir = createTempDir('e2e-import-custom-wp');
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

    it('files downloaded correctly', () => {
        const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
        assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);

        const sourceHashes = hashDirectory(getSiteDir(site));
        const importedHashes = hashDirectory(importedRoot);

        const comparison = compareDirectoryHashes(sourceHashes, importedHashes);
        assert.ok(comparison.match,
            `File mismatch: missing=${JSON.stringify(comparison.missing.slice(0, 5))}, ` +
            `different=${JSON.stringify(comparison.different.slice(0, 5))}`);
    });

    it('indexed at least 3000 files from remote', () => {
        assertFileCount(tempDir);
    });

    it('imported files form a valid WordPress site mirror', () => {
        assertSiteMirror(join(tempDir, 'filesystem-root', getSiteDir(site)));
    });

    it('sql-sync completes with valid dump', () => {
        const sqlDir = createTempDir('e2e-import-custom-wp-sql');
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
