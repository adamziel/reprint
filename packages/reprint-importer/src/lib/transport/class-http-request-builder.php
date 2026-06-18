<?php

namespace Reprint\Importer\Transport;

final class HttpRequestBuilder
{
    /**
     * User-Agent strings to try during preflight, in order of preference.
     */
    public const USER_AGENTS = [
        "Reprint/1.0",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0",
    ];

    public static function url(
        string $base_url,
        string $endpoint,
        ?string $cursor,
        array $params = []
    ): string {
        $separator = strpos($base_url, "?") === false ? "?" : "&";

        $params["endpoint"] = $endpoint;
        if ($cursor) {
            // Also include cursor in query params as a fallback when headers are stripped.
            $params["cursor"] = $cursor;
        }
        $params["_cache_bust"] = time() . "-" . rand(0, 999999);

        return $base_url . $separator . http_build_query($params);
    }

    public static function base_headers(string $accept, ?string $user_agent = null): array
    {
        $ua = $user_agent ?? self::USER_AGENTS[0];

        return [
            "User-Agent: {$ua}",
            "Accept: {$accept}",
            "Accept-Language: en-US,en;q=0.9",
            "Accept-Encoding: gzip, deflate",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Connection: keep-alive",
        ];
    }
}
