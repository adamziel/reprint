<?php
/**
 * Reproduction of WASM PHP curl/gzip crash.
 *
 * Phase 1: Fetch the same URL twice:
 *   - Once WITHOUT CURLOPT_ENCODING → save raw gzip bytes to disk
 *   - Once WITH CURLOPT_ENCODING → trigger the crash
 *
 * Phase 2: If Phase 1 doesn't crash, replay saved raw bytes through
 *   PHP's own gzip decompression to confirm the data is valid/corrupt.
 *
 * The saved raw bytes can be downloaded as a CI artifact and replayed
 * locally to reproduce the crash.
 *
 * Usage:
 *   # Against our test server:
 *   REPRO_URL=http://127.0.0.1:18787/slow-chunks php repro.php
 *
 *   # Against the real export API (in CI):
 *   REPRO_URL="http://127.0.0.1:8081/?site-export-api&endpoint=sql_chunk&directory=/srv/e2e-sites/basic" \
 *     REPRO_SECRET=test-secret-basic \
 *     php repro.php
 */

$url = getenv('REPRO_URL') ?: 'http://127.0.0.1:18787/slow-chunks';
$secret = getenv('REPRO_SECRET') ?: '';
$dump_dir = getenv('REPRO_DUMP_DIR') ?: '/tmp/wasm-curl-repro';

@mkdir($dump_dir, 0777, true);

echo "PHP " . PHP_VERSION . "\n";
echo "URL: $url\n";
echo "Dump dir: $dump_dir\n\n";

// Build HMAC auth headers if secret is provided
function get_auth_headers(string $secret, string $body = ''): array {
    if (!$secret) return [];
    $timestamp = time();
    $nonce = bin2hex(random_bytes(16));
    $message = $timestamp . $nonce . $body;
    $signature = hash_hmac('sha256', $message, $secret);
    return [
        "X-Auth-Signature: $signature",
        "X-Auth-Timestamp: $timestamp",
        "X-Auth-Nonce: $nonce",
    ];
}

// Phase 1a: Fetch WITHOUT encoding to save raw response bytes
echo "=== Phase 1a: Fetch raw (no CURLOPT_ENCODING) ===\n";
$ch = curl_init($url);
$headers = get_auth_headers($secret);
$headers[] = 'Accept-Encoding: gzip';
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_HEADER         => true,
]);

$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($errno !== 0) {
    echo "curl error $errno: $error\n";
    exit(1);
}

$resp_headers = substr($response, 0, $header_size);
$raw_body = substr($response, $header_size);

echo "Response headers:\n$resp_headers\n";
echo "Raw body size: " . strlen($raw_body) . " bytes\n";

// Save the raw response
$raw_file = "$dump_dir/raw-response.bin";
file_put_contents($raw_file, $raw_body);
file_put_contents("$dump_dir/response-headers.txt", $resp_headers);
echo "Saved raw body to $raw_file\n";

// Check if it's actually gzip
$is_gzip = strlen($raw_body) >= 2 && ord($raw_body[0]) === 0x1f && ord($raw_body[1]) === 0x8b;
echo "Is gzip: " . ($is_gzip ? "yes" : "no") . "\n";

// Try PHP-level decompression
if ($is_gzip) {
    $decoded = @gzdecode($raw_body);
    if ($decoded === false) {
        echo "PHP gzdecode: FAILED (corrupt gzip data)\n";
        // Save a hex dump of the first/last bytes for analysis
        file_put_contents("$dump_dir/hex-head.txt",
            "First 256 bytes:\n" . bin2hex(substr($raw_body, 0, 256)) . "\n\n" .
            "Last 256 bytes:\n" . bin2hex(substr($raw_body, -256)) . "\n"
        );
    } else {
        echo "PHP gzdecode: OK, decoded to " . strlen($decoded) . " bytes\n";
        file_put_contents("$dump_dir/decoded.txt", substr($decoded, 0, 4096));
    }
}

echo "\n=== Phase 1b: Fetch WITH CURLOPT_ENCODING (may crash WASM) ===\n";
echo "If the process dies here, the raw bytes above are the crash input.\n\n";

$ch = curl_init($url);
$headers = get_auth_headers($secret);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => 'gzip, deflate',
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => $headers,
    // Enable verbose output to see curl's internal operations
    CURLOPT_VERBOSE        => true,
    CURLOPT_STDERR         => fopen("$dump_dir/curl-verbose.log", 'w'),
]);

$body = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0) {
    echo "curl error $errno: $error (HTTP $http_code)\n";
    echo "(This is the expected graceful failure on native PHP)\n";
} else {
    echo "OK, received " . strlen($body) . " bytes, HTTP $http_code\n";
}

echo "\nPhase 1 complete without crash.\n";

// Phase 2: Try to trigger the crash with rapid repeated requests
echo "\n=== Phase 2: Rapid repeated requests (Asyncify stress) ===\n";
for ($i = 1; $i <= 10; $i++) {
    $ch = curl_init($url);
    $headers = get_auth_headers($secret);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0) {
        echo "  request $i: curl error $errno: $error\n";
    } else {
        echo "  request $i: OK, " . strlen($body) . " bytes\n";
    }
}

echo "\nAll phases completed without crash.\n";
