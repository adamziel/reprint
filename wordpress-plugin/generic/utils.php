<?php
/**
 * Shared utility functions used by both export.php and import.php.
 */

// Polyfill for PHP 7.4 which lacks str_starts_with().
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/**
 * Parse a human-readable size string (e.g. "16M", "1G", "512K") into bytes.
 * Accepts plain integers as well.
 */
function parse_size(string $value): int
{
    $value = trim($value);
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGkmg])?[Bb]?$/', $value, $m)) {
        throw new InvalidArgumentException(
            "Invalid size value: '{$value}'. Use a number optionally followed by K, M, or G (e.g. 64M).",
        );
    }
    $num = (float) $m[1];
    $suffix = strtoupper($m[2] ?? "");
    switch ($suffix) {
        case "K":
            return (int) ($num * 1024);
        case "M":
            return (int) ($num * 1024 * 1024);
        case "G":
            return (int) ($num * 1024 * 1024 * 1024);
        default:
            return (int) $num;
    }
}

/**
 * Throws on json_encode failure instead of returning false.
 *
 * Do NOT use inside error/shutdown handlers — those need hardcoded fallback strings.
 */
function json_encode_or_throw($value, int $flags = 0): string
{
    $json = json_encode($value, $flags);
    if ($json === false) {
        throw new RuntimeException("json_encode failed: " . json_last_error_msg());
    }
    return $json;
}

/**
 * Resolve ".." and "." segments in a path without touching the filesystem.
 *
 * Unlike realpath(), this works on paths that don't exist yet.
 */
function normalize_path(string $path): string
{
    $parts = explode("/", $path);
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === "" || $part === ".") {
            continue;
        }
        if ($part === "..") {
            array_pop($resolved);
        } else {
            $resolved[] = $part;
        }
    }
    return "/" . implode("/", $resolved);
}

/**
 * Returns true when $path is equal to $root or strictly under it.
 */
function path_is_within_root(string $path, string $root): bool
{
    return $path === $root || str_starts_with($path, $root . "/");
}
