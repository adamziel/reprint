/**
 * Per-stage performance benchmark for the `pull` pipeline.
 *
 * Provisions the `large-directory` e2e site (2k+ files), then runs each
 * pipeline stage as an individual CLI invocation and times the wall-clock.
 * Emits a markdown table to stdout, appends to $GITHUB_STEP_SUMMARY, and
 * writes a JSON artifact to bench-results.json.
 *
 * Stages timed: preflight, files-pull, db-pull, db-apply, apply-runtime.
 *
 * Note: each stage runs in a fresh PHP process to keep timings comparable
 * with how the importer is run in production (resumable, single-stage).
 * That means each measurement includes one PHP startup cost, which is
 * roughly constant across stages and across PRs — so deltas remain
 * meaningful even though absolute numbers carry that overhead.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, readFileSync, appendFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { performance } from 'node:perf_hooks';
import { createConnection } from 'mysql2/promise';
import { ensureSite } from '../lib/site-setup.js';
import {
    getSiteUrl, getSiteSecret, getSiteDir, fsRootDir,
} from '../lib/test-helpers.js';

const SITE = 'large-directory';
const IMPORT_DB = 'e2e_bench_pull';
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'importer', 'import.php');
const REGISTRY = JSON.parse(readFileSync(join(import.meta.dirname, '..', 'site-registry.json'), 'utf-8'));

async function provisionDatabase() {
    const conn = await createConnection({
        host: REGISTRY.dbHost,
        user: REGISTRY.dbUser,
        password: REGISTRY.dbPass,
        multipleStatements: true,
    });
    await conn.query(`DROP DATABASE IF EXISTS \`${IMPORT_DB}\``);
    await conn.query(`CREATE DATABASE \`${IMPORT_DB}\``);
    await conn.end();
}

function runStage(stage, stateDir, extraArgs = [], { includeUrl = true } = {}) {
    const url = `${getSiteUrl(SITE)}&directory=${getSiteDir(SITE)}`;
    const args = [
        IMPORTER_PATH,
        stage,
        ...(includeUrl ? [url] : []),
        `--state-dir=${stateDir}`,
        `--fs-root=${fsRootDir(stateDir)}`,
        `--secret=${getSiteSecret(SITE)}`,
        ...extraArgs,
    ];

    const start = performance.now();
    let attempts = 0;
    let lastErr = null;

    // Stages other than preflight may exit 2 to request resumption. Loop
    // until the command runs to completion (exit 0) or fatally fails.
    while (true) {
        attempts += 1;
        try {
            execFileSync(PHP_BINARY, args, {
                timeout: 240000,
                encoding: 'utf-8',
                maxBuffer: 64 * 1024 * 1024,
                stdio: ['ignore', 'pipe', 'pipe'],
            });
            const elapsedMs = performance.now() - start;
            return { stage, elapsedMs, attempts, ok: true };
        } catch (e) {
            const exitCode = e.status === null ? -1 : (e.status || 1);
            lastErr = e;
            if (exitCode === 2 && attempts < 50) {
                continue;
            }
            const elapsedMs = performance.now() - start;
            return {
                stage, elapsedMs, attempts, ok: false, exitCode,
                stderr: (e.stderr || '').toString().slice(-2000),
                stdout: (e.stdout || '').toString().slice(-2000),
            };
        }
    }
}

function fmtMs(ms) {
    if (ms < 1000) return `${ms.toFixed(0)} ms`;
    return `${(ms / 1000).toFixed(2)} s`;
}

function renderMarkdown(results, meta) {
    const total = results.reduce((s, r) => s + r.elapsedMs, 0);
    const lines = [];
    lines.push(`## Pull pipeline performance — \`${SITE}\``);
    lines.push('');
    lines.push(`Site: \`${SITE}\` · ${meta.fileCount} files · PHP \`${meta.phpVersion}\``);
    lines.push('');
    lines.push('| Stage | Wall time | Resume attempts | Status |');
    lines.push('|---|---:|---:|---|');
    for (const r of results) {
        lines.push(`| \`${r.stage}\` | ${fmtMs(r.elapsedMs)} | ${r.attempts} | ${r.ok ? '✓' : '✗ exit ' + r.exitCode} |`);
    }
    lines.push(`| **Total** | **${fmtMs(total)}** | | |`);
    lines.push('');
    return lines.join('\n');
}

async function main() {
    console.log(`Provisioning site: ${SITE}`);
    await ensureSite(SITE, {
        files: 'none',
        afterCreate: async (siteDir) => {
            const manyDir = join(siteDir, 'test-data', 'many-files');
            mkdirSync(manyDir, { recursive: true });
            for (let i = 1; i <= 2000; i++) {
                const num = String(i).padStart(4, '0');
                writeFileSync(join(manyDir, `file-${num}.txt`), `content-${num}`);
            }
        },
    });

    await provisionDatabase();

    const stateDir = join(tmpdir(), `bench-pull-${Date.now()}`);
    mkdirSync(stateDir, { recursive: true });
    mkdirSync(fsRootDir(stateDir), { recursive: true });

    const dbApplyArgs = [
        `--target-user=${REGISTRY.dbUser}`,
        `--target-pass=${REGISTRY.dbPass}`,
        `--target-db=${IMPORT_DB}`,
        `--new-site-url=http://localhost:9999`,
    ];
    const runtimeOutDir = join(stateDir, 'runtime-out');
    mkdirSync(runtimeOutDir, { recursive: true });
    const runtimeArgs = [
        '--runtime=php-builtin',
        `--output-dir=${runtimeOutDir}`,
    ];

    const stages = [
        { name: 'preflight', extra: [] },
        { name: 'files-pull', extra: [] },
        { name: 'db-pull', extra: [] },
        { name: 'db-apply', extra: dbApplyArgs },
        // apply-runtime is local-only; it doesn't take a remote URL.
        { name: 'apply-runtime', extra: runtimeArgs, includeUrl: false },
    ];

    const results = [];
    for (const { name, extra, includeUrl } of stages) {
        console.log(`-> ${name}`);
        const r = runStage(name, stateDir, extra, { includeUrl });
        results.push(r);
        console.log(`   ${r.ok ? 'ok' : 'FAIL'} in ${fmtMs(r.elapsedMs)} (attempts=${r.attempts})`);
        if (!r.ok) {
            console.error(`   stderr (tail):\n${r.stderr}`);
            console.error(`   stdout (tail):\n${r.stdout}`);
        }
    }

    const phpVersion = execFileSync(PHP_BINARY, ['-r', 'echo PHP_VERSION;'], { encoding: 'utf-8' }).trim();
    const meta = { site: SITE, fileCount: '2,000+', phpVersion, importer: IMPORTER_PATH };
    const md = renderMarkdown(results, meta);
    console.log('\n' + md);

    writeFileSync('bench-results.json', JSON.stringify({ meta, results }, null, 2));
    writeFileSync('bench-results.md', md);

    if (process.env.GITHUB_STEP_SUMMARY) {
        appendFileSync(process.env.GITHUB_STEP_SUMMARY, md + '\n');
    }

    if (results.some((r) => !r.ok)) {
        process.exit(1);
    }
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
