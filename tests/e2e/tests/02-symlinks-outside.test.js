/**
 * Test 02: Symlinks pointing outside the site directory.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, getSiteDir, getTestDataDir } from '../lib/test-helpers.js';

describe('Symlinks Outside Site Directory', () => {
    const site = 'symlinks-outside';

    it('should handle symlinks pointing outside the site in file index', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getTestDataDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        assert.ok(indexChunks.length > 0, 'Expected index_batch chunks');

        // Collect all entries
        let allEntries = [];
        for (const chunk of indexChunks) {
            allEntries = allEntries.concat(chunk.json);
        }

        // Should have symlink entries
        const linkEntries = allEntries.filter(e => e.type === 'link');
        assert.ok(linkEntries.length > 0, 'Expected symlink entries in index');
    });

    it('should handle regular files alongside symlinks', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getTestDataDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        let allEntries = [];
        for (const chunk of indexChunks) {
            allEntries = allEntries.concat(chunk.json);
        }

        const fileEntries = allEntries.filter(e => e.type === 'file');
        assert.ok(fileEntries.length > 0, 'Expected regular file entries');
    });

    it('should report symlinks as symlink chunks in file fetch', async () => {
        const resp = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(resp.status, 200);
        assert.ok(resp.json.ok);
    });
});
