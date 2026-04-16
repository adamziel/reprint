/**
 * Reproduces the exporter's gzipped multipart SQL streaming.
 *
 * Sends many SQL chunks through a gzip compressor with Z_SYNC_FLUSH
 * between each — the same pattern as the real exporter. The client's
 * WRITEFUNCTION callback does heavy work (multipart parsing + SQL
 * execution) during decompression. Under WASM PHP's Asyncify, the
 * async I/O from MySQL queries may corrupt zlib's stack state.
 *
 * Usage:
 *   node server.js &
 *   npx @wp-playground/cli php --mount=$(pwd):$(pwd) -- $(pwd)/repro.php
 */
const http = require('http');
const zlib = require('zlib');

const PORT = 18787;

const server = http.createServer((req, res) => {
    res.writeHead(200, {
        'Content-Type': 'multipart/mixed; boundary=BOUNDARY',
        'Content-Encoding': 'gzip',
        'Transfer-Encoding': 'chunked',
    });

    const gz = zlib.createGzip({ level: 6 });
    gz.on('data', chunk => res.write(chunk));

    // Send many SQL chunks with sync flushes — mimics the exporter
    // producing batched INSERT statements across multiple HTTP chunks.
    let i = 0;
    const total = 100;

    function writeNext() {
        if (i >= total) {
            gz.write(`--BOUNDARY--\r\n`);
            gz.end(() => res.end());
            return;
        }

        // ~8KB of SQL per chunk
        const rows = [];
        for (let r = 0; r < 50; r++) {
            const id = i * 50 + r;
            rows.push(`(${id}, 'post_${id}', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.', '2024-01-15 12:00:00')`);
        }
        const sql = `INSERT INTO wp_posts VALUES ${rows.join(',\n')};\n`;

        const part = [
            `--BOUNDARY\r\n`,
            `Content-Type: application/sql\r\n`,
            `X-Chunk-Type: sql\r\n`,
            `Content-Length: ${Buffer.byteLength(sql)}\r\n`,
            `\r\n`,
            sql,
            `\r\n`,
        ].join('');

        gz.write(part);
        i++;

        // Sync flush between chunks — same as GzipOutputStream.sync()
        gz.flush(zlib.constants.Z_SYNC_FLUSH, writeNext);
    }

    writeNext();
});

server.listen(PORT, '127.0.0.1', () => {
    console.log(`http://127.0.0.1:${PORT}`);
});
