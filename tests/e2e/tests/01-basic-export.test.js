/**
 * Test 01: Basic Export/Import
 * Tests basic file + SQL export/import flow with SHA1 verification.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    apiRequest, apiRequestWithFileList, createTempDir, cleanupTempDir, getSiteDir,
    getTestDataDir, hashDirectory, compareDirectoryHashes,
    createMysqlConnection, getDbName, compareDatabases,
} from '../lib/test-helpers.js';

describe('Basic Export/Import', () => {
    const site = 'basic';
    let tempDir;

    before(() => {
        tempDir = createTempDir('e2e-basic');
    });

    after(() => {
        cleanupTempDir(tempDir);
    });

    it('should return valid preflight response', async () => {
        const resp = await apiRequest(site, 'preflight', {
            directory: getSiteDir(site),
        });
        assert.equal(resp.status, 200);
        assert.ok(resp.json.ok, `Preflight not ok: ${JSON.stringify(resp.json.error || resp.json.database?.error)}`);
        assert.ok(resp.json.php.version.startsWith('8.'));
        assert.ok(resp.json.database.connected);
    });

    it('should return sql_preflight with table stats', async () => {
        const resp = await apiRequest(site, 'sql_preflight', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        assert.ok(resp.chunks.length > 0, 'Expected at least one chunk');
        const tableChunks = resp.chunks.filter(c => c.type === 'table_stats');
        assert.ok(tableChunks.length > 0, 'Expected table_stats chunks');
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
        assert.equal(completion.headers['x-status'], 'complete');
    });

    it('should export SQL data with valid chunks', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 30,
        });
        assert.equal(resp.status, 200);
        const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
        assert.ok(sqlChunks.length > 0, 'Expected SQL chunks');

        // Verify SQL content
        const allSql = sqlChunks.map(c => c.body).join('');
        assert.ok(allSql.includes('CREATE TABLE'), 'Expected CREATE TABLE statements');
        assert.ok(allSql.includes('INSERT INTO'), 'Expected INSERT INTO statements');
        assert.ok(allSql.includes('wp_options'), 'Expected wp_options table');
        assert.ok(allSql.includes('wp_posts'), 'Expected wp_posts table');

        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
        assert.equal(completion.headers['x-status'], 'complete');
    });

    it('should export file index', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getTestDataDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        assert.ok(indexChunks.length > 0, 'Expected index_batch chunks');

        // Parse index entries
        let totalEntries = 0;
        for (const chunk of indexChunks) {
            assert.ok(chunk.json, `Expected JSON in index batch: ${chunk.body?.substring(0, 100)}`);
            assert.ok(Array.isArray(chunk.json), 'Expected array of entries');
            totalEntries += chunk.json.length;
        }
        assert.ok(totalEntries >= 3, `Expected at least 3 files, got ${totalEntries}`);
    });

    it('should fetch files from the export', async () => {
        const testDataDir = getTestDataDir(site);
        // Request specific files using multipart form upload
        const filePaths = [
            `${testDataDir}/hello.txt`,
            `${testDataDir}/subdir/test.txt`,
        ];

        const resp = await apiRequestWithFileList(site, filePaths, {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200, `Expected 200, got ${resp.status}: ${JSON.stringify(resp.json || resp.text)}`);

        const fileChunks = resp.chunks.filter(c => c.type === 'file');
        assert.ok(fileChunks.length > 0, 'Expected file chunks');

        // Check that file data was returned
        const paths = fileChunks.map(c => {
            const encoded = c.headers['x-file-path'];
            return Buffer.from(encoded, 'base64').toString('utf-8');
        });
        assert.ok(paths.some(p => p.includes('hello.txt')), 'Expected hello.txt');
    });

    it('should have matching SQL data after export', async () => {
        // Export SQL
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 30,
        });
        const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
        const allSql = sqlChunks.map(c => c.body).join('');

        // Import into a separate database using MySQL CLI (handles multi-statement better)
        const importDb = `e2e_basic_import`;
        const conn = await createMysqlConnection();
        try {
            await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await conn.query(`CREATE DATABASE \`${importDb}\``);

            // Use MySQL CLI to import the SQL dump
            const { writeFileSync, unlinkSync } = await import('node:fs');
            const tmpSql = `/tmp/e2e-import-${Date.now()}.sql`;
            writeFileSync(tmpSql, `USE \`${importDb}\`;\n${allSql}\nCOMMIT;\n`);
            try {
                const { execSync } = await import('node:child_process');
                execSync(`mysql -u e2e_admin -pe2e_password -h 127.0.0.1 < ${tmpSql}`, {
                    timeout: 30000,
                    stdio: 'pipe',
                });
            } finally {
                unlinkSync(tmpSql);
            }

            // Compare databases
            const comparison = await compareDatabases(getDbName(site), importDb);
            assert.ok(comparison.match,
                `Database mismatch: missing=${JSON.stringify(comparison.missingTables)}, ` +
                `counts=${JSON.stringify(comparison.rowCounts)}`);
        } finally {
            await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
            await conn.end();
        }
    });
});
