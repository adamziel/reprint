<?php
/**
 * Streams a missing /wp-content/uploads/* request from the source site
 * to the visitor. Used by Playground's mu-plugin and by tests.
 *
 * Behaviour:
 *
 *   - Returns silently (no exit) if the request isn't for an uploads
 *     path or the requested file already exists locally — WordPress
 *     handles those itself.
 *   - Forwards the source's status code verbatim, including 4xx/5xx,
 *     so a real "Not Found" on the source surfaces as a real 404 on
 *     the imported site (rather than masquerading as 200 OK with the
 *     source's 404 body underneath).
 *   - Defers `status_header()` and `header()` calls until the first
 *     body byte is about to be written, so a no-content-type response
 *     (or a 4xx with no forwardable header) still gets a sane status
 *     emitted before any output hits the wire.
 *   - Falls back to a 502 plaintext error only when curl never wrote
 *     a chunk (transport failure or empty body).
 *   - Always exits when it handles the request, so WP's normal 404
 *     template doesn't run on top.
 *
 * The headers we forward are a small allowlist (Content-Type,
 * Content-Length, Cache-Control, Expires, ETag, Last-Modified) — we
 * deliberately don't pass Transfer-Encoding/Connection/Server through.
 *
 * Test seam: `$emitters` lets a test inject closures for status_header,
 * header, body output, and exit. Production passes nothing and the
 * handler talks to the real WP / PHP globals.
 */
if (!function_exists('reprint_playground_uploads_proxy_handle_request')) {
    /**
     * @param array<string,callable>|null $emitters
     *        Optional override map: 'status' (callable(int)), 'header'
     *        (callable(string)), 'body' (callable(string)), 'exit'
     *        (callable()). Defaults are status_header / header / echo /
     *        exit.
     */
    function reprint_playground_uploads_proxy_handle_request(string $source_origin, ?array $emitters = null): void {
        $emit_status = ($emitters['status'] ?? null) ?: function ($code) { status_header((int) $code); };
        $emit_header = ($emitters['header'] ?? null) ?: function ($line) { header((string) $line); };
        $emit_body = ($emitters['body'] ?? null) ?: function ($chunk) { echo $chunk; };
        $emit_exit = ($emitters['exit'] ?? null) ?: function () { exit; };

        $req = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($req, '/wp-content/uploads/') === false) return;
        $path = parse_url($req, PHP_URL_PATH) ?: '';
        $pos = strpos($path, '/wp-content/uploads/');
        if ($pos === false) return;
        $rel = substr($path, $pos);
        $local = WP_CONTENT_DIR . substr($rel, strlen('/wp-content'));
        if (file_exists($local)) return;

        $remote = $source_origin . $rel;
        $ch = curl_init($remote);
        if ($ch === false) {
            $emit_status(502);
            $emit_header('Content-Type: text/plain');
            $emit_body('curl_init failed');
            $emit_exit();
            return;
        }

        // Buffer the source's headers (and the status that goes with
        // them) until the moment we're about to write the first body
        // byte. Calling status_header / header from HEADERFUNCTION
        // emits them too eagerly: a 4xx with a forwardable
        // Content-Type would lock in a stale 200 OK, and a 200 with no
        // forwardable headers at all would leave $status_emitted
        // unset and trip the post-curl_exec fallback after the body
        // has already streamed.
        $source_status = 0;
        $forwardable_headers = [];
        $body_started = false;
        $send_headers = function () use (
            &$body_started,
            &$source_status,
            &$forwardable_headers,
            $emit_status,
            $emit_header
        ) {
            if ($body_started) return;
            $body_started = true;
            $emit_status($source_status > 0 ? $source_status : 502);
            foreach ($forwardable_headers as $line) {
                $emit_header($line);
            }
        };

        try {
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (
                    &$source_status,
                    &$forwardable_headers
                ) {
                    $len = strlen($header);
                    $line = trim($header);
                    if ($line === '') return $len;
                    // Capture the most recent status line. libcurl can
                    // call HEADERFUNCTION multiple times when a
                    // redirect chain runs (one block per response);
                    // resetting $forwardable_headers on each new status
                    // line keeps us in sync with the final response.
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $source_status = (int) $m[1];
                        $forwardable_headers = [];
                        return $len;
                    }
                    // Forward content-type, content-length, cache-control,
                    // expires, etag, last-modified — skip everything else
                    // so we don't leak transfer-encoding / connection /
                    // server identity from the source.
                    $lower = strtolower($line);
                    foreach (array('content-type:','content-length:','cache-control:','expires:','etag:','last-modified:') as $prefix) {
                        if (strpos($lower, $prefix) === 0) {
                            $forwardable_headers[] = $line;
                            break;
                        }
                    }
                    return $len;
                },
                // Stream each libcurl chunk straight to the wire so a
                // 100MB video doesn't sit in PHP memory. Status +
                // headers are flushed exactly once, on the first chunk.
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($send_headers, $emit_body) {
                    $send_headers();
                    $emit_body($chunk);
                    return strlen($chunk);
                },
            ));
            $ok = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            if ($ok === false || !$body_started) {
                if (!$body_started) {
                    // No body chunk arrived. Either the transport failed
                    // outright (curl_exec returned false), the source
                    // sent a body-less response (HEAD-style 304/204), or
                    // a status with no body. Forward whatever status
                    // libcurl told us — but if we don't have one, fall
                    // back to 502 since the proxy itself failed.
                    $body_started = true;
                    if ($http > 0) {
                        $emit_status($http);
                        foreach ($forwardable_headers as $line) {
                            $emit_header($line);
                        }
                    } else {
                        $emit_status(502);
                        $emit_header('Content-Type: text/plain');
                        $emit_body('Upload not available locally and source fetch failed');
                        if ($err !== '') {
                            $emit_body(': ' . $err);
                        }
                    }
                }
            }
        } finally {
            curl_close($ch);
        }
        $emit_exit();
    }
}
