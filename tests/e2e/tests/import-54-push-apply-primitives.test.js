/**
 * Test 54: Push apply primitives
 *
 * Exercises the low-level commands Studio can compose for a safer push:
 * file planning, real-file materialization, and staged apply into a target
 * document root.
 */
import { describe, it, beforeEach, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'importer', 'import.php');
const PHP_BINARY = process.env.PHP_BINARY || 'php';

function phpImporter(args) {
    return execFileSync(PHP_BINARY, [IMPORTER_PATH, ...args], {
        encoding: 'utf-8',
        maxBuffer: 10 * 1024 * 1024,
        env: { ...process.env },
    });
}


function parseJsonOutput(output) {
    const start = output.indexOf('{');
    const end = output.lastIndexOf('}');
    if (start === -1 || end === -1 || end < start) {
        throw new Error(`No JSON object in output: ${output}`);
    }
    return JSON.parse(output.slice(start, end + 1));
}

describe('Import: Push apply primitives', () => {
    let root;
    let stateDir;
    let fsRoot;
    let stagedRoot;
    let targetRoot;

    beforeEach(() => {
        root = mkdtempSync(join(tmpdir(), 'reprint-push-primitives-'));
        stateDir = join(root, 'state');
        fsRoot = join(root, 'fs-root');
        stagedRoot = join(root, 'staged');
        targetRoot = join(root, 'target');
        mkdirSync(stateDir, { recursive: true });
        mkdirSync(join(fsRoot, 'var', 'www', 'html', 'wp-content', 'plugins', 'foo'), { recursive: true });
        mkdirSync(join(fsRoot, 'var', 'www', 'html', 'wp-content', 'uploads', '2026'), { recursive: true });
        mkdirSync(join(targetRoot, 'wp-content', 'plugins', 'foo'), { recursive: true });
        mkdirSync(join(targetRoot, 'wp-content', 'uploads', '2026'), { recursive: true });

        writeState();
        writeIndex('.import-remote-index.jsonl', [
            ['/var/www/html/index.php', 2, 11, 'file'],
            ['/var/www/html/wp-content/plugins/foo/a.php', 2, 10, 'file'],
            ['/var/www/html/wp-content/uploads/2026/image.jpg', 2, 9, 'file'],
        ]);
        writeIndex('.import-index.jsonl', [
            ['/var/www/html/wp-content/plugins/foo/a.php', 1, 8, 'file'],
        ]);

        writeFileSync(join(fsRoot, 'var', 'www', 'html', 'index.php'), '<?php // new');
        writeFileSync(join(fsRoot, 'var', 'www', 'html', 'wp-content', 'plugins', 'foo', 'a.php'), 'new-plugin');
        writeFileSync(join(fsRoot, 'var', 'www', 'html', 'wp-content', 'plugins', 'foo', 'unselected.php'), 'new-unselected');
        writeFileSync(join(fsRoot, 'var', 'www', 'html', 'wp-content', 'uploads', '2026', 'image.jpg'), 'new-image');
        writeFileSync(join(targetRoot, 'index.php'), '<?php // old');
        writeFileSync(join(targetRoot, 'wp-content', 'plugins', 'foo', 'a.php'), 'old-plugin');
        writeFileSync(join(targetRoot, 'wp-content', 'plugins', 'foo', 'live-only.php'), 'live-only');
        writeFileSync(join(targetRoot, 'wp-content', 'plugins', 'foo', 'unselected.php'), 'old-unselected');
        writeFileSync(join(targetRoot, 'wp-content', 'uploads', '2026', 'old.jpg'), 'old-image');
    });

    afterEach(() => {
        if (root && existsSync(root)) {
            rmSync(root, { recursive: true, force: true });
        }
    });

    it('plans, materializes, and applies a staged file push', () => {
        const plan = parseJsonOutput(phpImporter([
            'files-plan',
            `--state-dir=${stateDir}`,
            `--fs-root=${fsRoot}`,
            `--target-root=${targetRoot}`,
            `--selected-files=${join(root, 'selected-files.jsonl')}`,
        ]));

        assert.equal(plan.summary.total, 3);
        assert.equal(plan.files.find((file) => file.relative_path === 'index.php').policy.status, 'warning');
        assert.equal(
            plan.files.find((file) => file.relative_path === 'wp-content/plugins/foo/a.php').classification.area,
            'plugin'
        );

        const materialize = parseJsonOutput(phpImporter([
            'materialize-docroot',
            `--state-dir=${stateDir}`,
            `--fs-root=${fsRoot}`,
            `--materialize-to=${stagedRoot}`,
            `--selected-files=${join(root, 'selected-files.jsonl')}`,
        ]));
        assert.equal(materialize.status, 'complete');
        assert.ok(existsSync(join(stagedRoot, 'wp-content', 'plugins', 'foo', 'a.php')));
        assert.ok(!existsSync(join(stagedRoot, 'wp-content', 'plugins', 'foo', 'unselected.php')));

        const apply = parseJsonOutput(phpImporter([
            'apply-staged-files',
            `--state-dir=${stateDir}`,
            `--staged-root=${stagedRoot}`,
            `--target-root=${targetRoot}`,
            `--apply-journal=${join(stateDir, 'apply.json')}`,
            `--maintenance-file=${join(targetRoot, '.maintenance')}`,
            `--selected-files=${join(root, 'selected-files.jsonl')}`,
        ]));

        assert.equal(apply.status, 'complete');
        assert.equal(readFileSync(join(targetRoot, 'index.php'), 'utf-8'), '<?php // new');
        assert.equal(readFileSync(join(targetRoot, 'wp-content', 'plugins', 'foo', 'a.php'), 'utf-8'), 'new-plugin');
        assert.equal(readFileSync(join(targetRoot, 'wp-content', 'plugins', 'foo', 'live-only.php'), 'utf-8'), 'live-only');
        assert.equal(readFileSync(join(targetRoot, 'wp-content', 'plugins', 'foo', 'unselected.php'), 'utf-8'), 'old-unselected');
        assert.equal(readFileSync(join(targetRoot, 'wp-content', 'uploads', '2026', 'image.jpg'), 'utf-8'), 'new-image');
        assert.equal(readFileSync(join(targetRoot, 'wp-content', 'uploads', '2026', 'old.jpg'), 'utf-8'), 'old-image');
        assert.ok(!existsSync(join(stateDir, 'apply.json')), 'journal should be removed after successful apply');
        assert.ok(!existsSync(join(targetRoot, '.maintenance')), 'maintenance file should be removed after successful apply');
    });

    function writeState() {
        writeFileSync(join(stateDir, '.import-state.json'), JSON.stringify({
            preflight: {
                data: {
                    database: {
                        wp: {
                            paths_urls: {
                                abspath: '/var/www/html',
                                wp_admin_path: '/var/www/html/wp-admin',
                                wp_includes_path: '/var/www/html/wp-includes',
                                content_dir: '/var/www/html/wp-content',
                                plugins_dir: '/var/www/html/wp-content/plugins',
                                mu_plugins_dir: '/var/www/html/wp-content/mu-plugins',
                                uploads: {
                                    basedir: '/var/www/html/wp-content/uploads',
                                },
                            },
                        },
                    },
                },
            },
        }));
    }

    function writeIndex(name, rows) {
        const lines = rows.map(([path, ctime, size, type]) => JSON.stringify({
            path: Buffer.from(path).toString('base64'),
            ctime,
            size,
            type,
        })).join('\n') + '\n';
        writeFileSync(join(stateDir, name), lines);
    }
});
