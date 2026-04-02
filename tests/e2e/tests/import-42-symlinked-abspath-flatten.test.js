/**
 * Test 42: flat-document-root when ABSPATH goes through a symlink
 *
 * Simulates a WordPress.com Atomic-like environment where:
 * - WP core files live in a separate directory (/tmp/e2e-symlinked-core/)
 * - The site has a symlink (wordpress/) pointing to that directory
 * - wp-config.php defines ABSPATH as __DIR__ . '/wordpress/' — through the symlink
 *
 * Before the fix, the exporter reported the unresolved symlink path as abspath
 * (e.g. /srv/e2e-sites/symlinked-abspath/wordpress). The importer would download
 * files under the resolved real path (/tmp/e2e-symlinked-core/...) but then
 * flat-document-root would try to find the ABSPATH directory at the unresolved
 * symlink path in fs-root — which is a broken symlink locally — and fail with
 * "WordPress ABSPATH directory not found".
 *
 * The fix: the exporter now applies realpath() to ABSPATH, matching the convention
 * used for all other paths (content_dir, plugins_dir, etc.). This ensures flat-
 * document-root looks for the directory at the resolved location where the files
 * were actually downloaded.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, writeFileSync,
    mkdirSync, symlinkSync,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const CORE_DIR = '/tmp/e2e-symlinked-core';

describe('Import: Flat Document Root with symlinked ABSPATH', () => {
    const site = 'symlinked-abspath';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                // Simulate Atomic-like layout:
                //   /tmp/e2e-symlinked-core/  — real directory with WP core files
                //   {siteDir}/wordpress/       — symlink to /tmp/e2e-symlinked-core/
                //   {siteDir}/wp-config.php    — defines ABSPATH as __DIR__ . '/wordpress/'
                //
                // This means ABSPATH resolves through a symlink, just like Atomic's
                // /wordpress symlink.
                execSync(`sudo rm -rf ${CORE_DIR}`);
                mkdirSync(CORE_DIR, { recursive: true });

                // Move WP core files into the separate core directory.
                // Keep wp-content in the site dir (not behind the symlink).
                for (const entry of ['wp-admin', 'wp-includes', 'wp-load.php',
                    'wp-settings.php', 'wp-blog-header.php', 'wp-cron.php',
                    'wp-login.php', 'wp-signup.php', 'index.php', 'xmlrpc.php',
                    'wp-activate.php', 'wp-comments-post.php', 'wp-links-opml.php',
                    'wp-mail.php', 'wp-trackback.php']) {
                    const src = join(siteDir, entry);
                    if (existsSync(src)) {
                        execSync(`mv "${src}" "${CORE_DIR}/${entry}"`);
                    }
                }

                // Create the symlink: siteDir/wordpress -> /tmp/e2e-symlinked-core
                symlinkSync(CORE_DIR, join(siteDir, 'wordpress'));

                // Rewrite wp-config.php so ABSPATH goes through the symlink.
                // This is the key: __DIR__ is the site dir, so ABSPATH becomes
                // {siteDir}/wordpress/ — an unresolved symlink path.
                //
                // WP_CONTENT_DIR must be set explicitly because WordPress defaults
                // it to ABSPATH . 'wp-content', which would resolve through the
                // symlink to CORE_DIR/wp-content (doesn't exist). The real
                // wp-content stays in the site dir.
                writeFileSync(join(siteDir, 'wp-config.php'), `<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'e2e_symlinked_abspath');
define('DB_USER', 'e2e_admin');
define('DB_PASSWORD', 'e2e_password');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY',         'e2e-test-key-1');
define('SECURE_AUTH_KEY',  'e2e-test-key-2');
define('LOGGED_IN_KEY',    'e2e-test-key-3');
define('NONCE_KEY',        'e2e-test-key-4');
define('AUTH_SALT',        'e2e-test-salt-1');
define('SECURE_AUTH_SALT', 'e2e-test-salt-2');
define('LOGGED_IN_SALT',   'e2e-test-salt-3');
define('NONCE_SALT',       'e2e-test-salt-4');
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/wordpress/' );
}
require_once ABSPATH . 'wp-settings.php';
`);
            },
            afterPermissions: async () => {
                execSync(`sudo chown -R nginx:nginx ${CORE_DIR}`);
            },
        });

        tempDir = createTempDir('e2e-symlinked-abspath');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('preflight reports resolved (realpath) abspath, not the symlink path', () => {
        const result = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `preflight failed:\n${result.stderr}`);

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const pathsUrls = state.preflight?.data?.database?.wp?.paths_urls;
        assert.ok(pathsUrls, 'Expected paths_urls in preflight data');

        // The critical assertion: abspath should be the resolved real path
        // (/tmp/e2e-symlinked-core), NOT the symlink path (.../symlinked-abspath/wordpress).
        assert.ok(
            pathsUrls.abspath?.includes('e2e-symlinked-core'),
            `Expected abspath to be the resolved real path containing 'e2e-symlinked-core', ` +
            `got: ${pathsUrls.abspath}`,
        );
        assert.ok(
            !pathsUrls.abspath?.includes('symlinked-abspath/wordpress'),
            `abspath should NOT contain the unresolved symlink path 'symlinked-abspath/wordpress', ` +
            `got: ${pathsUrls.abspath}`,
        );
    });

    it('files-sync completes with follow_symlinks', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--follow-symlinks'],
        });
        assert.equal(result.exitCode, 0, `files-sync failed:\n${result.stderr}`);
    });

    it('WP core files exist at the resolved path in fs-root', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const abspath = state.preflight?.data?.database?.wp?.paths_urls?.abspath;
        assert.ok(abspath, 'abspath not found in state');

        const localAbspath = join(fsRootDir(tempDir), abspath);
        assert.ok(
            existsSync(localAbspath),
            `ABSPATH directory should exist at resolved path: ${localAbspath}`,
        );
        assert.ok(
            existsSync(join(localAbspath, 'wp-admin')),
            'wp-admin should exist under resolved abspath',
        );
        assert.ok(
            existsSync(join(localAbspath, 'wp-includes')),
            'wp-includes should exist under resolved abspath',
        );
    });

    describe('flat-document-root', () => {
        let flattenTo;

        beforeAll(() => {
            flattenTo = join(tempDir, 'flattened');
            const result = runImporter(importUrl(), tempDir, 'flat-document-root', {
                secret: getSiteSecret(site),
                extraArgs: [`--flatten-to=${flattenTo}`],
            });
            assert.equal(
                result.exitCode, 0,
                `flat-document-root failed:\n${result.stderr}\n${result.stdout}`,
            );
        });

        it('creates a working flattened layout', () => {
            assert.ok(existsSync(join(flattenTo, 'wp-load.php')), 'wp-load.php should exist');
            assert.ok(existsSync(join(flattenTo, 'index.php')), 'index.php should exist');
        });

        it('wp-admin is traversable', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-admin', 'admin.php')),
                'wp-admin/admin.php should be accessible through flatten-to',
            );
        });

        it('wp-includes is traversable', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-includes', 'version.php')),
                'wp-includes/version.php should be accessible through flatten-to',
            );
        });

        it('wp-content is accessible', () => {
            assert.ok(
                existsSync(join(flattenTo, 'wp-content')),
                'wp-content should be accessible through flatten-to',
            );
        });
    });
});
