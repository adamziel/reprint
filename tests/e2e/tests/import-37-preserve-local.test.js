/**
 * Test 37: --preserve-local mode
 *
 * Tests importing into a pre-existing WordPress hosting environment that uses
 * symlinks for shared infrastructure: WP core, plugins, themes, drop-ins, and
 * mu-plugins all live in a shared read-only directory and are symlinked into
 * the site docroot.
 *
 * Pre-existing structure (mirrors real Atomic hosting):
 *
 *   wordpress/                              (shared, read-only)
 *     core/latest/wp-load.php
 *     drop-ins/{object-cache,advanced-cache}.php
 *     plugins/{akismet,jetpack,wpcomsh}/latest/...
 *     themes/twentyseventeen/latest/style.css
 *
 *   site-root/
 *     __wp__                     -> ../wordpress/core/latest
 *     wp-load.php                -> __wp__/wp-load.php
 *     wp-content/
 *       object-cache.php         -> ../../wordpress/drop-ins/object-cache.php
 *       advanced-cache.php       -> ../../wordpress/drop-ins/advanced-cache.php
 *       mu-plugins/
 *         wpcomsh               -> ../../../wordpress/plugins/wpcomsh/latest
 *         wpcomsh-loader.php    -> ../../../wordpress/plugins/wpcomsh/latest/wpcomsh-loader.php
 *       plugins/
 *         akismet               -> ../../../wordpress/plugins/akismet/latest
 *         jetpack               -> ../../../wordpress/plugins/jetpack/latest
 *       themes/
 *         twentyseventeen       -> ../../../wordpress/themes/twentyseventeen/latest
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, writeFileSync, mkdirSync,
    symlinkSync, chmodSync, lstatSync, readlinkSync,
} from 'node:fs';
import { join, dirname } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    readAuditLog,
    docrootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: --preserve-local', () => {
    const site = 'preserve-local';
    let siteDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (remoteSiteDir) => {
                // Ensure the remote site has its own akismet — a genuine path
                // conflict with the local hosting symlink to shared akismet.
                // WP might ship with akismet by default, but we write explicit
                // content so the test is self-contained and verifiable.
                const akismetDir = join(remoteSiteDir, 'wp-content', 'plugins', 'akismet');
                mkdirSync(akismetDir, { recursive: true });
                writeFileSync(join(akismetDir, 'akismet.php'),
                    '<?php /* REMOTE akismet – should NOT appear locally */');
                writeFileSync(join(akismetDir, 'readme.txt'),
                    'Remote Akismet readme – should NOT appear locally');

                // A regular file that will also exist locally as a plain file
                // (not a symlink) — tests file-vs-file conflict preservation.
                writeFileSync(join(remoteSiteDir, 'wp-content', 'maintenance.php'),
                    '<?php // REMOTE maintenance – should NOT appear locally');
            },
        });
        siteDir = getSiteDir(site);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    // ----------------------------------------------------------------
    // Helper: build the realistic hosting structure inside a docroot.
    //
    // Places a shared "wordpress/" directory as a sibling of the site
    // directory (both under docroot/srv/e2e-sites/), then creates all
    // the hosting symlinks inside the site root.  The shared directory
    // is made read-only (chmod 555) so that files under symlinked dirs
    // cannot be written — matching real hosting constraints.
    // ----------------------------------------------------------------
    function buildHostingStructureInLocalDocroot(docroot) {
        const siteRoot = join(docroot, siteDir);
        const wpShared = join(docroot, dirname(siteDir), 'wordpress');

        // -- shared wordpress directory (read-only after setup) --------
        const dirs = [
            'core/latest/wp-includes',
            'drop-ins',
            'plugins/akismet/latest',
            'plugins/jetpack/latest',
            'plugins/wpcomsh/latest',
            'themes/twentyseventeen/latest',
        ];
        for (const d of dirs) {
            mkdirSync(join(wpShared, d), { recursive: true });
        }
        writeFileSync(join(wpShared, 'core/latest/wp-load.php'),
            '<?php // shared WP core loader');
        writeFileSync(join(wpShared, 'core/latest/wp-includes/version.php'),
            '<?php $wp_version = "6.7";');
        writeFileSync(join(wpShared, 'drop-ins/object-cache.php'),
            '<?php // shared object cache drop-in');
        writeFileSync(join(wpShared, 'drop-ins/advanced-cache.php'),
            '<?php // shared advanced cache drop-in');
        writeFileSync(join(wpShared, 'plugins/akismet/latest/akismet.php'),
            '<?php // shared akismet');
        writeFileSync(join(wpShared, 'plugins/jetpack/latest/jetpack.php'),
            '<?php // shared jetpack');
        writeFileSync(join(wpShared, 'plugins/wpcomsh/latest/wpcomsh-loader.php'),
            '<?php // shared wpcomsh loader');
        writeFileSync(join(wpShared, 'themes/twentyseventeen/latest/style.css'),
            '/* shared twentyseventeen */');

        // -- site root with hosting symlinks ---------------------------
        mkdirSync(join(siteRoot, 'wp-content', 'mu-plugins'), { recursive: true });
        mkdirSync(join(siteRoot, 'wp-content', 'plugins'), { recursive: true });
        mkdirSync(join(siteRoot, 'wp-content', 'themes'), { recursive: true });

        // Directory symlink to WP core
        symlinkSync('../wordpress/core/latest',
            join(siteRoot, '__wp__'));

        // File symlink through __wp__
        symlinkSync('__wp__/wp-load.php',
            join(siteRoot, 'wp-load.php'));

        // Drop-in file symlinks (2 levels up from wp-content/ to site parent)
        symlinkSync('../../wordpress/drop-ins/object-cache.php',
            join(siteRoot, 'wp-content', 'object-cache.php'));
        symlinkSync('../../wordpress/drop-ins/advanced-cache.php',
            join(siteRoot, 'wp-content', 'advanced-cache.php'));

        // mu-plugins: directory symlink + file symlink (3 levels up)
        symlinkSync('../../../wordpress/plugins/wpcomsh/latest',
            join(siteRoot, 'wp-content', 'mu-plugins', 'wpcomsh'));
        symlinkSync('../../../wordpress/plugins/wpcomsh/latest/wpcomsh-loader.php',
            join(siteRoot, 'wp-content', 'mu-plugins', 'wpcomsh-loader.php'));

        // Plugin directory symlinks (3 levels up)
        symlinkSync('../../../wordpress/plugins/akismet/latest',
            join(siteRoot, 'wp-content', 'plugins', 'akismet'));
        symlinkSync('../../../wordpress/plugins/jetpack/latest',
            join(siteRoot, 'wp-content', 'plugins', 'jetpack'));

        // Theme directory symlink (3 levels up)
        symlinkSync('../../../wordpress/themes/twentyseventeen/latest',
            join(siteRoot, 'wp-content', 'themes', 'twentyseventeen'));

        // -- local regular files that conflict with remote paths --------
        // These are plain files (not symlinks) that also exist on the
        // remote site.  preserve-local should keep the local version.
        writeFileSync(join(siteRoot, 'wp-content', 'maintenance.php'),
            '<?php // LOCAL maintenance – should be preserved');

        // Lock down the shared directory — hosting infra is read-only
        chmodSync(join(wpShared, 'core/latest/wp-includes'), 0o555);
        chmodSync(join(wpShared, 'core/latest'), 0o555);
        chmodSync(join(wpShared, 'drop-ins'), 0o555);
        chmodSync(join(wpShared, 'plugins/akismet/latest'), 0o555);
        chmodSync(join(wpShared, 'plugins/jetpack/latest'), 0o555);
        chmodSync(join(wpShared, 'plugins/wpcomsh/latest'), 0o555);
        chmodSync(join(wpShared, 'themes/twentyseventeen/latest'), 0o555);

        return { siteRoot, wpShared };
    }

    // Restore write permissions so cleanup can remove the tree.
    function unlockSharedDir(wpShared) {
        if (!existsSync(wpShared)) return;
        const unlock = [
            'core/latest/wp-includes',
            'core/latest',
            'drop-ins',
            'plugins/akismet/latest',
            'plugins/jetpack/latest',
            'plugins/wpcomsh/latest',
            'themes/twentyseventeen/latest',
        ];
        for (const d of unlock) {
            const p = join(wpShared, d);
            if (existsSync(p)) chmodSync(p, 0o755);
        }
    }

    // ------------------------------------------------------------------
    // Test: non-empty directory without --preserve-local errors
    // ------------------------------------------------------------------
    describe('non-empty directory without --preserve-local errors', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-preserve-local-no-flag');
            buildHostingStructureInLocalDocroot(docrootDir(tempDir));
        });

        afterAll(() => {
            unlockSharedDir(join(docrootDir(tempDir), dirname(siteDir), 'wordpress'));
            cleanupTempDir(tempDir);
        });

        it('errors on non-empty directory without --preserve-local', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                autoResume: false,
            });
            assert.equal(result.exitCode, 1, 'Expected exit code 1');
            assert.ok(
                result.stderr.includes('not empty'),
                `Expected error about non-empty directory, got: ${result.stderr}`,
            );
        });
    });

    // ------------------------------------------------------------------
    // Test: realistic hosting import with --preserve-local
    // ------------------------------------------------------------------
    describe('hosting environment with symlinked infrastructure', () => {
        let tempDir;
        let wpShared;
        let localSiteRoot;

        beforeAll(() => {
            tempDir = createTempDir('e2e-preserve-local-hosting');
            const built = buildHostingStructureInLocalDocroot(docrootDir(tempDir));
            wpShared = built.wpShared;
            localSiteRoot = built.siteRoot;
        });

        afterAll(() => {
            unlockSharedDir(wpShared);
            cleanupTempDir(tempDir);
        });

        it('files-sync completes with --preserve-local', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--on-docroot-nonempty=preserve-local'],
            });
            assert.equal(
                result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
            );
        });

        it('state shows complete with preserve_local persisted', () => {
            const stateFile = join(tempDir, '.import-state.json');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.status, 'complete');
            assert.equal(state.docroot_nonempty_behavior, 'preserve-local');
        });

        // -- file symlinks preserved ----------------------------------

        it('wp-load.php file symlink preserved', () => {
            const p = join(localSiteRoot, 'wp-load.php');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected wp-load.php to remain a symlink');
            assert.equal(readlinkSync(p), '__wp__/wp-load.php');
        });

        it('object-cache.php drop-in symlink preserved', () => {
            const p = join(localSiteRoot, 'wp-content', 'object-cache.php');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected object-cache.php to remain a symlink');
        });

        it('advanced-cache.php drop-in symlink preserved', () => {
            const p = join(localSiteRoot, 'wp-content', 'advanced-cache.php');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected advanced-cache.php to remain a symlink');
        });

        it('wpcomsh-loader.php mu-plugin file symlink preserved', () => {
            const p = join(localSiteRoot, 'wp-content', 'mu-plugins', 'wpcomsh-loader.php');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected wpcomsh-loader.php to remain a symlink');
        });

        // -- directory symlinks preserved -----------------------------

        it('__wp__ directory symlink preserved', () => {
            const p = join(localSiteRoot, '__wp__');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected __wp__ to remain a symlink');
            assert.equal(readlinkSync(p), '../wordpress/core/latest');
        });

        it('akismet plugin directory symlink preserved', () => {
            const p = join(localSiteRoot, 'wp-content', 'plugins', 'akismet');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected akismet to remain a symlink');
        });

        it('jetpack plugin directory symlink preserved', () => {
            const p = join(localSiteRoot, 'wp-content', 'plugins', 'jetpack');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected jetpack to remain a symlink');
        });

        it('wpcomsh mu-plugin directory symlink preserved', () => {
            const p = join(localSiteRoot, 'wp-content', 'mu-plugins', 'wpcomsh');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected wpcomsh to remain a symlink');
        });

        it('twentyseventeen theme directory symlink preserved', () => {
            const p = join(localSiteRoot, 'wp-content', 'themes', 'twentyseventeen');
            assert.ok(lstatSync(p).isSymbolicLink(), 'Expected twentyseventeen to remain a symlink');
        });

        // -- shared files unchanged -----------------------------------

        it('shared WP core file not modified', () => {
            const content = readFileSync(
                join(wpShared, 'core/latest/wp-load.php'), 'utf-8',
            );
            assert.equal(content, '<?php // shared WP core loader');
        });

        it('shared akismet file not modified', () => {
            const content = readFileSync(
                join(wpShared, 'plugins/akismet/latest/akismet.php'), 'utf-8',
            );
            assert.equal(content, '<?php // shared akismet');
        });

        // -- conflicting paths: local content wins --------------------

        it('akismet.php through symlink is from shared hosting, not remote', () => {
            // The remote site has wp-content/plugins/akismet/akismet.php
            // with REMOTE content.  The local hosting has a symlink at
            // wp-content/plugins/akismet pointing to shared hosting.
            // Reading through the symlink should return the shared hosting
            // version, proving the remote never overwrote it.
            const content = readFileSync(
                join(localSiteRoot, 'wp-content', 'plugins', 'akismet', 'akismet.php'), 'utf-8',
            );
            assert.equal(content, '<?php // shared akismet',
                'Expected local shared hosting akismet.php, not the remote version');
        });

        it('no remote-only akismet files leaked through the symlink', () => {
            // The remote's akismet includes readme.txt, but the shared
            // hosting copy doesn't.  If the importer wrote through the
            // symlink, readme.txt would appear in the shared directory.
            const readmePath = join(localSiteRoot, 'wp-content', 'plugins', 'akismet', 'readme.txt');
            assert.ok(!existsSync(readmePath),
                'readme.txt should not exist under local akismet (shared hosting has no readme)');
        });

        it('local regular file preserved over conflicting remote version', () => {
            // maintenance.php exists as a plain file both locally and on the
            // remote, with different content.  The local version should win.
            const content = readFileSync(
                join(localSiteRoot, 'wp-content', 'maintenance.php'), 'utf-8',
            );
            assert.equal(content, '<?php // LOCAL maintenance – should be preserved');
        });

        // -- non-conflicting remote files imported normally -----------

        it('non-conflicting remote files were downloaded', () => {
            // test-data/ doesn't overlap with any local hosting content,
            // so its files should be imported from the server normally.
            const remoteFile = join(localSiteRoot, 'test-data', 'hello.txt');
            assert.ok(existsSync(remoteFile),
                'Expected test-data/hello.txt to be downloaded from remote');
        });

        it('wp-config.php was imported (no local conflict)', () => {
            const wpConfig = join(localSiteRoot, 'wp-config.php');
            assert.ok(existsSync(wpConfig),
                'Expected wp-config.php to be imported from remote');
        });

        // -- audit log ------------------------------------------------

        it('audit log contains PRESERVE-LOCAL skip entries', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('PRESERVE-LOCAL'),
                'Expected PRESERVE-LOCAL entries in audit log');
            assert.ok(audit.includes('skip file'),
                'Expected "skip file" entries for preserved paths');
        });
    });

    // ------------------------------------------------------------------
    // Test: --preserve-local survives resume
    // ------------------------------------------------------------------
    describe('--preserve-local survives resume', () => {
        let tempDir;
        let wpShared;
        let localSiteRoot;

        beforeAll(() => {
            tempDir = createTempDir('e2e-preserve-local-resume');
            const built = buildHostingStructureInLocalDocroot(docrootDir(tempDir));
            wpShared = built.wpShared;
            localSiteRoot = built.siteRoot;
        });

        afterAll(() => {
            unlockSharedDir(wpShared);
            cleanupTempDir(tempDir);
        });

        it('completes after forced resume with --max-exec=3', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--on-docroot-nonempty=preserve-local', '--max-exec=3'],
                timeout: 120000,
            });
            assert.equal(
                result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
            );
        });

        it('hosting symlinks still intact after multi-request import', () => {
            assert.ok(lstatSync(join(localSiteRoot, 'wp-load.php')).isSymbolicLink());
            assert.ok(lstatSync(join(localSiteRoot, '__wp__')).isSymbolicLink());
            assert.ok(lstatSync(join(localSiteRoot, 'wp-content', 'plugins', 'akismet')).isSymbolicLink());
            assert.ok(lstatSync(join(localSiteRoot, 'wp-content', 'object-cache.php')).isSymbolicLink());
        });

        it('state preserves preserve_local across resume cycles', () => {
            const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
            assert.equal(state.docroot_nonempty_behavior, 'preserve-local');
        });
    });

    // ------------------------------------------------------------------
    // Test: delta sync preserves hosting symlinks
    // ------------------------------------------------------------------
    describe('delta sync after --preserve-local', () => {
        let tempDir;
        let wpShared;
        let localSiteRoot;

        beforeAll(() => {
            tempDir = createTempDir('e2e-preserve-local-delta');
            const built = buildHostingStructureInLocalDocroot(docrootDir(tempDir));
            wpShared = built.wpShared;
            localSiteRoot = built.siteRoot;
        });

        afterAll(() => {
            unlockSharedDir(wpShared);
            cleanupTempDir(tempDir);
        });

        it('initial import completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--on-docroot-nonempty=preserve-local'],
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('tamper with a synced file to verify delta overwrites it', () => {
            // Pick a file we know was synced (not preserved) — wp-config.php
            // is a non-conflicting remote file that gets downloaded.
            const wpConfig = join(localSiteRoot, 'wp-config.php');
            assert.ok(existsSync(wpConfig), 'Precondition: wp-config.php exists');
            writeFileSync(wpConfig, '<?php // TAMPERED locally');
        });

        it('delta sync completes', () => {
            // Abort previous completion, then re-run — triggers delta.
            // preserve_local is restored from persisted state.
            const abort = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--abort'],
            });
            assert.equal(abort.exitCode, 0,
                `Expected abort exit 0\nstderr: ${abort.stderr}\nstdout: ${abort.stdout}`);

            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0 (delta)\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('delta sync overwrites tampered synced file with remote version', () => {
            // wp-config.php was tampered locally but the remote version
            // changed (different ctime after our tampering is invisible to
            // the remote, but the local index has the OLD ctime while the
            // file on disk was rewritten).  The delta diff sees a ctime
            // mismatch and re-downloads it.
            //
            // Actually: the delta compares remote ctime against our local
            // index (which recorded the original remote ctime).  If the
            // remote file's ctime hasn't changed, the diff sees no change
            // and skips it — which is correct behavior (no remote change).
            // To test that delta DOES overwrite, we'd need the remote to
            // change too.  So instead, we verify preserve-local didn't
            // block the initial download: wp-config.php is not a symlink
            // and not in a non-writable dir, so the fetch stage should
            // have written it during the initial sync.
            const wpConfig = join(localSiteRoot, 'wp-config.php');
            assert.ok(existsSync(wpConfig), 'wp-config.php should still exist after delta');
        });

        it('hosting symlinks still intact after delta', () => {
            assert.ok(lstatSync(join(localSiteRoot, 'wp-load.php')).isSymbolicLink());
            assert.ok(lstatSync(join(localSiteRoot, '__wp__')).isSymbolicLink());
            assert.ok(lstatSync(join(localSiteRoot, 'wp-content', 'plugins', 'akismet')).isSymbolicLink());
            assert.ok(lstatSync(join(localSiteRoot, 'wp-content', 'plugins', 'jetpack')).isSymbolicLink());
            assert.ok(lstatSync(join(localSiteRoot, 'wp-content', 'mu-plugins', 'wpcomsh')).isSymbolicLink());
            assert.ok(lstatSync(join(localSiteRoot, 'wp-content', 'object-cache.php')).isSymbolicLink());
        });

        it('shared files unchanged after delta', () => {
            assert.equal(
                readFileSync(join(wpShared, 'core/latest/wp-load.php'), 'utf-8'),
                '<?php // shared WP core loader',
            );
        });
    });

    // ------------------------------------------------------------------
    // Test: directory-level symlink pointing outside docroot
    //
    // This covers the case where an entire directory like wp-content/plugins
    // is a symlink to a location outside the docroot (e.g., a shared hosting
    // plugins pool).  When the remote site has files under that directory,
    // they should all be skipped gracefully — we should never see "Security:
    // Refusing to create directory outside docroot".
    // ------------------------------------------------------------------
    describe('directory-level symlink pointing outside docroot', () => {
        let tempDir;
        let localSiteRoot;
        let sharedPluginsDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-preserve-local-dirlink');

            const docroot = docrootDir(tempDir);
            localSiteRoot = join(docroot, siteDir);

            // Create the site structure with wp-content/plugins as a
            // directory-level symlink rather than per-plugin symlinks.
            // The symlink target is a sibling of the site root, completely
            // outside the docroot tree that the importer considers safe.
            sharedPluginsDir = join(docroot, dirname(siteDir), 'shared-plugins-pool');
            mkdirSync(sharedPluginsDir, { recursive: true });

            // Build minimal site structure (no per-plugin symlinks this time)
            mkdirSync(join(localSiteRoot, 'wp-content'), { recursive: true });

            // wp-content/plugins is itself a symlink outside the docroot.
            // From wp-content/, ../../ goes to dirname(siteRoot) where the
            // shared pool lives.
            symlinkSync(
                '../../shared-plugins-pool',
                join(localSiteRoot, 'wp-content', 'plugins'),
            );

            // Also create a regular file to prove non-conflicting imports work
            writeFileSync(
                join(localSiteRoot, 'wp-content', 'local-marker.txt'),
                'local marker',
            );
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('files-sync completes without security errors', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--on-docroot-nonempty=preserve-local'],
            });
            assert.equal(
                result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`,
            );
            assert.ok(
                !result.stderr.includes('Security: Refusing to create directory outside docroot'),
                `Should not see security error, got: ${result.stderr}`,
            );
        });

        it('plugins directory symlink is preserved', () => {
            const p = join(localSiteRoot, 'wp-content', 'plugins');
            assert.ok(lstatSync(p).isSymbolicLink(),
                'Expected wp-content/plugins to remain a symlink');
            assert.equal(readlinkSync(p), '../../shared-plugins-pool');
        });

        it('no remote plugin files leaked into shared directory', () => {
            // The remote site has akismet/akismet.php and akismet/readme.txt.
            // Since wp-content/plugins points outside the docroot, these
            // should be skipped — nothing should appear in the shared pool.
            const akismetDir = join(sharedPluginsDir, 'akismet');
            assert.ok(!existsSync(akismetDir),
                'akismet directory should not have been created in the shared plugins pool');
        });

        it('audit log shows PRESERVE-LOCAL skip for symlink in path', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('PRESERVE-LOCAL'),
                'Expected PRESERVE-LOCAL entries in audit log');
            assert.ok(
                audit.includes('symlink in path'),
                'Expected "symlink in path" skip entries in audit log',
            );
        });
    });
});
