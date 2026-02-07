/**
 * Test 03: Custom wp-content location.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, getSiteDir } from '../lib/test-helpers.js';

describe('Custom WP-Content Location', () => {
    const site = 'custom-wp-content';

    it('should detect WordPress root correctly', async () => {
        const resp = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(resp.status, 200);
        assert.ok(resp.json.ok, `Preflight not ok: ${JSON.stringify(resp.json.error)}`);
        assert.ok(resp.json.database.connected);
    });

    it('should export SQL with auto-detected credentials', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
        assert.ok(sqlChunks.length > 0, 'Expected SQL chunks');
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.equal(completion.headers['x-status'], 'complete');
    });

    it('should index files in test-data directory', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: `${getSiteDir(site)}/test-data`,
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        assert.ok(indexChunks.length > 0, 'Expected index_batch chunks');
    });
});
