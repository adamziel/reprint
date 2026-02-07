/**
 * Test 08: MySQL user that can't run SHOW CREATE TABLE or has limited privileges.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, getSiteDir } from '../lib/test-helpers.js';

describe('MySQL Restricted User', () => {
    const site = 'mysql-restricted';

    it('should preflight and detect database connectivity', async () => {
        const resp = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(resp.status, 200);
        // The restricted user should be able to connect
        assert.ok(resp.json.database.connected, 'Expected database connection');
    });

    it('should list tables via sql_preflight', async () => {
        const resp = await apiRequest(site, 'sql_preflight', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const tableChunks = resp.chunks.filter(c => c.type === 'table_stats');
        assert.ok(tableChunks.length > 0, 'Expected table stats even with restricted user');
    });

    it('should attempt SQL export with restricted permissions', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // With restricted user, we expect either SQL data or error chunks
        // The restricted user has SELECT on the database, so it should work
        // but SHOW CREATE TABLE may fail
        const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
        const errorChunks = resp.chunks.filter(c => c.type === 'error');

        // Should have some response
        assert.ok(sqlChunks.length > 0 || errorChunks.length > 0,
            'Expected SQL or error chunks from restricted user');

        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
    });
});
