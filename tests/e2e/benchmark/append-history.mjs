/**
 * Append a single trunk benchmark run to history.json.
 *
 * Inputs (env):
 *   BENCH_JSON       path to bench-trunk.json (default: bench-trunk.json)
 *   HISTORY_JSON     path to history.json to update in-place (default: history.json)
 *   COMMIT_SHA       full commit sha
 *   COMMIT_DATE      ISO date for the commit
 *   COMMIT_MESSAGE   first line of the commit message
 *   COMMIT_AUTHOR    author name
 *   MAX_ENTRIES      cap on entries kept (default: 500)
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const benchPath = process.env.BENCH_JSON || 'bench-trunk.json';
const historyPath = process.env.HISTORY_JSON || 'history.json';
const max = Number(process.env.MAX_ENTRIES || 500);

if (!existsSync(benchPath)) {
    console.error(`Missing bench JSON: ${benchPath}`);
    process.exit(1);
}

const bench = JSON.parse(readFileSync(benchPath, 'utf-8'));
const history = existsSync(historyPath)
    ? JSON.parse(readFileSync(historyPath, 'utf-8'))
    : { schema: 1, entries: [] };

const sha = process.env.COMMIT_SHA || '';
const entry = {
    sha,
    shortSha: sha.slice(0, 7),
    date: process.env.COMMIT_DATE || new Date().toISOString(),
    message: process.env.COMMIT_MESSAGE || '',
    author: process.env.COMMIT_AUTHOR || '',
    meta: bench.meta,
    results: bench.results.map((r) => ({
        stage: r.stage,
        elapsedMs: r.elapsedMs,
        attempts: r.attempts,
        ok: r.ok,
    })),
};

// Replace if same sha already present, else append.
const idx = history.entries.findIndex((e) => e.sha === entry.sha);
if (idx >= 0) history.entries[idx] = entry;
else history.entries.push(entry);

// Keep newest MAX_ENTRIES.
if (history.entries.length > max) {
    history.entries = history.entries.slice(-max);
}

writeFileSync(historyPath, JSON.stringify(history, null, 2) + '\n');
console.log(`Wrote ${history.entries.length} entries to ${historyPath}`);
