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
