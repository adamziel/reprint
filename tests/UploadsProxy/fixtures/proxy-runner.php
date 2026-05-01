<?php
/**
 * Test runner for PlaygroundUploadsProxyHandlerTest.
 *
 * Loads the proxy handler, sets up the env it expects (REQUEST_URI,
 * WP_CONTENT_DIR), and passes capturing closures via the handler's
 * `$emitters` test seam. Status / header calls are appended (in
 * invocation order) to a sidecar JSON-lines file; the body is echoed
 * to stdout as the visitor would receive it.
 *
 * Usage: php proxy-runner.php <source-origin> <request-uri> <sidecar-file>
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: proxy-runner.php <source-origin> <request-uri> <sidecar-file>\n");
    exit(1);
}

$source_origin = $argv[1];
$request_uri = $argv[2];
$sidecar = $argv[3];

$_SERVER['REQUEST_URI'] = $request_uri;

// The handler insists on a real WP_CONTENT_DIR existing so it can
// fall through to the proxy when the local file is absent.
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/reprint-uploads-proxy-test-empty');
@mkdir(WP_CONTENT_DIR, 0700, true);

require __DIR__ . '/../../../reprint-ui/lib/playground-uploads-proxy-handler.php';

$write_event = function (array $event) use ($sidecar): void {
    file_put_contents($sidecar, json_encode($event) . "\n", FILE_APPEND);
};

reprint_playground_uploads_proxy_handle_request($source_origin, [
    'status' => function ($code) use ($write_event) {
        $write_event(['kind' => 'status', 'code' => (int) $code]);
    },
    'header' => function ($line) use ($write_event) {
        $write_event(['kind' => 'header', 'line' => (string) $line]);
    },
    'body' => function ($chunk) {
        echo $chunk;
        flush();
    },
    'exit' => function () {
        exit;
    },
]);
