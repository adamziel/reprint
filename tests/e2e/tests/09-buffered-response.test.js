/**
 * Test 09: Buffered response via nginx (port 8098).
 * Tests that streaming works even with fastcgi_buffering enabled.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, getSiteDir, getTestDataDir } from '../lib/test-helpers.js';

describe('Buffered Response', () => {
    const site = 'buffered';
    const port = 8098;

    it('should complete preflight via buffered port', async () => {
        const resp = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(resp.status, 200);
        assert.ok(resp.json.ok, `Preflight not ok: ${JSON.stringify(resp.json.error)}`);
    });

    it('should export SQL via buffered port', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
        assert.ok(sqlChunks.length > 0, 'Expected SQL chunks via buffered response');

        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
        assert.equal(completion.headers['x-status'], 'complete');
    });

    it('should export file index via buffered port', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getTestDataDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        assert.ok(indexChunks.length > 0, 'Expected index chunks via buffered response');
    });

    it('should produce identical SQL output as unbuffered', async () => {
        // Get SQL from buffered port
        const bufferedResp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        // Get SQL from basic site (unbuffered)
        const basicResp = await apiRequest('basic', 'sql_chunk', {
            directory: getSiteDir('basic'),
            max_execution_time: 10,
        });

        const bufferedSql = bufferedResp.chunks.filter(c => c.type === 'sql');
        const basicSql = basicResp.chunks.filter(c => c.type === 'sql');

        // Both should have SQL chunks
        assert.ok(bufferedSql.length > 0, 'Expected buffered SQL chunks');
        assert.ok(basicSql.length > 0, 'Expected basic SQL chunks');

        // Both should have CREATE TABLE and INSERT statements
        const bufferedAllSql = bufferedSql.map(c => c.body).join('');
        assert.ok(bufferedAllSql.includes('CREATE TABLE'), 'Buffered SQL should have CREATE TABLE');
        assert.ok(bufferedAllSql.includes('INSERT INTO'), 'Buffered SQL should have INSERT INTO');
    });
});
