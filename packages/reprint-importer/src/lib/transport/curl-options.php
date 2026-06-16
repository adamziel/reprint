<?php

/**
 * If the ALL_PROXY environment variable is set, apply it to the cURL
 * handle via CURLOPT_PROXY.
 *
 * libcurl does inspect ALL_PROXY on its own, but only when curl is
 * built against a libc that exports the env var and when no one has
 * unset it in the PHP process. Some SAPIs and managed runtimes strip
 * the environment before PHP starts, so setting CURLOPT_PROXY
 * explicitly makes the behavior deterministic across hosts.
 *
 * Empty values are ignored — an explicit empty ALL_PROXY is the
 * shell idiom for "no proxy".
 */
function reprint_apply_curl_proxy_from_env($ch): ?string {
    $proxy = getenv('ALL_PROXY');
    if (!is_string($proxy) || $proxy === '') {
        return null;
    }
    curl_setopt($ch, CURLOPT_PROXY, $proxy);
    return $proxy;
}

/**
 * Mirror PHP's `openssl.cafile` ini value onto the cURL handle as
 * `CURLOPT_CAINFO` — workaround for WordPress Playground, where the
 * WASM curl build doesn't honor `curl.cainfo` / `openssl.cafile`
 * (both are PHP_INI_SYSTEM, and curl can't see PHP-level ini values
 * anyway). Reading the ini value in PHP and passing the path via a
 * per-handle option is the only knob that works there.
 *
 * No-op when `openssl.cafile` is empty (the typical Linux case —
 * curl uses its compile-time default). When it's set and points at
 * a readable file, we mirror it; if `curl.cainfo` was also set to
 * the same path PHP's curl extension already applied it to the
 * handle, so the per-handle setopt is a benign re-set.
 *
 * TODO: remove once https://github.com/WordPress/wordpress-playground
 * resolves `openssl.cafile` natively inside its WASM curl bundle.
 */
function reprint_apply_curl_ca_bundle($ch): ?string {
    // Insecure-TLS escape hatch for environments where neither
    // CURLOPT_CAINFO nor any other knob persuades the TLS layer to
    // trust the source's cert — notably WordPress Playground in the
    // browser, where networking goes through a JS TLS library running
    // inside the page (not libcurl's TLS) and that library may have
    // a CA store that pre-dates the Let's Encrypt intermediate the
    // source's cert is signed by. The wizard sets this env when it
    // hands off; we never set it for any other caller.
    if (getenv('REPRINT_INSECURE_TLS') === '1') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        return '(insecure)';
    }

    $cafile = (string) ini_get('openssl.cafile');
    if ($cafile === '' || !is_readable($cafile)) {
        return null;
    }
    curl_setopt($ch, CURLOPT_CAINFO, $cafile);
    return $cafile;
}
