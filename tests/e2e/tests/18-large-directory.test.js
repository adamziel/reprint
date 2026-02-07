/**
 * Test 18: Large directory with many files.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, getSiteDir, getTestDataDir } from '../lib/test-helpers.js';

describe('Large Directory', () => {
    const site = 'large-directory';

    it('should index a directory with 2000+ files', async () => {
        const testDataDir = getTestDataDir(site);

        // May need multiple requests due to execution time limits
        let totalEntries = 0;
        let cursor = null;
        let requests = 0;
        const maxRequests = 20;

        while (requests < maxRequests) {
            const params = {
                directory: getSiteDir(site),
                list_dir: testDataDir,
                max_execution_time: 10,
                batch_size: 500,
            };
            if (cursor) {
                params.cursor = cursor;
            }

            const resp = await apiRequest(site, 'file_index', params);
            assert.equal(resp.status, 200, `Request ${requests} failed`);
            requests++;

            const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
            for (const chunk of indexChunks) {
                totalEntries += chunk.json.length;
            }

            const completion = resp.chunks.find(c => c.type === 'completion');
            assert.ok(completion, `Expected completion chunk on request ${requests}`);

            if (completion.headers['x-status'] === 'complete') {
                break;
            }

            // Get cursor for resumption
            cursor = completion.headers['x-cursor'];
            assert.ok(cursor, 'Expected cursor for partial response');
        }

        // Should have indexed all 2000 files plus directories
        assert.ok(totalEntries >= 2000,
            `Expected at least 2000 entries, got ${totalEntries} in ${requests} requests`);
    });

    it('should export SQL even with large filesystem', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
        assert.ok(sqlChunks.length > 0, 'Expected SQL chunks');
    });
});
