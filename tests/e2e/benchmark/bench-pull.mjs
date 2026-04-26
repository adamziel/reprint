/**
 * Per-stage performance benchmark for the `pull` pipeline.
 *
 * Provisions the `large-directory` e2e site (2k+ files) and seeds its DB
 * with hundreds of thousands of posts/postmeta rows so the DB stages
 * dominate wall-clock — see Automattic/studio#3248 for the dataset shape.
 * Then runs each pipeline stage as an individual CLI invocation and times
 * the wall-clock.
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
// Seed enough posts/postmeta to make db-pull and db-apply dominate wall-clock
// (the default WP install has ~1 post). Mirrors the dataset shape used in
// Automattic/studio#3248: ~320k posts and ~720k postmeta rows.
const SEED_POSTS = Number(process.env.BENCH_SEED_POSTS || 320_007);
const SEED_POSTMETA = Number(process.env.BENCH_SEED_POSTMETA || 720_015);
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'importer', 'import.php');
const REGISTRY = JSON.parse(readFileSync(join(import.meta.dirname, '..', 'site-registry.json'), 'utf-8'));

async function seedSourceDb() {
    const dbName = `e2e_${SITE.replace(/-/g, '_')}`;
    const conn = await createConnection({
        host: REGISTRY.dbHost,
        user: REGISTRY.dbUser,
        password: REGISTRY.dbPass,
        database: dbName,
        multipleStatements: true,
    });
    try {
        const [rows] = await conn.query('SELECT COUNT(*) AS c FROM wp_posts');
        if (rows[0].c >= SEED_POSTS) {
            console.log(`Source DB already seeded with ${rows[0].c} posts; skipping seed`);
            return;
        }

        console.log(`Seeding source DB: ${SEED_POSTS} posts, ${SEED_POSTMETA} postmeta...`);
        // Speed up bulk inserts: skip per-row durability, defer index updates.
        // Drop STRICT mode so TEXT columns without defaults (to_ping,
        // pinged, post_content_filtered, etc.) accept implicit ''.
        await conn.query("SET SESSION sql_mode='';");
        await conn.query('SET autocommit=0; SET unique_checks=0; SET foreign_key_checks=0;');
        await conn.query('ALTER TABLE wp_posts DISABLE KEYS; ALTER TABLE wp_postmeta DISABLE KEYS;');

        const BATCH = 2000;
        const now = '2024-01-01 00:00:00';
        for (let start = 1; start <= SEED_POSTS; start += BATCH) {
            const end = Math.min(start + BATCH - 1, SEED_POSTS);
            const values = [];
            const params = [];
            for (let i = start; i <= end; i++) {
                values.push('(?,?,?,?,?,?,?,?,?,?,?,?)');
                params.push(
                    1, now, now,
                    `Bench post body ${i} — lorem ipsum dolor sit amet, consectetur adipiscing elit. http://localhost:9999/post/${i}`,
                    `Bench post ${i}`,
                    '', 'publish', 'open', 'open', `bench-post-${i}`,
                    'post', 0,
                );
            }
            await conn.query(
                `INSERT INTO wp_posts
                    (post_author, post_date, post_date_gmt, post_content, post_title,
                     post_excerpt, post_status, comment_status, ping_status, post_name,
                     post_type, comment_count)
                 VALUES ${values.join(',')}`,
                params,
            );
        }

        for (let start = 1; start <= SEED_POSTMETA; start += BATCH) {
            const end = Math.min(start + BATCH - 1, SEED_POSTMETA);
            const values = [];
            const params = [];
            for (let i = start; i <= end; i++) {
                const postId = ((i - 1) % SEED_POSTS) + 1;
                values.push('(?,?,?)');
                params.push(postId, `bench_meta_${i % 3}`, `value-${i}`);
            }
            await conn.query(
                `INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ${values.join(',')}`,
                params,
            );
        }

        await conn.query('COMMIT');
        await conn.query('ALTER TABLE wp_posts ENABLE KEYS; ALTER TABLE wp_postmeta ENABLE KEYS;');
        await conn.query('SET autocommit=1; SET unique_checks=1; SET foreign_key_checks=1;');
        console.log('Seed complete');
    } finally {
        await conn.end();
    }
}

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
                timeout: 900_000,
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
    lines.push(`Site: \`${SITE}\` · ${meta.fileCount} files · ${meta.seedPosts.toLocaleString('en-US')} posts · ${meta.seedPostmeta.toLocaleString('en-US')} postmeta · PHP \`${meta.phpVersion}\``);
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

    await seedSourceDb();
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
    const meta = {
        site: SITE,
        fileCount: '2,000+',
        seedPosts: SEED_POSTS,
        seedPostmeta: SEED_POSTMETA,
        phpVersion,
        importer: IMPORTER_PATH,
    };
    const md = renderMarkdown(results, meta);
    console.log('\n' + md);

    const label = process.env.BENCH_LABEL || 'pr';
    const jsonOut = process.env.BENCH_JSON_OUT || 'bench-results.json';
    const mdOut = process.env.BENCH_MD_OUT || 'bench-results.md';
    writeFileSync(jsonOut, JSON.stringify({ meta: { ...meta, label }, results }, null, 2));
    writeFileSync(mdOut, md);

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
