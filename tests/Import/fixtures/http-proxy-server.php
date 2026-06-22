<?php
/**
 * Minimal HTTP forward proxy used by CurlProxyFromEnvTest.
 *
 * Binds to 127.0.0.1 on an ephemeral port, prints the port number to
 * stdout followed by a newline (the parent test reads that line to
 * discover where to send traffic), then accepts connections, appends
 * each incoming request line to the log file, and responds with a
 * fixed 200. Runs until the parent kills it.
 *
 * Usage: php http-proxy-server.php <log-file>
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: http-proxy-server.php <log-file>\n");
    exit(1);
}

$log_file = $argv[1];

$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$sock) {
    fwrite(STDOUT, "SKIP bind failed: {$errstr}\n");
    fflush(STDOUT);
    exit(0);
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

    $request_line = strtok($request, "\r\n");
    if ($request_line === false) {
        $request_line = '(empty)';
    }
    file_put_contents($log_file, $request_line . "\n", FILE_APPEND);

    $body = 'proxied-ok';
    $response =
        "HTTP/1.1 200 OK\r\n" .
        "Content-Type: text/plain\r\n" .
        "Content-Length: " . strlen($body) . "\r\n" .
        "Connection: close\r\n" .
        "\r\n" .
        $body;
    fwrite($conn, $response);
    fclose($conn);
}
