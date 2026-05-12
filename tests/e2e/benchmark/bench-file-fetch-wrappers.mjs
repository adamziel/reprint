/**
 * HTTP-level benchmark for file_fetch compression behavior.
 *
 * This intentionally avoids the full e2e site registry. It starts the real
 * exporter endpoint behind PHP's built-in server, wraps it with several local
 * reverse-proxy modes, and verifies the multipart payload byte-for-byte.
 *
 * Compared modes:
 *   - old: no file_part_gzip request flag (mixed batches use response gzip)
 *   - new: file_part_gzip=1 (mixed batches may gzip selected file parts)
 *
 * Useful environment variables:
 *   BENCH_REPS=5
 *   BENCH_DATASETS=mixed-binary-heavy,text-heavy-many-small-mixed
 *   BENCH_WRAPPERS=php-default,proxy-pass
 *   BENCH_CHUNK_SIZE=8388608
 *   BENCH_OUT=/tmp/reprint-file-fetch-wrappers.json
 *   BENCH_KEEP_TMP=1
 */
import { spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync, statSync, readFileSync } from 'node:fs';
import { request, createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { performance } from 'node:perf_hooks';
import { gzipSync, gunzipSync } from 'node:zlib';
import net from 'node:net';

const PROJECT_ROOT = resolve(join(import.meta.dirname, '..', '..', '..'));
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const REPS = Number(process.env.BENCH_REPS || 5);
const CHUNK_SIZE = Number(process.env.BENCH_CHUNK_SIZE || 8 * 1024 * 1024);
const OUTPUT_PATH = process.env.BENCH_OUT || '';
const KEEP_TMP = process.env.BENCH_KEEP_TMP === '1';
const MIB = 1024 * 1024;
const KIB = 1024;

const PERF_WRAPPERS = [
    'php-default',
    'php-zlib-buffering',
    'proxy-pass',
    'proxy-buffer',
    'proxy-gzip-identity',
];

const BREAK_WRAPPERS = [
    'proxy-strip-http-content-encoding',
    'proxy-strip-part-encoding',
];

const DEFAULT_DATASETS = [
    'all-binary',
    'all-text-many-small',
    'balanced-mixed',
    'mixed-binary-heavy',
    'text-heavy-many-small-mixed',
];

function requestedList(envName, defaults) {
    const raw = process.env[envName] || '';
    return raw
        ? raw.split(',').map((s) => s.trim()).filter(Boolean)
        : defaults;
}

const DATASET_FILTER = requestedList('BENCH_DATASETS', DEFAULT_DATASETS);
const WRAPPER_FILTER = requestedList('BENCH_WRAPPERS', PERF_WRAPPERS);

function fmtBytes(bytes) {
    if (!Number.isFinite(bytes)) return '';
    if (bytes < KIB) return `${bytes} B`;
    if (bytes < MIB) return `${(bytes / KIB).toFixed(1)} KiB`;
    return `${(bytes / MIB).toFixed(1)} MiB`;
}

function fmtMs(ms) {
    return ms < 1000 ? `${ms.toFixed(0)} ms` : `${(ms / 1000).toFixed(2)} s`;
}

function median(values) {
    if (values.length === 0) return NaN;
    const sorted = [...values].sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);
    return sorted.length % 2 === 0
        ? (sorted[mid - 1] + sorted[mid]) / 2
        : sorted[mid];
}

function pctDelta(newValue, oldValue) {
    if (!Number.isFinite(newValue) || !Number.isFinite(oldValue) || oldValue === 0) {
        return NaN;
    }
    return ((newValue - oldValue) / oldValue) * 100;
}

function ensureDir(path) {
    mkdirSync(path, { recursive: true });
}

function repeatedBuffer(size, seed) {
    const line = Buffer.from(
        `<?php /* ${seed} */ function bench_${seed.replace(/[^a-z0-9]/gi, '_')}() { return "lorem ipsum dolor sit amet"; }\n`,
    );
    const out = Buffer.allocUnsafe(size);
    for (let offset = 0; offset < size; offset += line.length) {
        line.copy(out, offset, 0, Math.min(line.length, size - offset));
    }
    return out;
}

function writeTextFile(path, size, seed) {
    ensureDir(dirname(path));
    writeFileSync(path, repeatedBuffer(size, seed));
}

function writeRandomFile(path, size) {
    ensureDir(dirname(path));
    const chunks = [];
    let remaining = size;
    while (remaining > 0) {
        const n = Math.min(remaining, MIB);
        chunks.push(randomBytes(n));
        remaining -= n;
    }
    writeFileSync(path, Buffer.concat(chunks));
}

function sha256File(path) {
    const hash = createHash('sha256');
    const data = readFileSync(path);
    hash.update(data);
    return hash.digest('hex');
}

function fileRecord(path) {
    return {
        path,
        size: statSync(path).size,
        sha256: sha256File(path),
    };
}

function buildDatasets(root) {
    const siteDir = join(root, 'site');
    ensureDir(siteDir);
    const datasets = new Map();

    const binaryOnly = [];
    for (let i = 0; i < 4; i++) {
        const path = join(siteDir, 'all-binary', `image-${i}.jpg`);
        writeRandomFile(path, 8 * MIB);
        binaryOnly.push(fileRecord(path));
    }
    datasets.set('all-binary', {
        name: 'all-binary',
        description: '32 MiB random binary; should stay identity in both modes',
        files: binaryOnly,
    });

    const textOnly = [];
    for (let i = 0; i < 2000; i++) {
        const path = join(siteDir, 'all-text-many-small', `file-${String(i).padStart(4, '0')}.php`);
        writeTextFile(path, 2 * KIB, `all-text-${i}`);
        textOnly.push(fileRecord(path));
    }
    datasets.set('all-text-many-small', {
        name: 'all-text-many-small',
        description: '2000 small PHP files; should keep one response-gzip stream',
        files: textOnly,
    });

    const balanced = [];
    const balancedText = join(siteDir, 'balanced-mixed', 'theme.css');
    const balancedBinary = join(siteDir, 'balanced-mixed', 'hero.jpg');
    writeTextFile(balancedText, 8 * MIB, 'balanced-text');
    writeRandomFile(balancedBinary, 8 * MIB);
    balanced.push(fileRecord(balancedText), fileRecord(balancedBinary));
    datasets.set('balanced-mixed', {
        name: 'balanced-mixed',
        description: '8 MiB text + 8 MiB random binary',
        files: balanced,
    });

    const binaryHeavy = [];
    const readme = join(siteDir, 'mixed-binary-heavy', 'README.md');
    writeTextFile(readme, 8 * MIB, 'binary-heavy-readme');
    binaryHeavy.push(fileRecord(readme));
    for (let i = 0; i < 3; i++) {
        const path = join(siteDir, 'mixed-binary-heavy', `media-${i}.jpg`);
        writeRandomFile(path, 8 * MIB);
        binaryHeavy.push(fileRecord(path));
    }
    datasets.set('mixed-binary-heavy', {
        name: 'mixed-binary-heavy',
        description: '8 MiB text + 24 MiB random binary; favorable for per-part gzip',
        files: binaryHeavy,
    });

    const textHeavyMixed = [];
    for (let i = 0; i < 2000; i++) {
        const path = join(siteDir, 'text-heavy-many-small-mixed', `snippet-${String(i).padStart(4, '0')}.php`);
        writeTextFile(path, 2 * KIB, `mixed-small-${i}`);
        textHeavyMixed.push(fileRecord(path));
    }
    const tinyBinary = join(siteDir, 'text-heavy-many-small-mixed', 'tiny-logo.jpg');
    writeRandomFile(tinyBinary, 64 * KIB);
    textHeavyMixed.push(fileRecord(tinyBinary));
    datasets.set('text-heavy-many-small-mixed', {
        name: 'text-heavy-many-small-mixed',
        description: '2000 small text files + one tiny binary; unfavorable for per-part gzip',
        files: textHeavyMixed,
    });

    const largeTextPart = [];
    const largeText = join(siteDir, 'large-text-part-mixed', 'large-style.css');
    const largeBin = join(siteDir, 'large-text-part-mixed', 'small-image.jpg');
    writeTextFile(largeText, 24 * MIB, 'large-text-part');
    writeRandomFile(largeBin, MIB);
    largeTextPart.push(fileRecord(largeText), fileRecord(largeBin));
    datasets.set('large-text-part-mixed', {
        name: 'large-text-part-mixed',
        description: '24 MiB text file + 1 MiB random binary; probes memory pressure',
        files: largeTextPart,
    });

    for (const dataset of datasets.values()) {
        const listPath = join(root, `${dataset.name}.file-list.json`);
        writeFileSync(listPath, JSON.stringify(dataset.files.map((file) => file.path)));
        dataset.siteDir = siteDir;
        dataset.listPath = listPath;
        dataset.totalBytes = dataset.files.reduce((sum, file) => sum + file.size, 0);
    }

    return datasets;
}

function getFreePort() {
    return new Promise((resolvePort, reject) => {
        const server = net.createServer();
        server.listen(0, '127.0.0.1', () => {
            const address = server.address();
            const port = typeof address === 'object' && address ? address.port : 0;
            server.close(() => resolvePort(port));
        });
        server.on('error', reject);
    });
}

function waitForTcp(port, timeoutMs = 10000) {
    const start = performance.now();
    return new Promise((resolveReady, reject) => {
        const attempt = () => {
            const socket = net.createConnection({ port, host: '127.0.0.1' });
            socket.once('connect', () => {
                socket.end();
                resolveReady();
            });
            socket.once('error', (error) => {
                socket.destroy();
                if (performance.now() - start > timeoutMs) {
                    reject(error);
                } else {
                    setTimeout(attempt, 50);
                }
            });
        };
        attempt();
    });
}

async function startPhpServer(root, label, iniArgs = []) {
    const port = await getFreePort();
    const routerPath = join(root, `${label}.router.php`);
    writeFileSync(routerPath, `<?php
chdir(${JSON.stringify(PROJECT_ROOT)});
require_once ${JSON.stringify(join(PROJECT_ROOT, 'packages/reprint-exporter/src/class-http-server.php'))};
try {
    Site_Export_HTTP_Server::serve();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
`);

    const child = spawn(PHP_BINARY, [
        ...iniArgs,
        '-S',
        `127.0.0.1:${port}`,
        routerPath,
    ], {
        cwd: PROJECT_ROOT,
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    let stderr = '';
    child.stderr.on('data', (chunk) => {
        stderr += chunk.toString();
        if (stderr.length > 16000) stderr = stderr.slice(-16000);
    });

    child.stdout.resume();
    await waitForTcp(port);

    return {
        name: label,
        baseUrl: `http://127.0.0.1:${port}`,
        close: async () => {
            child.kill('SIGTERM');
            await new Promise((resolveClose) => child.once('close', resolveClose));
        },
        stderr: () => stderr,
    };
}

function copyHeaders(headers) {
    const out = { ...headers };
    delete out['connection'];
    delete out['keep-alive'];
    delete out['proxy-authenticate'];
    delete out['proxy-authorization'];
    delete out['te'];
    delete out['trailer'];
    delete out['transfer-encoding'];
    delete out['upgrade'];
    delete out['content-length'];
    return out;
}

function collectStream(stream) {
    return new Promise((resolveBody, reject) => {
        const chunks = [];
        stream.on('data', (chunk) => chunks.push(chunk));
        stream.on('end', () => resolveBody(Buffer.concat(chunks)));
        stream.on('error', reject);
    });
}

async function startProxy(targetBaseUrl, mode) {
    const target = new URL(targetBaseUrl);
    const server = createServer((clientReq, clientRes) => {
        const upstreamReq = request({
            hostname: target.hostname,
            port: target.port,
            method: clientReq.method,
            path: clientReq.url,
            headers: clientReq.headers,
        }, async (upstreamRes) => {
            try {
                if (mode === 'proxy-pass') {
                    clientRes.writeHead(upstreamRes.statusCode || 502, copyHeaders(upstreamRes.headers));
                    upstreamRes.pipe(clientRes);
                    return;
                }

                let body = await collectStream(upstreamRes);
                let headers = copyHeaders(upstreamRes.headers);

                if (mode === 'proxy-gzip-identity' && !headers['content-encoding']) {
                    body = gzipSync(body, { level: 6 });
                    headers = {
                        ...headers,
                        'content-encoding': 'gzip',
                    };
                } else if (mode === 'proxy-strip-http-content-encoding') {
                    delete headers['content-encoding'];
                } else if (
                    mode === 'proxy-strip-part-encoding' &&
                    !headers['content-encoding']
                ) {
                    body = Buffer.from(
                        body
                            .toString('binary')
                            .replace(/\r?\nX-Body-Encoding: gzip\r?\n/gi, '\r\n')
                            .replace(/\r?\nX-Decoded-Content-Length: [0-9]+\r?\n/gi, '\r\n'),
                        'binary',
                    );
                }

                clientRes.writeHead(upstreamRes.statusCode || 502, headers);
                clientRes.end(body);
            } catch (error) {
                clientRes.writeHead(502, { 'content-type': 'text/plain' });
                clientRes.end(String(error && error.stack ? error.stack : error));
            }
        });

        upstreamReq.on('error', (error) => {
            clientRes.writeHead(502, { 'content-type': 'text/plain' });
            clientRes.end(String(error.message || error));
        });
        clientReq.pipe(upstreamReq);
    });

    const port = await getFreePort();
    await new Promise((resolveListen) => server.listen(port, '127.0.0.1', resolveListen));

    return {
        name: mode,
        baseUrl: `http://127.0.0.1:${port}`,
        close: async () => new Promise((resolveClose) => server.close(resolveClose)),
    };
}

async function createWrapper(root, name, basePhpServer) {
    if (name === 'php-default') {
        return basePhpServer;
    }
    if (name === 'php-zlib-buffering') {
        return startPhpServer(root, name, [
            '-d', 'zlib.output_compression=1',
            '-d', 'output_buffering=4096',
        ]);
    }
    if (name.startsWith('proxy-')) {
        return startProxy(basePhpServer.baseUrl, name);
    }
    throw new Error(`Unknown wrapper: ${name}`);
}

function requestFileFetch(wrapper, dataset, mode, chunkSize = CHUNK_SIZE) {
    const url = new URL(wrapper.baseUrl);
    url.searchParams.set('endpoint', 'file_fetch');
    url.searchParams.set('directory', dataset.siteDir);
    url.searchParams.set('file_list_path', dataset.listPath);
    url.searchParams.set('chunk_size', String(chunkSize));
    if (mode === 'new') {
        url.searchParams.set('file_part_gzip', '1');
    }

    const start = performance.now();
    return new Promise((resolveResult) => {
        const req = request({
            hostname: url.hostname,
            port: url.port,
            path: `${url.pathname}${url.search}`,
            method: 'GET',
            headers: {
                'accept': 'multipart/mixed,*/*',
                'accept-encoding': 'gzip, deflate',
                'user-agent': 'ReprintFileFetchBench/1.0',
            },
        }, async (res) => {
            try {
                const wireBody = await collectStream(res);
                const elapsedMs = performance.now() - start;
                const httpEncoding = String(res.headers['content-encoding'] || 'identity').toLowerCase();
                let decodedBody = wireBody;
                if (httpEncoding === 'gzip') {
                    decodedBody = gunzipSync(wireBody);
                } else if (httpEncoding !== 'identity' && httpEncoding !== '') {
                    throw new Error(`Unsupported response content-encoding: ${httpEncoding}`);
                }

                const verification = verifyMultipart(decodedBody, res.headers, dataset);
                resolveResult({
                    ok: res.statusCode === 200 && verification.ok,
                    statusCode: res.statusCode,
                    elapsedMs,
                    wallMs: elapsedMs,
                    wireBytes: wireBody.length,
                    decodedHttpBytes: decodedBody.length,
                    httpEncoding,
                    ...verification,
                });
            } catch (error) {
                resolveResult({
                    ok: false,
                    statusCode: res.statusCode,
                    elapsedMs: performance.now() - start,
                    wireBytes: 0,
                    decodedHttpBytes: 0,
                    httpEncoding: String(res.headers['content-encoding'] || 'identity').toLowerCase(),
                    error: String(error && error.message ? error.message : error),
                });
            }
        });
        req.on('error', (error) => {
            resolveResult({
                ok: false,
                statusCode: 0,
                elapsedMs: performance.now() - start,
                wireBytes: 0,
                decodedHttpBytes: 0,
                httpEncoding: 'identity',
                error: String(error.message || error),
            });
        });
        req.end();
    });
}

function findHeaderEnd(buffer, start) {
    const crlf = Buffer.from('\r\n\r\n');
    const lf = Buffer.from('\n\n');
    const crlfPos = buffer.indexOf(crlf, start);
    const lfPos = buffer.indexOf(lf, start);
    if (crlfPos === -1) {
        return lfPos === -1 ? null : { pos: lfPos, length: 2 };
    }
    if (lfPos === -1 || crlfPos < lfPos) {
        return { pos: crlfPos, length: 4 };
    }
    return { pos: lfPos, length: 2 };
}

function findLineEnd(buffer, start) {
    const lf = buffer.indexOf(0x0a, start);
    if (lf === -1) return -1;
    return lf + 1;
}

function parsePartHeaders(headerBlock) {
    const headers = {};
    for (const rawLine of headerBlock.split(/\r?\n/)) {
        const idx = rawLine.indexOf(':');
        if (idx === -1) continue;
        headers[rawLine.slice(0, idx).trim().toLowerCase()] = rawLine.slice(idx + 1).trimStart();
    }
    return headers;
}

function parseMultipart(body, boundary) {
    const delimiter = Buffer.from(`--${boundary}`);
    const parts = [];
    let pos = body.indexOf(delimiter);
    if (pos === -1) {
        throw new Error('multipart boundary not found in decoded HTTP body');
    }

    while (pos !== -1) {
        const afterDelimiter = pos + delimiter.length;
        if (body.subarray(afterDelimiter, afterDelimiter + 2).toString('latin1') === '--') {
            return parts;
        }

        const headersStart = findLineEnd(body, afterDelimiter);
        if (headersStart === -1) {
            throw new Error('unterminated multipart boundary line');
        }

        const headerEnd = findHeaderEnd(body, headersStart);
        if (headerEnd === null) {
            throw new Error('unterminated multipart headers');
        }

        const headerText = body.subarray(headersStart, headerEnd.pos).toString('latin1');
        const headers = parsePartHeaders(headerText);
        const bodyStart = headerEnd.pos + headerEnd.length;
        const contentLength = Number(headers['content-length']);
        if (!Number.isInteger(contentLength) || contentLength < 0) {
            throw new Error('multipart part missing valid content-length');
        }
        const bodyEnd = bodyStart + contentLength;
        if (bodyEnd > body.length) {
            throw new Error(`multipart body truncated: expected ${contentLength} bytes`);
        }

        parts.push({
            headers,
            body: body.subarray(bodyStart, bodyEnd),
        });

        pos = bodyEnd;
        if (body[pos] === 0x0d && body[pos + 1] === 0x0a) {
            pos += 2;
        } else if (body[pos] === 0x0a) {
            pos += 1;
        }
        pos = body.indexOf(delimiter, pos);
    }

    throw new Error('closing multipart boundary not found');
}

function decodeBase64Header(value) {
    if (!value) return '';
    return Buffer.from(value, 'base64').toString();
}

function verifyMultipart(body, responseHeaders, dataset) {
    const contentType = String(responseHeaders['content-type'] || '');
    const match = contentType.match(/boundary="?([^";\s]+)"?/i);
    if (!match) {
        throw new Error(`missing multipart boundary header; content-type=${contentType || '<none>'}`);
    }

    const parts = parseMultipart(body, match[1]);
    const expectedByPath = new Map(dataset.files.map((file) => [file.path, file]));
    const buffersByPath = new Map();
    let fileParts = 0;
    let gzipFileParts = 0;
    let completionStatus = '';
    let serverTimeSeconds = null;

    for (const part of parts) {
        const chunkType = part.headers['x-chunk-type'] || '';
        if (chunkType === 'completion') {
            completionStatus = part.headers['x-status'] || '';
            if (part.headers['x-time-elapsed']) {
                serverTimeSeconds = Number(part.headers['x-time-elapsed']);
            }
            continue;
        }
        if (chunkType !== 'file') {
            continue;
        }

        fileParts++;
        const path = decodeBase64Header(part.headers['x-file-path']);
        if (!expectedByPath.has(path)) {
            throw new Error(`unexpected file part path: ${path}`);
        }

        let data = part.body;
        const bodyEncoding = String(part.headers['x-body-encoding'] || '').toLowerCase();
        if (bodyEncoding === 'gzip') {
            gzipFileParts++;
            data = gunzipSync(data);
            const expectedDecodedLength = Number(part.headers['x-decoded-content-length'] || -1);
            if (expectedDecodedLength !== data.length) {
                throw new Error(`decoded length mismatch for ${path}: expected ${expectedDecodedLength}, got ${data.length}`);
            }
        } else if (bodyEncoding && bodyEncoding !== 'identity') {
            throw new Error(`unsupported part body encoding for ${path}: ${bodyEncoding}`);
        }

        const expectedChunkSize = Number(part.headers['x-chunk-size'] || -1);
        if (expectedChunkSize !== data.length) {
            throw new Error(`chunk size mismatch for ${path}: expected ${expectedChunkSize}, got ${data.length}`);
        }

        const offset = Number(part.headers['x-chunk-offset'] || 0);
        const existing = buffersByPath.get(path) || Buffer.alloc(0);
        if (existing.length !== offset) {
            throw new Error(`chunk offset mismatch for ${path}: expected offset ${existing.length}, got ${offset}`);
        }
        buffersByPath.set(path, Buffer.concat([existing, data]));
    }

    if (completionStatus !== 'complete') {
        throw new Error(`missing complete status; status=${completionStatus || '<none>'}`);
    }

    for (const expected of dataset.files) {
        const actual = buffersByPath.get(expected.path);
        if (!actual) {
            throw new Error(`missing file in response: ${expected.path}`);
        }
        if (actual.length !== expected.size) {
            throw new Error(`file size mismatch for ${expected.path}: expected ${expected.size}, got ${actual.length}`);
        }
        const hash = createHash('sha256').update(actual).digest('hex');
        if (hash !== expected.sha256) {
            throw new Error(`file hash mismatch for ${expected.path}`);
        }
    }

    return {
        ok: true,
        parts: parts.length,
        fileParts,
        gzipFileParts,
        serverTimeSeconds,
    };
}

async function runScenario(wrapper, dataset, mode, reps = REPS, chunkSize = CHUNK_SIZE) {
    const warmup = await requestFileFetch(wrapper, dataset, mode, chunkSize);
    if (!warmup.ok) {
        return {
            wrapper: wrapper.name,
            dataset: dataset.name,
            mode,
            ok: false,
            runs: [warmup],
            error: warmup.error || `HTTP ${warmup.statusCode}`,
        };
    }

    const runs = [];
    for (let i = 0; i < reps; i++) {
        const run = await requestFileFetch(wrapper, dataset, mode, chunkSize);
        runs.push(run);
        if (!run.ok) {
            return {
                wrapper: wrapper.name,
                dataset: dataset.name,
                mode,
                ok: false,
                runs,
                error: run.error || `HTTP ${run.statusCode}`,
            };
        }
    }

    return summarizeScenario(wrapper.name, dataset.name, mode, runs);
}

function summarizeScenario(wrapperName, datasetName, mode, runs) {
    return {
        wrapper: wrapperName,
        dataset: datasetName,
        mode,
        ok: runs.every((run) => run.ok),
        reps: runs.length,
        wallMsMedian: median(runs.map((run) => run.wallMs)),
        wallMsMin: Math.min(...runs.map((run) => run.wallMs)),
        wallMsMax: Math.max(...runs.map((run) => run.wallMs)),
        serverMsMedian: median(runs.map((run) => run.serverTimeSeconds * 1000).filter(Number.isFinite)),
        wireBytesMedian: median(runs.map((run) => run.wireBytes)),
        decodedHttpBytesMedian: median(runs.map((run) => run.decodedHttpBytes)),
        filePartsMedian: median(runs.map((run) => run.fileParts)),
        gzipFilePartsMedian: median(runs.map((run) => run.gzipFileParts)),
        httpEncoding: runs[0]?.httpEncoding || '',
        runs,
    };
}

async function runPerformanceMatrix(root, datasets, basePhpServer) {
    const results = [];
    for (const wrapperName of WRAPPER_FILTER) {
        console.log(`wrapper=${wrapperName}`);
        const wrapper = await createWrapper(root, wrapperName, basePhpServer);
        try {
            for (const datasetName of DATASET_FILTER) {
                const dataset = datasets.get(datasetName);
                if (!dataset) {
                    throw new Error(`Unknown dataset requested: ${datasetName}`);
                }
                console.log(`  dataset=${datasetName} total=${fmtBytes(dataset.totalBytes)} files=${dataset.files.length}`);
                for (const mode of ['old', 'new']) {
                    const result = await runScenario(wrapper, dataset, mode);
                    results.push(result);
                    const label = result.ok
                        ? `${fmtMs(result.wallMsMedian)} wire=${fmtBytes(result.wireBytesMedian)} gzipParts=${result.gzipFilePartsMedian}`
                        : `FAIL ${result.error}`;
                    console.log(`    ${mode}: ${label}`);
                }
            }
        } finally {
            if (wrapper !== basePhpServer) {
                await wrapper.close();
            }
        }
    }
    return results;
}

async function runBreakageProbes(root, datasets, basePhpServer) {
    const probes = [];

    for (const wrapperName of BREAK_WRAPPERS) {
        const wrapper = await createWrapper(root, wrapperName, basePhpServer);
        try {
            const dataset = wrapperName === 'proxy-strip-http-content-encoding'
                ? datasets.get('all-text-many-small')
                : datasets.get('mixed-binary-heavy');
            for (const mode of ['old', 'new']) {
                const run = await requestFileFetch(wrapper, dataset, mode);
                probes.push({
                    probe: wrapperName,
                    dataset: dataset.name,
                    mode,
                    ok: run.ok,
                    statusCode: run.statusCode,
                    error: run.error || '',
                    wireBytes: run.wireBytes,
                    decodedHttpBytes: run.decodedHttpBytes,
                });
            }
        } finally {
            await wrapper.close();
        }
    }

    for (const memoryLimit of ['32M', '48M', '64M', '96M']) {
        const php = await startPhpServer(root, `php-memory-${memoryLimit.toLowerCase()}`, [
            '-d', `memory_limit=${memoryLimit}`,
            '-d', 'output_buffering=0',
            '-d', 'zlib.output_compression=0',
        ]);
        try {
            const dataset = datasets.get('large-text-part-mixed');
            for (const mode of ['old', 'new']) {
                const run = await requestFileFetch(php, dataset, mode, 32 * MIB);
                probes.push({
                    probe: `memory_limit=${memoryLimit}`,
                    dataset: dataset.name,
                    mode,
                    ok: run.ok,
                    statusCode: run.statusCode,
                    error: run.error || '',
                    wireBytes: run.wireBytes,
                    decodedHttpBytes: run.decodedHttpBytes,
                    phpStderrTail: php.stderr ? php.stderr().slice(-1000) : '',
                });
            }
        } finally {
            await php.close();
        }
    }

    return probes;
}

function renderPerformanceMarkdown(results, datasets) {
    const byKey = new Map();
    for (const result of results) {
        byKey.set(`${result.wrapper}/${result.dataset}/${result.mode}`, result);
    }

    const lines = [];
    lines.push(`## file_fetch HTTP wrapper benchmark`);
    lines.push('');
    lines.push(`Reps: ${REPS} recorded + 1 warmup per scenario. Chunk size: ${fmtBytes(CHUNK_SIZE)}.`);
    lines.push('');
    lines.push('| Dataset | Wrapper | Old median | New median | New vs old | Old wire | New wire | Wire delta | Encodings |');
    lines.push('|---|---|---:|---:|---:|---:|---:|---:|---|');

    for (const wrapper of WRAPPER_FILTER) {
        for (const dataset of DATASET_FILTER) {
            const oldResult = byKey.get(`${wrapper}/${dataset}/old`);
            const newResult = byKey.get(`${wrapper}/${dataset}/new`);
            if (!oldResult || !newResult) continue;
            if (!oldResult.ok || !newResult.ok) {
                lines.push(`| ${dataset} | ${wrapper} | ${oldResult.ok ? fmtMs(oldResult.wallMsMedian) : 'FAIL'} | ${newResult.ok ? fmtMs(newResult.wallMsMedian) : 'FAIL'} | | | | | ${oldResult.error || newResult.error || ''} |`);
                continue;
            }
            const timeDelta = pctDelta(newResult.wallMsMedian, oldResult.wallMsMedian);
            const wireDelta = pctDelta(newResult.wireBytesMedian, oldResult.wireBytesMedian);
            lines.push([
                dataset,
                wrapper,
                fmtMs(oldResult.wallMsMedian),
                fmtMs(newResult.wallMsMedian),
                `${timeDelta > 0 ? '+' : ''}${timeDelta.toFixed(1)}%`,
                fmtBytes(oldResult.wireBytesMedian),
                fmtBytes(newResult.wireBytesMedian),
                `${wireDelta > 0 ? '+' : ''}${wireDelta.toFixed(1)}%`,
                `${oldResult.httpEncoding}->${newResult.httpEncoding}; parts ${oldResult.gzipFilePartsMedian}->${newResult.gzipFilePartsMedian}`,
            ].map((value) => String(value).replaceAll('|', '/')).join(' | ').replace(/^/, '| ').replace(/$/, ' |'));
        }
    }

    lines.push('');
    lines.push('### Datasets');
    lines.push('');
    for (const name of DATASET_FILTER) {
        const dataset = datasets.get(name);
        if (!dataset) continue;
        lines.push(`- ${name}: ${dataset.description}; ${dataset.files.length} files; ${fmtBytes(dataset.totalBytes)}`);
    }
    lines.push('');
    return lines.join('\n');
}

function renderBreakageMarkdown(probes) {
    const lines = [];
    lines.push('## Breakage probes');
    lines.push('');
    lines.push('| Probe | Dataset | Mode | Result | Error |');
    lines.push('|---|---|---|---|---|');
    for (const probe of probes) {
        const error = (probe.error || '')
            .replace(/\s+/g, ' ')
            .slice(0, 140)
            .replaceAll('|', '/');
        lines.push(`| ${probe.probe} | ${probe.dataset} | ${probe.mode} | ${probe.ok ? 'ok' : `FAIL HTTP ${probe.statusCode}`} | ${error} |`);
    }
    lines.push('');
    return lines.join('\n');
}

async function main() {
    const root = mkdtempSync(join(tmpdir(), 'reprint-file-fetch-wrappers-'));
    console.log(`tmp=${root}`);
    console.log(`project=${PROJECT_ROOT}`);
    console.log(`reps=${REPS} chunk=${fmtBytes(CHUNK_SIZE)}`);

    let basePhpServer = null;
    try {
        const datasets = buildDatasets(root);
        basePhpServer = await startPhpServer(root, 'php-default');
        const performanceResults = await runPerformanceMatrix(root, datasets, basePhpServer);
        const breakageProbes = await runBreakageProbes(root, datasets, basePhpServer);

        const markdown =
            renderPerformanceMarkdown(performanceResults, datasets) +
            renderBreakageMarkdown(breakageProbes);
        console.log('\n' + markdown);

        const payload = {
            meta: {
                projectRoot: PROJECT_ROOT,
                reps: REPS,
                chunkSize: CHUNK_SIZE,
                tmp: root,
                wrappers: WRAPPER_FILTER,
                datasets: DATASET_FILTER,
            },
            performanceResults,
            breakageProbes,
            markdown,
        };

        if (OUTPUT_PATH) {
            writeFileSync(OUTPUT_PATH, JSON.stringify(payload, null, 2));
            console.log(`wrote ${OUTPUT_PATH}`);
        }

        if (
            performanceResults.some((result) => !result.ok) ||
            breakageProbes.some((probe) => !probe.ok && probe.probe.startsWith('memory_limit=96M'))
        ) {
            process.exitCode = 1;
        }
    } finally {
        if (basePhpServer) {
            await basePhpServer.close();
        }
        if (!KEEP_TMP) {
            rmSync(root, { recursive: true, force: true });
        } else {
            console.log(`kept ${root}`);
        }
    }
}

main().catch((error) => {
    console.error(error && error.stack ? error.stack : error);
    process.exit(1);
});
