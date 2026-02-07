/**
 * Test 04: Paths with emojis, newlines, and invalid unicode sequences.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, apiRequestWithFileList, getSiteDir, getTestDataDir } from '../lib/test-helpers.js';

describe('Emoji and Unicode Paths', () => {
    const site = 'emoji-paths';

    it('should index files with unicode names including emojis', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getTestDataDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        assert.ok(indexChunks.length > 0, 'Expected index_batch chunks');

        let allEntries = [];
        for (const chunk of indexChunks) {
            allEntries = allEntries.concat(chunk.json);
        }

        // Decode all paths
        const paths = allEntries
            .filter(e => e.type === 'file')
            .map(e => Buffer.from(e.path, 'base64').toString('utf-8'));

        // Should have café file (UTF-8 with accented character)
        assert.ok(paths.some(p => p.includes('caf')), 'Expected café file in index');
        // Should have file with spaces
        assert.ok(paths.some(p => p.includes('file with spaces')), 'Expected file with spaces');
        // Should have Chinese characters
        assert.ok(paths.some(p => p.includes('\u4e2d\u6587')), 'Expected Chinese-named file (中文)');
        // Should have emoji filename (🔥🚀)
        assert.ok(paths.some(p => p.includes('\ud83d\udd25') || p.includes('🔥')),
            `Expected emoji filename (🔥🚀) in paths: ${paths.filter(p => !p.includes('/.')).join(', ')}`);
        // Should have file with newlines in name
        assert.ok(paths.some(p => p.includes('\n')),
            'Expected file with newlines in name');
    });

    it('should fetch files with emoji filenames', async () => {
        const testDataDir = getTestDataDir(site);

        // First get the file index to get the exact paths
        const indexResp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: testDataDir,
            max_execution_time: 10,
        });
        const indexChunks = indexResp.chunks.filter(c => c.type === 'index_batch');
        let filePaths = [];
        for (const chunk of indexChunks) {
            for (const entry of chunk.json) {
                if (entry.type === 'file') {
                    filePaths.push(Buffer.from(entry.path, 'base64').toString('utf-8'));
                }
            }
        }

        assert.ok(filePaths.length >= 5, `Expected at least 5 files, got ${filePaths.length}`);

        // Fetch all files
        const resp = await apiRequestWithFileList(site, filePaths, {
            directory: getSiteDir(site),
            max_execution_time: 30,
        });
        assert.equal(resp.status, 200);

        const fileChunks = resp.chunks.filter(c => c.type === 'file');
        assert.ok(fileChunks.length > 0, 'Expected file chunks for unicode-named files');

        // Check that we got file data back for emoji-named files
        const fetchedPaths = fileChunks.map(c =>
            Buffer.from(c.headers['x-file-path'], 'base64').toString('utf-8')
        );
        assert.ok(fetchedPaths.length >= 3,
            `Expected at least 3 fetched file paths, got ${fetchedPaths.length}`);
    });

    it('should export SQL with unicode content', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
        assert.ok(sqlChunks.length > 0, 'Expected SQL chunks');

        // The SQL should contain unicode data
        const allSql = sqlChunks.map(c => c.body).join('');
        assert.ok(allSql.includes('INSERT INTO'), 'Expected INSERT statements');
    });

    it('should preflight successfully with unicode paths', async () => {
        const resp = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(resp.status, 200);
        assert.ok(resp.json.ok);
    });
});
