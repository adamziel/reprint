/**
 * Reproduction server for WASM PHP curl/gzip crash.
 *
 * The crash is NOT about corrupt gzip data — WASM PHP handles corrupt
 * gzip the same way native PHP does (curl error 61). The crash is about
 * Asyncify corrupting zlib's internal state when the WASM stack is
 * unwound/rewound during async I/O pauses between HTTP chunks.
 *
 * This server sends valid gzip data in many small chunks with delays,
 * forcing multiple Asyncify unwind/rewind cycles during curl's
 * gzip_unencode_write → inflate → updatewindow path.
 *
 * Usage:
 *   node tests/wasm-curl-repro/server.js &
 *   npx @wp-playground/cli php --mount=$(pwd):$(pwd) -- $(pwd)/tests/wasm-curl-repro/repro.php
 */
const http = require('http');
const zlib = require('zlib');
const crypto = require('crypto');

const PORT = 18787;

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

const server = http.createServer(async (req, res) => {
    console.log(`${req.method} ${req.url}`);

    if (req.url === '/slow-chunks') {
        // Send valid gzip data in many tiny chunks with delays.
        // Each delay triggers an Asyncify unwind/rewind in WASM PHP's curl.
        // If the unwind happens while zlib's inflate() is mid-execution,
        // the internal state (window buffer, bit accumulator) gets corrupted.
        // The next inflate() call reads corrupted state → OOB access → trap.
        res.writeHead(200, {
            'Content-Type': 'text/plain',
            'Content-Encoding': 'gzip',
            'Transfer-Encoding': 'chunked',
        });

        // Compress a large body
        const body = crypto.randomBytes(32768).toString('base64');
        const compressed = zlib.gzipSync(Buffer.from(body));

        // Send in very small chunks (16-64 bytes each) with tiny delays
        const chunkSize = 32;
        for (let i = 0; i < compressed.length; i += chunkSize) {
            const chunk = compressed.subarray(i, Math.min(i + chunkSize, compressed.length));
            res.write(chunk);
            // Small delay to force async I/O boundaries
            await sleep(1);
        }
        res.end();
        return;
    }

    if (req.url === '/many-flushes') {
        // Simulate the exporter's GzipOutputStream pattern:
        // incremental deflate_add() with ZLIB_SYNC_FLUSH after each part.
        // Each flush creates a sync point in the gzip stream, and the
        // chunked response delivers each flushed segment separately.
        res.writeHead(200, {
            'Content-Type': 'multipart/mixed; boundary=BOUNDARY',
            'Content-Encoding': 'gzip',
            'Transfer-Encoding': 'chunked',
        });

        const gzip = zlib.createGzip({ level: 6, flush: zlib.constants.Z_SYNC_FLUSH });
        gzip.on('data', (chunk) => {
            res.write(chunk);
        });

        // Write many small parts with flushes between them,
        // mimicking the exporter's multipart output
        for (let i = 0; i < 100; i++) {
            const part = [
                `--BOUNDARY\r\n`,
                `Content-Type: application/octet-stream\r\n`,
                `X-Part: ${i}\r\n`,
                `\r\n`,
                `INSERT INTO t VALUES (${i}, '${crypto.randomBytes(256).toString('hex')}');\n`,
                `\r\n`,
            ].join('');

            gzip.write(part);
            // Force a sync flush after each part
            await new Promise(resolve => gzip.flush(resolve));
            // Small delay between parts
            await sleep(2);
        }

        await new Promise(resolve => gzip.end(resolve));
        res.end();
        return;
    }

    if (req.url === '/burst-pause') {
        // Alternate between bursts of data and pauses.
        // The burst fills zlib's internal buffers; the pause triggers
        // Asyncify. When inflate() resumes, the window state may be wrong.
        res.writeHead(200, {
            'Content-Type': 'text/plain',
            'Content-Encoding': 'gzip',
            'Transfer-Encoding': 'chunked',
        });

        const gzip = zlib.createGzip({ level: 1 });
        gzip.on('data', (chunk) => res.write(chunk));

        for (let burst = 0; burst < 20; burst++) {
            // Write a burst of data (fills zlib window)
            for (let i = 0; i < 10; i++) {
                gzip.write(crypto.randomBytes(4096).toString('base64'));
            }
            await new Promise(resolve => gzip.flush(resolve));
            // Pause to trigger Asyncify unwind
            await sleep(5);
        }

        await new Promise(resolve => gzip.end(resolve));
        res.end();
        return;
    }

    if (req.url === '/corrupt-mid-stream') {
        // Original strategy: valid gzip with garbage injected mid-stream.
        // Keeping this as a reference, though it doesn't crash locally.
        res.writeHead(200, {
            'Content-Type': 'text/plain',
            'Content-Encoding': 'gzip',
            'Transfer-Encoding': 'chunked',
        });

        const gzip = zlib.createGzip({ level: 6 });
        gzip.on('data', (chunk) => res.write(chunk));

        gzip.write('X'.repeat(65536));
        await new Promise(resolve => gzip.flush(resolve));

        // Inject garbage bytes bypassing the compressor
        res.write(Buffer.from('\x1f\x8b\x08CORRUPTED_GZIP_DATA'));
        res.write(crypto.randomBytes(512));

        await new Promise(resolve => gzip.end(resolve));
        res.end();
        return;
    }

    res.writeHead(200, { 'Content-Type': 'text/plain' });
    res.end([
        'Endpoints:',
        '  /slow-chunks        - Valid gzip in 32-byte chunks with 1ms delays',
        '  /many-flushes       - 100 gzip sync-flushed parts with 2ms delays',
        '  /burst-pause        - Data bursts alternating with 5ms pauses',
        '  /corrupt-mid-stream - Valid gzip with garbage injected mid-stream',
    ].join('\n'));
});

server.listen(PORT, '127.0.0.1', () => {
    console.log(`Crash repro server on http://127.0.0.1:${PORT}`);
});
