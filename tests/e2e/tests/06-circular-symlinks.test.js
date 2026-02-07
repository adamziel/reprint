/**
 * Test 06: Circular symlink chains.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, getSiteDir, getTestDataDir } from '../lib/test-helpers.js';

describe('Circular Symlinks', () => {
    const site = 'circular-symlinks';

    it('should handle circular symlinks without infinite loops', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getTestDataDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should complete without hanging
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk (no infinite loop)');

        // Should have index entries for regular files
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        let allEntries = [];
        for (const chunk of indexChunks) {
            allEntries = allEntries.concat(chunk.json);
        }

        const fileEntries = allEntries.filter(e => e.type === 'file');
        assert.ok(fileEntries.length > 0, 'Expected regular file entries');

        // Should have symlink entries
        const linkEntries = allEntries.filter(e => e.type === 'link');
        assert.ok(linkEntries.length >= 2, `Expected at least 2 symlink entries, got ${linkEntries.length}`);
    });

    it('should preflight successfully despite circular symlinks', async () => {
        const resp = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(resp.status, 200);
        assert.ok(resp.json.ok);
    });
});
