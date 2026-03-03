<?php

/**
 * Scans SQL statements for FROM_BASE64('...') expressions and provides
 * methods to extract and replace their decoded values.
 *
 * The SQL dump format encodes all string values as FROM_BASE64('...') and
 * JSON column values as CONVERT(FROM_BASE64('...') USING utf8mb4). Since
 * base64 alphabet (A-Za-z0-9+/=) never contains a single quote, the
 * closing ') is an unambiguous terminator.
 */
class Base64ValueScanner
{
    private const FROM_BASE64_PREFIX = "FROM_BASE64('";
    private const FROM_BASE64_PREFIX_LEN = 13; // strlen("FROM_BASE64('")
    private const CONVERT_SUFFIX = " USING utf8mb4)";
    private const CONVERT_SUFFIX_LEN = 15; // strlen(" USING utf8mb4)")

    /**
     * Scan a SQL statement for FROM_BASE64('...') expressions.
     *
     * Returns array of matches, each containing:
     *   'offset'  => int    Position of the full expression in $sql
     *   'length'  => int    Length of the full expression (including any CONVERT wrapper)
     *   'value'   => string The base64-decoded string value
     *   'is_json' => bool   Whether wrapped in CONVERT(... USING utf8mb4)
     *
     * @param string $sql The SQL statement to scan.
     * @return array<int, array{offset: int, length: int, value: string, is_json: bool}>
     */
    public static function scan(string $sql): array
    {
        $results = [];
        $pos = 0;
        $sql_len = strlen($sql);

        while ($pos < $sql_len) {
            // Look for FROM_BASE64(' starting from current position
            $fb_pos = strpos($sql, self::FROM_BASE64_PREFIX, $pos);
            if ($fb_pos === false) {
                break;
            }

            // Find the closing ')
            $payload_start = $fb_pos + self::FROM_BASE64_PREFIX_LEN;
            $close_pos = strpos($sql, "')", $payload_start);
            if ($close_pos === false) {
                // Malformed — skip past this match
                $pos = $payload_start;
                continue;
            }

            // Extract and decode the base64 payload
            $b64_payload = substr($sql, $payload_start, $close_pos - $payload_start);
            $decoded = base64_decode($b64_payload, true);
            if ($decoded === false) {
                // Invalid base64 — skip
                $pos = $close_pos + 2;
                continue;
            }

            // Check if preceded by CONVERT( to detect JSON wrapper
            $is_json = false;
            $expr_offset = $fb_pos;
            $expr_end = $close_pos + 2; // past the ')

            // CONVERT(FROM_BASE64('...') USING utf8mb4)
            $convert_check_start = $fb_pos - 8; // strlen("CONVERT(") = 8
            if (
                $convert_check_start >= 0 &&
                substr($sql, $convert_check_start, 8) === "CONVERT("
            ) {
                // Verify the USING utf8mb4) suffix follows
                if (
                    $expr_end + self::CONVERT_SUFFIX_LEN <= $sql_len &&
                    substr($sql, $expr_end, self::CONVERT_SUFFIX_LEN) === self::CONVERT_SUFFIX
                ) {
                    $is_json = true;
                    $expr_offset = $convert_check_start;
                    $expr_end = $expr_end + self::CONVERT_SUFFIX_LEN;
                }
            }

            $results[] = [
                'offset' => $expr_offset,
                'length' => $expr_end - $expr_offset,
                'value' => $decoded,
                'is_json' => $is_json,
            ];

            $pos = $expr_end;
        }

        return $results;
    }

    /**
     * Replace a base64 value at the given offset.
     *
     * Handles both FROM_BASE64('...') and CONVERT(FROM_BASE64('...') USING utf8mb4).
     * Returns the modified SQL.
     *
     * @param string $sql       The SQL statement.
     * @param int    $offset    Start offset of the expression.
     * @param int    $length    Length of the expression.
     * @param string $new_value The new decoded value to encode.
     * @param bool   $is_json   Whether to wrap in CONVERT(... USING utf8mb4).
     * @return string The modified SQL.
     */
    public static function replace(string $sql, int $offset, int $length, string $new_value, bool $is_json): string
    {
        $encoded = base64_encode($new_value);

        if ($is_json) {
            $replacement = "CONVERT(FROM_BASE64('" . $encoded . "') USING utf8mb4)";
        } else {
            $replacement = "FROM_BASE64('" . $encoded . "')";
        }

        return substr($sql, 0, $offset) . $replacement . substr($sql, $offset + $length);
    }
}
