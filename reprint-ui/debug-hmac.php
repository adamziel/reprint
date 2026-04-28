<?php
/**
 * Hand-rolled rotate + sign + ping, with every byte dumped, so we can
 * see whether wpcomsh's HMAC error is from our signing or from the
 * site genuinely having a different stored secret.
 */
require __DIR__ . '/reprint-ui/bootstrap.php';
reprint_session_start();
header('Content-Type: text/plain; charset=utf-8');

$token   = reprint_wpcom_token();
$site_id = (int) ($_GET['site_id'] ?? 0);
$site_url = $_GET['site_url'] ?? '';
if (!$token || !$site_id || !$site_url) {
    echo "Need an authenticated session and ?site_id=X&site_url=https://…\n";
    exit;
}

function pp($label, $value) {
    echo "── $label ──\n";
    if (is_string($value)) {
        echo $value . "\n  (len=" . strlen($value) . ", hex_head=" . bin2hex(substr($value, 0, 16)) . ", hex_tail=" . bin2hex(substr($value, -16)) . ")\n\n";
    } else {
        echo var_export($value, true) . "\n\n";
    }
}

function http(string $method, string $url, array $headers = [], string $body = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Reprint-debug/1.0',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $hsize = (int) $info['header_size'];
    $hdrs = substr((string) $resp, 0, $hsize);
    $b = substr((string) $resp, $hsize);
    curl_close($ch);
    return ['code' => (int) $info['http_code'], 'headers' => $hdrs, 'body' => (string) $b];
}

// 1) Enable
$enable = http(
    'POST',
    "https://public-api.wordpress.com/rest/v1.1/sites/{$site_id}/settings",
    ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    http_build_query(['reprint_exporter_enabled' => time()])
);
pp('ENABLE response code', (string) $enable['code']);
pp('ENABLE response body', $enable['body']);

// 2) Rotate
$rotate = http(
    'POST',
    "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
    ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
    json_encode(['path' => '/wpcomsh/v1/reprint/rotate-export-secret'])
);
pp('ROTATE response code', (string) $rotate['code']);
pp('ROTATE response body (raw)', $rotate['body']);

$env = json_decode($rotate['body'], true);
pp('ROTATE parsed envelope', $env);

$inner = $env['body'] ?? null;
if (is_string($inner)) $inner = json_decode($inner, true);
pp('ROTATE inner', $inner);

$secret = $inner['data']['secret'] ?? ($inner['secret'] ?? null);
pp('SECRET extracted', (string) $secret);

if (!$secret) { echo "no secret, abort\n"; exit; }

// 3) Sign + ping using exporter's algorithm
$nonce = bin2hex(random_bytes(16));
$ts = sprintf('%.6f', microtime(true));
$content_hash = hash('sha256', '');
$sig = hash_hmac('sha256', $nonce . $ts . $content_hash, $secret);

pp('nonce', $nonce);
pp('timestamp', $ts);
pp('content_hash', $content_hash);
pp('signature', $sig);

$preflight_url = rtrim($site_url, '/') . '/?reprint-api&site-export-api&endpoint=preflight';
echo "→ GET $preflight_url\n\n";

$ping = http('GET', $preflight_url, [
    'Accept: application/json',
    'X-Auth-Signature: ' . $sig,
    'X-Auth-Nonce: ' . $nonce,
    'X-Auth-Timestamp: ' . $ts,
    'X-Auth-Content-Hash: ' . $content_hash,
]);

pp('PREFLIGHT code', (string) $ping['code']);
pp('PREFLIGHT response headers', $ping['headers']);
pp('PREFLIGHT response body', substr($ping['body'], 0, 1500));

// 4) Immediately rotate AGAIN to see if subsequent rotates return same or different secrets
$rotate2 = http(
    'POST',
    "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
    ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
    json_encode(['path' => '/wpcomsh/v1/reprint/rotate-export-secret'])
);
$env2 = json_decode($rotate2['body'], true);
$inner2 = $env2['body'] ?? null;
if (is_string($inner2)) $inner2 = json_decode($inner2, true);
$secret2 = $inner2['data']['secret'] ?? ($inner2['secret'] ?? null);
pp('SECOND ROTATE secret', (string) $secret2);
echo $secret === $secret2 ? "‼️ Same secret returned twice\n" : "✓ Different secret on second rotate\n";

// 5) Try ping with secret2
$nonce = bin2hex(random_bytes(16));
$ts = sprintf('%.6f', microtime(true));
$sig = hash_hmac('sha256', $nonce . $ts . $content_hash, $secret2);
$ping2 = http('GET', $preflight_url, [
    'Accept: application/json',
    'X-Auth-Signature: ' . $sig,
    'X-Auth-Nonce: ' . $nonce,
    'X-Auth-Timestamp: ' . $ts,
    'X-Auth-Content-Hash: ' . $content_hash,
]);
pp('PREFLIGHT 2 code', (string) $ping2['code']);
pp('PREFLIGHT 2 body', substr($ping2['body'], 0, 800));

// 6) Read the site option directly via the Jetpack proxy — confirms what
// the SITE has stored (vs what wpcom-side rotate returned). If rotate is
// being intercepted by wpcom and never reaching the site, the site's
// stored value will differ from what rotate returned.
$opt = http(
    'POST',
    "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
    ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
    json_encode(['path' => '/wp/v2/settings'])  // returns whitelisted settings; reprint_exporter_secret isn't whitelisted but reprint_exporter_enabled is
);
pp('SITE settings (Jetpack proxy)', substr($opt['body'], 0, 1500));

// 7) Confirm the site_id resolves to the URL we expect
$siteinfo = http(
    'GET',
    "https://public-api.wordpress.com/rest/v1.2/sites/{$site_id}?fields=ID,URL,name,is_wpcom_atomic,is_wpcom_simple,is_jetpack",
    ['Authorization: Bearer ' . $token, 'Accept: application/json']
);
pp('SITE info from /sites/<id>', $siteinfo['body']);

// 7b) Check whether wpcomsh's REST namespace is actually present on this
// site (proves the reprint-exporter-api.php is loaded, with all its
// filters/hooks). If wpcomsh/v1 is missing, rotate is being handled
// somewhere wpcom-side rather than on the site itself.
$nsroot = http(
    'POST',
    "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
    ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
    json_encode(['path' => '/'])
);
$nsbody = $nsroot['body'];
preg_match_all('/"namespaces":\[[^\]]+\]/', $nsbody, $m);
pp('REST namespaces on site (wpcomsh/v1 present?)', $m[0][0] ?? '(no match)');
pp('contains "wpcomsh/v1"', strpos($nsbody, 'wpcomsh/v1') !== false ? 'YES — wpcomsh is loaded on the site' : 'NO — wpcomsh appears to NOT be loaded on this site');

// 7c) Read wpcomsh routes specifically
$wpcomsh_routes = http(
    'POST',
    "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
    ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
    json_encode(['path' => '/wpcomsh/v1'])
);
pp('wpcomsh/v1 route listing', substr($wpcomsh_routes['body'], 0, 1500));

// 8) Hit the same ?reprint-api endpoint without HMAC headers to see if
// the request actually reaches wpcomsh's handler (vs being intercepted
// by a cache/proxy/CDN before reaching the site).
$bare = http('GET', rtrim($site_url, '/') . '/?reprint-api', ['Accept: application/json']);
pp('BARE preflight (no HMAC) code', (string) $bare['code']);
pp('BARE preflight headers', $bare['headers']);
pp('BARE preflight body', substr($bare['body'], 0, 600));
