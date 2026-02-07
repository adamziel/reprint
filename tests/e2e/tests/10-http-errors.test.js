/**
 * Test 10: HTTP errors injected via test hooks.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    apiRequest, getSiteDir, getTestDataDir,
    writeTestHooks, removeTestHooks,
    writeHookState, readHookState, clearHookState,
} from '../lib/test-helpers.js';

describe('HTTP Error Simulation', () => {
    const site = 'http-errors';

    before(() => {
        clearHookState(site);
    });

    after(() => {
        removeTestHooks(site);
        clearHookState(site);
    });

    it('should inject error mid-stream via test hook', async () => {
        // Set up test hooks that throw an error on the second SQL batch
        writeHookState(site, { batchCount: 0 });
        writeTestHooks(site, `
function test_hook_before_sql_batch(&$sql, $cursor) {
    $stateFile = '/tmp/e2e-hook-state-http-errors';
    $state = json_decode(file_get_contents($stateFile), true);
    $state['batchCount']++;
    file_put_contents($stateFile, json_encode($state));
    if ($state['batchCount'] >= 2) {
        throw new RuntimeException('Test-injected error on batch ' . $state['batchCount']);
    }
}
        `);

        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 30,
            fragments_per_batch: 1,
        });
        assert.equal(resp.status, 200);

        // Should have error chunks from the injected error
        const errorChunks = resp.chunks.filter(c => c.type === 'error');
        assert.ok(errorChunks.length > 0, 'Expected error chunks from injected error');

        // Error should mention our test message
        const errorMessages = errorChunks.map(c => c.body || JSON.stringify(c.json));
        assert.ok(errorMessages.some(m => m.includes('Test-injected error')),
            `Expected test-injected error message, got: ${errorMessages.join(', ')}`);
    });

    it('should have partial status when error occurs mid-stream', async () => {
        clearHookState(site);
        writeHookState(site, { batchCount: 0 });
        writeTestHooks(site, `
function test_hook_before_sql_batch(&$sql, $cursor) {
    $stateFile = '/tmp/e2e-hook-state-http-errors';
    $state = json_decode(file_get_contents($stateFile), true);
    $state['batchCount']++;
    file_put_contents($stateFile, json_encode($state));
    if ($state['batchCount'] >= 2) {
        throw new RuntimeException('Error on batch ' . $state['batchCount']);
    }
}
        `);

        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 30,
            fragments_per_batch: 1,
        });

        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk even after error');
        assert.equal(completion.headers['x-status'], 'partial',
            'Expected partial status after mid-stream error');
    });
});
