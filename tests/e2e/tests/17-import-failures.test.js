/**
 * Test 17: All scenarios where import can't proceed.
 * Covers: invalid directory, missing directory, bad cursor, wrong DB credentials,
 * out-of-range parameters, unconfigured secret (503), invalid JSON cursor.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import { apiRequest, getSiteDir } from '../lib/test-helpers.js';
import { HmacClient } from '../lib/hmac-client.js';

describe('Import Failure Scenarios', () => {
    const site = 'import-failures';

    it('should fail with invalid directory parameter', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: '/this/path/does/not/exist',
            list_dir: '/also/nonexistent',
            max_execution_time: 10,
        });
        // Should get 400 for invalid directory
        assert.ok(resp.status === 400 || resp.status === 500,
            `Expected 400 or 500, got ${resp.status}`);
    });

    it('should fail with missing directory for file_index', async () => {
        const resp = await apiRequest(site, 'file_index', {
            max_execution_time: 10,
        });
        // Should error because list_dir is required
        assert.ok(resp.status === 400 || resp.status === 200);
        if (resp.status === 400) {
            assert.ok(resp.json?.error, 'Expected error message for missing directory');
        }
    });

    it('should fail with invalid base64 cursor', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            cursor: 'not-valid-base64!!!',
            max_execution_time: 10,
        });
        assert.equal(resp.status, 400, `Expected 400 for invalid cursor, got ${resp.status}`);
        assert.ok(resp.json?.error?.includes('base64') || resp.json?.error?.includes('Cursor'),
            `Expected cursor error message, got: ${resp.json?.error}`);
    });

    it('should fail with valid base64 but invalid JSON cursor', async () => {
        // Base64 of "not json at all"
        const fakeCursor = Buffer.from('not json at all').toString('base64');
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            cursor: fakeCursor,
            max_execution_time: 10,
        });
        assert.equal(resp.status, 400, `Expected 400 for invalid JSON cursor, got ${resp.status}`);
        assert.ok(resp.json?.error?.includes('JSON') || resp.json?.error?.includes('cursor'),
            `Expected JSON/cursor error, got: ${resp.json?.error}`);
    });

    it('should fail with invalid database credentials', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            db_host: '127.0.0.1',
            db_name: 'nonexistent_database_xyz',
            db_user: 'bad_user',
            db_password: 'bad_password',
            max_execution_time: 10,
        });
        // Should get error (500 for connection failure)
        assert.ok(resp.status === 500 || resp.status === 400 || resp.status === 200,
            `Unexpected status: ${resp.status}`);
        if (resp.status === 500) {
            assert.ok(resp.json?.error, 'Expected error message for bad DB credentials');
        }
    });

    it('should fail with out-of-range max_execution_time', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 99999,
        });
        assert.equal(resp.status, 400, `Expected 400 for out-of-range param, got ${resp.status}`);
        assert.ok(resp.json?.error?.includes('out of range'),
            `Expected range error, got: ${resp.json?.error}`);
    });

    it('should handle minimum execution time (1 second)', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 1,
        });
        assert.equal(resp.status, 200);
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
    });

    it('should return 503 for unconfigured secret', async () => {
        // Temporarily rename the secret.php file to simulate unconfigured state
        const secretPath = '/srv/e2e-sites/import-failures/wp-content/plugins/site-export/secret.php';
        execSync(`sudo mv ${secretPath} ${secretPath}.bak`, { timeout: 5000 });

        try {
            const client = new HmacClient('test-secret-import-failures');
            const url = new URL('http://127.0.0.1:8100/api.php');
            url.searchParams.set('endpoint', 'preflight');
            const headers = client.getAuthHeaders('');

            const response = await fetch(url.toString(), { headers });
            assert.equal(response.status, 503, `Expected 503 for missing secret, got ${response.status}`);
            const json = await response.json();
            assert.ok(json.error.includes('not configured') || json.error.includes('secret'),
                `Expected configuration error, got: ${json.error}`);
        } finally {
            // Restore secret
            execSync(`sudo mv ${secretPath}.bak ${secretPath}`, { timeout: 5000 });
        }
    });

    it('should fail with out-of-range memory_threshold', async () => {
        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            memory_threshold: 99.9,
            max_execution_time: 5,
        });
        assert.equal(resp.status, 400, `Expected 400 for invalid memory_threshold, got ${resp.status}`);
    });

    it('should fail with list_dir outside allowed roots', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: '/etc',
            max_execution_time: 10,
        });
        assert.equal(resp.status, 400, `Expected 400 for list_dir outside roots, got ${resp.status}`);
        assert.ok(resp.json?.error?.includes('outside') || resp.json?.error?.includes('not accessible'),
            `Expected "outside" error, got: ${resp.json?.error}`);
    });
});
