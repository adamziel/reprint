/**
 * Combine PR and trunk bench results into a single markdown table with
 * per-stage deltas. Reads bench-pr.json and bench-trunk.json, writes
 * bench-results.md.
 *
 * When bench-pr-native.json exists (native Rust extension run), appends a
 * second table showing the native-ext speedup relative to plain PHP.
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

function fmtMs(ms) {
    if (ms < 1000) return `${ms.toFixed(0)} ms`;
    return `${(ms / 1000).toFixed(2)} s`;
}

function fmtDelta(prMs, trunkMs) {
    const diff = prMs - trunkMs;
    const pct = trunkMs > 0 ? (diff / trunkMs) * 100 : 0;
    const sign = diff >= 0 ? '+' : '';
    const abs = `${sign}${fmtMs(Math.abs(diff)).replace(/^/, diff < 0 ? '-' : '')}`;
    // Simple visual cue: 🟢 ≥10% faster, 🔴 ≥10% slower, ⚪ otherwise.
    let marker = '⚪';
    if (Math.abs(pct) >= 10 && Math.abs(diff) >= 50) {
        marker = diff < 0 ? '🟢' : '🔴';
    }
    return `${marker} ${abs} (${sign}${pct.toFixed(1)}%)`;
}

const prPath = process.env.PR_JSON || 'bench-pr.json';
const trunkPath = process.env.TRUNK_JSON || 'bench-trunk.json';
const nativePath = process.env.NATIVE_JSON || 'bench-pr-native.json';
const outPath = process.env.OUT_MD || 'bench-results.md';

if (!existsSync(prPath)) {
    console.error(`Missing PR results: ${prPath}`);
    process.exit(1);
}

const pr = JSON.parse(readFileSync(prPath, 'utf-8'));
const trunk = existsSync(trunkPath) ? JSON.parse(readFileSync(trunkPath, 'utf-8')) : null;
const native = existsSync(nativePath) ? JSON.parse(readFileSync(nativePath, 'utf-8')) : null;

const lines = [];
lines.push(`## Pull pipeline performance — \`${pr.meta.site}\``);
lines.push('');
{
    const seedSuffix = (pr.meta.seedPosts && pr.meta.seedPostmeta)
        ? ` · ${Number(pr.meta.seedPosts).toLocaleString('en-US')} posts · ${Number(pr.meta.seedPostmeta).toLocaleString('en-US')} postmeta`
        : '';
    lines.push(`Site: \`${pr.meta.site}\` · ${pr.meta.fileCount} files${seedSuffix} · PHP \`${pr.meta.phpVersion}\``);
}
lines.push('');

if (trunk) {
    lines.push('| Stage | PR | trunk | Δ | Status |');
    lines.push('|---|---:|---:|---:|---|');
    const trunkByStage = Object.fromEntries(trunk.results.map((r) => [r.stage, r]));
    let prTotal = 0;
    let trunkTotal = 0;
    for (const r of pr.results) {
        const t = trunkByStage[r.stage];
        prTotal += r.elapsedMs;
        if (t) trunkTotal += t.elapsedMs;
        const delta = t ? fmtDelta(r.elapsedMs, t.elapsedMs) : '—';
        const status = r.ok ? '✓' : '✗ exit ' + r.exitCode;
        lines.push(`| \`${r.stage}\` | ${fmtMs(r.elapsedMs)} | ${t ? fmtMs(t.elapsedMs) : '—'} | ${delta} | ${status} |`);
    }
    lines.push(`| **Total** | **${fmtMs(prTotal)}** | **${fmtMs(trunkTotal)}** | **${fmtDelta(prTotal, trunkTotal)}** | |`);
} else {
    lines.push('_Trunk baseline unavailable — showing PR numbers only._');
    lines.push('');
    lines.push('| Stage | Wall time | Resume attempts | Status |');
    lines.push('|---|---:|---:|---|');
    let total = 0;
    for (const r of pr.results) {
        total += r.elapsedMs;
        lines.push(`| \`${r.stage}\` | ${fmtMs(r.elapsedMs)} | ${r.attempts} | ${r.ok ? '✓' : '✗ exit ' + r.exitCode} |`);
    }
    lines.push(`| **Total** | **${fmtMs(total)}** | | |`);
}

// ─── Native extension comparison ─────────────────────────────────────────────
if (native) {
    lines.push('');
    lines.push('### Native Rust extension speedup (PR + ext vs plain PHP)');
    lines.push('');
    lines.push('| Stage | PHP | + native ext | Δ |');
    lines.push('|---|---:|---:|---:|');
    const phpByStage = Object.fromEntries(pr.results.map((r) => [r.stage, r]));
    let phpTotal = 0;
    let nativeTotal = 0;
    for (const r of native.results) {
        const p = phpByStage[r.stage];
        phpTotal += p?.elapsedMs ?? 0;
        nativeTotal += r.elapsedMs;
        const delta = p ? fmtDelta(r.elapsedMs, p.elapsedMs) : '—';
        lines.push(`| \`${r.stage}\` | ${p ? fmtMs(p.elapsedMs) : '—'} | ${fmtMs(r.elapsedMs)} | ${delta} |`);
    }
    lines.push(`| **Total** | **${fmtMs(phpTotal)}** | **${fmtMs(nativeTotal)}** | **${fmtDelta(nativeTotal, phpTotal)}** |`);
}

lines.push('');
lines.push('<sub>Numbers carry runner noise; treat single-run deltas as directional, not authoritative.</sub>');
lines.push('');

const md = lines.join('\n');
writeFileSync(outPath, md);
console.log(md);
