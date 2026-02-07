/**
 * Test 11: Error Messages via import.php
 * Tests that various error conditions produce useful error messages.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
} from '../lib/test-helpers.js';

describe('Import: Error Messages', () => {
    let tempDir;

    before(() => {
        tempDir = createTempDir('e2e-import-errors');
    });

    after(() => {
        cleanupTempDir(tempDir);
    });

    it('wrong HMAC secret produces auth error', () => {
        const site = 'hmac-errors';
        const url = `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        const dir = createTempDir('e2e-import-wrong-hmac');
        try {
            const result = runImporter(url, dir, 'files-sync-initial', {
                secret: 'wrong-secret-value',
            });
            assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code for wrong HMAC');
            const output = (result.stdout + result.stderr).toLowerCase();
            assert.ok(
                output.includes('auth') || output.includes('signature') || output.includes('403') || output.includes('hmac') || output.includes('unauthorized'),
                `Expected auth-related error message, got: ${result.stdout + result.stderr}`
            );
        } finally {
            cleanupTempDir(dir);
        }
    });

    it('unreachable server produces connection error', () => {
        const url = 'http://127.0.0.1:19999/api.php?directory=/tmp';
        const dir = createTempDir('e2e-import-unreachable');
        try {
            const result = runImporter(url, dir, 'files-sync-initial', {
                secret: 'any-secret',
                timeout: 15000,
            });
            assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code for unreachable server');
            const output = (result.stdout + result.stderr).toLowerCase();
            assert.ok(
                output.includes('connect') || output.includes('refused') || output.includes('error') || output.includes('curl') || output.includes('failed'),
                `Expected connection error message, got: ${result.stdout + result.stderr}`
            );
        } finally {
            cleanupTempDir(dir);
        }
    });

    it('invalid command produces error', () => {
        const site = 'basic';
        const url = `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        const dir = createTempDir('e2e-import-bad-cmd');
        try {
            const result = runImporter(url, dir, 'not-a-real-command', {
                secret: getSiteSecret(site),
            });
            assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code for invalid command');
            const output = (result.stdout + result.stderr).toLowerCase();
            assert.ok(
                output.includes('invalid') || output.includes('command'),
                `Expected invalid command error message, got: ${result.stdout + result.stderr}`
            );
        } finally {
            cleanupTempDir(dir);
        }
    });

    it('files-sync-delta without prior initial fails with useful error', () => {
        const site = 'basic';
        const url = `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        const dir = createTempDir('e2e-import-delta-no-initial');
        try {
            const result = runImporter(url, dir, 'files-sync-delta', {
                secret: getSiteSecret(site),
            });
            assert.notEqual(result.exitCode, 0, 'Expected non-zero exit code');
            const output = (result.stdout + result.stderr).toLowerCase();
            assert.ok(
                output.includes('initial') || output.includes('files-sync-initial'),
                `Expected message about needing initial sync, got: ${result.stdout + result.stderr}`
            );
        } finally {
            cleanupTempDir(dir);
        }
    });
});
