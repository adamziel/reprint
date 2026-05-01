<?php
/**
 * Scripted HTTP origin used by PlaygroundUploadsProxyHandlerTest.
 *
 * Binds 127.0.0.1 on an ephemeral port, prints the port to stdout, then
 * answers each request with a response fixture chosen by the path:
 *
 *   /200-with-content-type   → 200 OK, Content-Type: image/jpeg, body
 *   /200-no-forwardable      → 200 OK, only Connection / Server, body
 *   /404-with-html           → 404, Content-Type: text/html, body
 *   /5xx-no-body             → 502, no headers besides Connection
 *
 * Usage: php scripted-source-server.php
 */
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$sock) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($sock, false);
$port = (int) substr($name, strrpos($name, ':') + 1);
fwrite(STDOUT, $port . "\n");
fflush(STDOUT);

while (true) {
    $conn = @stream_socket_accept($sock, 30);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 5);

    $request = '';
    while (!feof($conn)) {
        $chunk = fread($conn, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
        if (strpos($request, "\r\n\r\n") !== false) {
            break;
        }
        if (strlen($request) > 65536) {
            break;
        }
    }

    $request_line = strtok($request, "\r\n") ?: '';
    $parts = explode(' ', $request_line);
    $path = $parts[1] ?? '';

    // Strip the /wp-content/uploads/ prefix the proxy adds so the
    // path key matches our fixture names.
    $key = $path;
    $marker = '/wp-content/uploads/';
    $pos = strpos($key, $marker);
    if ($pos !== false) {
        $key = '/' . substr($key, $pos + strlen($marker));
    }

    [$status_line, $headers, $body] = scripted_response_for($key);

    $response = $status_line . "\r\n";
    foreach ($headers as $h) {
        $response .= $h . "\r\n";
    }
    $response .= "\r\n" . $body;
    fwrite($conn, $response);
    fclose($conn);
}

function scripted_response_for(string $key): array {
    switch ($key) {
        case '/200-with-content-type':
            $body = "JPEG-BYTES-HERE";
            return [
                'HTTP/1.1 200 OK',
                [
                    'Content-Type: image/jpeg',
                    'Content-Length: ' . strlen($body),
                    'Cache-Control: max-age=3600',
                    'Connection: close',
                ],
                $body,
            ];
        case '/200-no-forwardable':
            // 200 with body, but ONLY non-allowlisted headers — exposes
            // the bug where the proxy never emits status_header() before
            // streaming body bytes.
            $body = "OPAQUE-BYTES";
            return [
                'HTTP/1.1 200 OK',
                [
                    'Server: scripted',
                    'Connection: close',
                ],
                $body,
            ];
        case '/404-with-html':
            // 404 with a forwardable Content-Type: the proxy currently
            // sets $status_emitted=true via the on-Content-Type emit
            // but doesn't actually call status_header() because $http
            // is 404 (the `>= 200 && < 400` gate filters it out). Body
            // streams under PHP's default 200.
            $body = "<html>not found</html>";
            return [
                'HTTP/1.1 404 Not Found',
                [
                    'Content-Type: text/html',
                    'Content-Length: ' . strlen($body),
                    'Connection: close',
                ],
                $body,
            ];
        case '/5xx-no-body':
            return [
                'HTTP/1.1 502 Bad Gateway',
                [
                    'Connection: close',
                ],
                '',
            ];
        default:
            $body = 'unknown fixture: ' . $key;
            return [
                'HTTP/1.1 500 Internal Server Error',
                [
                    'Content-Type: text/plain',
                    'Content-Length: ' . strlen($body),
                    'Connection: close',
                ],
                $body,
            ];
    }
}
