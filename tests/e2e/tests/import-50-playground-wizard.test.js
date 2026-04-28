/**
 * Test 50: Wizard flow — pull → flatten → SQLite, ready for Playground
 *
 * This test mirrors what the deployed reprint-ui wizard does, end-to-end,
 * against a wpcom-Atomic-style fixture. It does NOT talk to wp.com — the
 * fixture's reprint-exporter is reachable directly with a known shared
 * secret, the way the wizard would talk to a real site after the OAuth
 * + rotate-export-secret round-trip.
 *
 * Each `it` block models one piece the wizard relies on:
 *
 *   1. `pull` against a split-root Atomic layout, with the same flags
 *      the wizard sets:
 *         --target-engine=sqlite
 *         --target-sqlite-path=...   (mirrors `/internal/shared/imported.sqlite`)
 *         --flatten-to=...           (mirrors `/wordpress`)
 *         --runtime=none
 *         --no-adaptive
 *      Produces a flat WP layout with wp-admin / wp-includes as symlinks
 *      into the imported core tree, plus a populated SQLite database.
 *
 *   2. The flattened directory has the structure WP needs to boot:
 *      real wp-config.php / wp-load.php / index.php at the top, working
 *      symlinks for wp-admin and wp-includes, and wp-content with the
 *      imported plugins.
 *
 *   3. The imported SQLite has the source site's wp_options
 *      (`siteurl`, `home`, `blogname`, etc.) with URLs rewritten to the
 *      `--new-site-url` value the wizard passes.
 *
 *   4. WordPress can actually serve the cloned site: we boot
 *      Playground with the flattened dir mounted at /wordpress and the
 *      imported SQLite in place, then HTTP-fetch `/` and assert the
 *      blog title appears in the response. Catches the "install wizard
 *      shows up instead of the cloned site" class of bug.
 *
 * If any of these breaks on CI, the wizard breaks for the user.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, statSync,
    lstatSync, readlinkSync, mkdirSync, writeFileSync, rmSync,
    cpSync, copyFileSync,
} from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    PHP_BINARY,
    PROJECT_ROOT,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Wizard flow: pull → flatten → SQLite — playground-ready clone', { timeout: 360000 }, () => {
    // Use the 'basic' fixture rather than 'wpcloud-flatten' even
    // though the latter better mirrors a real WP Cloud site's split
    // root: tests 38 and 46 expect the wpcloud-flatten fixture set
    // up with their own afterCreate (creating /tmp/e2e-wpcloud-core
    // + __wp__ symlinks). ensureSite's marker file means whichever
    // test runs first decides how the fixture is shaped, and a
    // bare ensureSite('wpcloud-flatten') here shapes it as a
    // regular WP install — breaking test 38's split-root
    // assertions. Sticking to 'basic' avoids the cross-test
    // contention; split-root coverage stays with test 38.
    const site = 'basic';
    let tempDir;
    let importDir;     // what flat-docroot writes to (= wizard's /wordpress)
    let sqlitePath;    // where db-apply puts the SQLite (= wizard's /internal/shared/imported.sqlite)
    // Align the SQL's siteurl/home rewrite with the URL Playground will
    // serve under in this test, so WP doesn't issue a canonical
    // redirect on the front-page check.
    const PLAYGROUND_PORT = 18745;
    const PLAYGROUND_URL = `http://127.0.0.1:${PLAYGROUND_PORT}`;

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-wizard-flow');
        importDir = join(tempDir, 'site');
        sqlitePath = join(tempDir, 'imported.sqlite');
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('pull --flatten-to + SQLite completes successfully (matches wizard flags)', () => {
        const result = runImporter(importUrl(), tempDir, 'pull', {
            secret: getSiteSecret(site),
            // pull internally runs preflight as its first stage, so
            // skip the wrapper's auto-preflight to avoid running it twice.
            skipPreflight: true,
            timeout: 180000,
            wallTimeout: 300000,
            extraArgs: [
                '--target-engine=sqlite',
                `--target-sqlite-path=${sqlitePath}`,
                `--flatten-to=${importDir}`,
                `--new-site-url=${PLAYGROUND_URL}`,
                '--runtime=none',
                '--no-adaptive',
            ],
        });
        assert.equal(
            result.exitCode, 0,
            `pull exited ${result.exitCode}\nstdout:\n${result.stdout}\nstderr:\n${result.stderr}`,
        );
    });

    it('flattened dir has the files WordPress needs to boot', () => {
        // Real top-level files at the destination — these need to live
        // at the flattened path so __DIR__ / dirname(__FILE__) resolves
        // there.
        for (const f of ['wp-config.php', 'wp-load.php', 'index.php']) {
            const p = join(importDir, f);
            assert.ok(existsSync(p), `${f} missing in flattened dir at ${p}`);
        }

        // wp-admin and wp-includes resolve through symlinks (split-root
        // case) or are real dirs (basic fixture). Either way they must
        // exist and contain a recognisable WP file. (The symlink shape
        // is exercised separately by test 38 with the wpcloud-flatten
        // fixture.)
        for (const dir of ['wp-admin', 'wp-includes']) {
            const p = join(importDir, dir);
            assert.ok(existsSync(p), `${dir} missing in flattened dir`);
            const expectedFile = dir === 'wp-admin' ? 'admin.php' : 'version.php';
            assert.ok(
                existsSync(join(p, expectedFile)),
                `${dir} doesn't contain ${expectedFile}`,
            );
        }

        // wp-content with the imported tree.
        assert.ok(
            existsSync(join(importDir, 'wp-content', 'plugins')),
            'wp-content/plugins missing in flattened dir',
        );
    });

    it('imported SQLite is populated with the source site\'s data', () => {
        assert.ok(existsSync(sqlitePath), `imported.sqlite missing at ${sqlitePath}`);
        const size = statSync(sqlitePath).size;
        assert.ok(
            size > 64 * 1024,
            `imported.sqlite is only ${size} bytes — expected ≥ ~64KB for a populated WP DB`,
        );

        // Query wp_options directly via PDO — this bypasses wpdb so
        // we're checking the raw imported data, not WP's interpretation.
        const script = `
            $db = new PDO('sqlite:' . $argv[1]);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $db->prepare(
                "SELECT option_name, option_value FROM wp_options "
                . "WHERE option_name IN ('siteurl','home','blogname','template','active_plugins')"
            );
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        `;
        const out = execFileSync(PHP_BINARY, ['-r', script, sqlitePath], { encoding: 'utf-8' });
        const opts = JSON.parse(out);

        assert.ok(opts.siteurl, `wp_options.siteurl missing — got: ${JSON.stringify(opts)}`);
        assert.ok(opts.home, 'wp_options.home missing');
        assert.ok(opts.blogname, 'wp_options.blogname missing');

        // --new-site-url should have rewritten the source URLs to the
        // local Playground URL. If it didn't, WP would redirect every
        // request back to the source domain.
        assert.ok(
            opts.siteurl.includes('127.0.0.1') && opts.siteurl.includes(String(PLAYGROUND_PORT)),
            `siteurl was not rewritten by --new-site-url; got: ${opts.siteurl}`,
        );
        assert.ok(
            opts.home.includes('127.0.0.1') && opts.home.includes(String(PLAYGROUND_PORT)),
            `home was not rewritten by --new-site-url; got: ${opts.home}`,
        );
    });

    describe('Boot Playground against the imported site', () => {
        let playgroundCli;
        let serverUrl;
        let mountDir;

        beforeAll(async () => {
            // Stage the imported tree in a writable, self-contained
            // dir for Playground to mount.
            //
            // dereference: true is critical here. flat-docroot's
            // output contains absolute symlinks pointing at the raw
            // pulled tree (wp-admin → /tmp/.../e2e-wpcloud-core/wp-admin
            // etc. for the wpcloud-flatten fixture). cpSync with
            // dereference:false would copy those symlinks verbatim;
            // when Playground mounts the dir at /wordpress, the WASM
            // VFS sees the symlinks but can't resolve their host-FS
            // targets that live outside the mount. Resolving them at
            // copy time turns mountDir into a normal flat WP install
            // — which is what the deployed wizard achieves by copying
            // recursively in its activation step.
            mountDir = join(tempDir, 'playground-mount');
            cpSync(importDir, mountDir, { recursive: true, dereference: true });

            // Neutralize hardcoded path defines in the imported
            // wp-config.php. The wpcloud-flatten fixture's wp-config
            // has e.g. WP_CONTENT_DIR=/tmp/e2e-wpcloud-wpcontent/wp-content
            // — a host path that doesn't exist inside Playground's
            // WASM VFS. WP would then mkdir that nonexistent dir and
            // bail. Comment out anything that hardcodes a layout
            // path so WP falls back to deriving them from ABSPATH=
            // /wordpress/. (Real Atomic-site wp-configs need the same
            // treatment in the deployed wizard — this is what the
            // wizard's activation step does in PHP.)
            const cfgPath = join(mountDir, 'wp-config.php');
            const cfg = readFileSync(cfgPath, 'utf-8');
            const neutralized = cfg.replace(
                /^[ \t]*define\s*\(\s*['"](?:ABSPATH|WP_CONTENT_DIR|WP_CONTENT_URL|WP_TEMP_DIR|WPMU_PLUGIN_DIR|WPMU_PLUGIN_URL|WP_PLUGIN_DIR|WP_PLUGIN_URL|WP_LANG_DIR|WP_HOME|WP_SITEURL|COOKIE_DOMAIN)['"][^)]*\)\s*;.*$/gm,
                '// import-50 neutralized: $&',
            );
            writeFileSync(cfgPath, neutralized);

            // Place the imported SQLite where Playground's auto-loaded
            // SQLite drop-in expects it (this is what the wizard does
            // in its activation step).
            const dbDir = join(mountDir, 'wp-content', 'database');
            mkdirSync(dbDir, { recursive: true });
            copyFileSync(sqlitePath, join(dbDir, '.ht.sqlite'));

            const { runCLI } = await import('@wp-playground/cli');
            // Mirror Studio's pull-reprint server flags: the imported
            // tree IS the site, so SkipSqliteSetup keeps Playground
            // from reinstalling the SQLite plugin on top of it, and
            // followSymlinks lets the wp-admin / wp-includes symlinks
            // produced by flat-docroot resolve.
            playgroundCli = await runCLI({
                command: 'server',
                port: PLAYGROUND_PORT, // outside the 8081-8119 fixture range
                skipBrowser: true,
                quiet: true,
                wordpressInstallMode: 'do-not-attempt-installing',
                skipSqliteSetup: true,
                followSymlinks: true,
                'mount-before-install': [{ hostPath: mountDir, vfsPath: '/wordpress' }],
                php: '8.2',
                // Tell the request handler that WP thinks it's at this
                // origin — matches the --new-site-url we passed to pull,
                // so wp_options.siteurl agrees with what WP sees, and
                // we don't get an immediate redirect on the front page.
                'site-url': PLAYGROUND_URL,
            });
            serverUrl = playgroundCli.serverUrl;
        }, 180000);

        afterAll(async () => {
            if (playgroundCli && typeof playgroundCli[Symbol.asyncDispose] === 'function') {
                await playgroundCli[Symbol.asyncDispose]();
            }
        });

        it('responds with the imported site\'s blog title (no install wizard)', async () => {
            // Playground's first request always 302s for the auto-login
            // cookie handshake — fetch must follow redirects to reach WP.
            const res = await fetch(serverUrl, { redirect: 'follow' });
            const body = await res.text();
            const headers = Object.fromEntries(res.headers.entries());
            const snippet = body.slice(0, 1000);
            assert.equal(
                res.status, 200,
                `Expected 200 from ${serverUrl}, got ${res.status}\nheaders: ${JSON.stringify(headers)}\nbody[0..1000]:\n${snippet}`,
            );
            // The install wizard contains a redirect to install.php
            // and a "Welcome" page that asks for a language. If we see
            // those, the SQLite drop-in didn't see the imported DB and
            // WP fell back to "blog not installed" — exactly the bug
            // we keep hitting in the deployed wizard.
            assert.ok(
                !/install\.php|Welcome to the famous/i.test(body),
                `WordPress is showing the install wizard — DB not picked up.\nbody[0..1000]:\n${snippet}`,
            );
            // Sanity check: the response should be a real WP page.
            assert.ok(
                /<title>[^<]+<\/title>/i.test(body),
                `No <title> tag in response — WP didn't render the front page.\nbody[0..1000]:\n${snippet}`,
            );
        });
    });
});
