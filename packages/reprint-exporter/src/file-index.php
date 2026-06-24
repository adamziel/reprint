<?php

namespace Reprint\Exporter;

/**
 * Encodes a file_index stack for JSON serialization.
 *
 * Paths may contain non-UTF8 bytes, so dir and after are base64-encoded.
 *
 * @param list<array{dir: string, after: ?string}> $stack
 * @return list<array{dir: string, after: ?string}>
 */
function encode_index_stack(array $stack): array
{
    $encoded = [];
    foreach ($stack as $frame) {
        $encoded[] = [
            "dir" => base64_encode($frame["dir"]),
            "after" => $frame["after"] !== null ? base64_encode($frame["after"]) : null,
        ];
    }
    return $encoded;
}

/**
 * Resolve "." and ".." segments in a path without resolving symlinks.
 */
function normalize_dot_segments(string $path): string
{
    $parts = explode("/", $path);
    $normalized = [];
    foreach ($parts as $p) {
        if ($p === "" || $p === ".") {
            if (empty($normalized)) {
                $normalized[] = "";
            }
            continue;
        }
        if ($p === "..") {
            if (count($normalized) > 1) {
                array_pop($normalized);
            }
            continue;
        }
        $normalized[] = $p;
    }
    return implode("/", $normalized);
}

/**
 * Given a path, returns all parent path components that are symlinks.
 *
 * @return list<array{path: string, ctime: int, size: int, type: string, target: string, intermediate: bool}>
 */
function find_parents_symlinks(string $absolute_path): array
{
    $entries = [];
    $parts = explode('/', $absolute_path);
    $current = "";
    foreach ($parts as $part) {
        if ($part === "") {
            $current = "/";
            continue;
        }
        $current = rtrim($current, "/") . "/" . $part;
        if (!@is_link($current)) {
            continue;
        }

        $target = @readlink($current);
        if ($target !== false && $target !== "") {
            $stat = @lstat($current);
            $entries[] = [
                "path" => $current,
                "ctime" => (int) ($stat["ctime"] ?? 0),
                "size" => 0,
                "type" => "link",
                "target" => $target,
                "intermediate" => true,
            ];
        }

        $real = @realpath($current);
        if ($real !== false) {
            $current = $real;
        }
    }
    return $entries;
}

/**
 * Resolves a symlink's target to a canonical path for the file index.
 *
 * @return array{target: ?string, intermediates: list<array<string, mixed>>}
 */
function resolve_symlink_target(string $path): array
{
    clearstatcache(true, $path);
    $resolved_target = @realpath($path);

    if (
        $resolved_target === false ||
        $resolved_target === $path ||
        !is_dir($resolved_target)
    ) {
        return ['target' => null, 'intermediates' => []];
    }

    $intermediates = [];
    $raw_target = @readlink($path);
    if ($raw_target !== false && $raw_target !== "") {
        if ($raw_target[0] !== "/") {
            $raw_target = dirname($path) . "/" . $raw_target;
        }
        $abs_raw = normalize_dot_segments($raw_target);
        if ($abs_raw !== "" && $abs_raw[0] === "/" && $abs_raw !== $resolved_target) {
            $intermediates = find_parents_symlinks($abs_raw);
        }
    }

    return ['target' => $resolved_target, 'intermediates' => $intermediates];
}

/**
 * Encodes batch items for JSON serialization, base64-encoding paths.
 *
 * @param list<array<string, mixed>> $batch_items
 * @return list<array<string, mixed>>
 */
function encode_index_batch(array $batch_items): array
{
    $encoded = [];
    foreach ($batch_items as $item) {
        $entry = [
            "path" => base64_encode($item["path"]),
            "ctime" => $item["ctime"],
            "size" => $item["size"],
            "type" => $item["type"],
        ];
        if (isset($item["target"])) {
            $entry["target"] = base64_encode($item["target"]);
        }
        if (!empty($item["intermediate"])) {
            $entry["intermediate"] = true;
        }
        $encoded[] = $entry;
    }
    return $encoded;
}

/**
 * Decides whether to gzip a file_fetch multipart response based on the path
 * list it will carry.
 *
 * @param array<int, mixed> $paths
 */
function file_fetch_paths_should_gzip(array $paths): bool
{
    if ($paths === []) {
        return false;
    }
    $any_compressible = false;
    foreach ($paths as $path) {
        if (!is_string($path)) {
            return false;
        }
        if ($any_compressible) {
            continue;
        }
        $ext = path_extension_compressibility($path);
        if ($ext === 'yes') {
            $any_compressible = true;
            continue;
        }
        if ($ext === 'unknown' && path_head_looks_like_text($path)) {
            $any_compressible = true;
        }
    }
    return $any_compressible;
}

/**
 * Returns true if a path's basename suggests text content gzip will shrink.
 */
function path_extension_is_compressible(string $path): bool
{
    return path_extension_compressibility($path) === 'yes';
}

/**
 * Three-state classifier for a path's extension.
 *
 * @return 'yes'|'no'|'unknown'
 */
function path_extension_compressibility(string $path): string
{
    $basename = basename($path);
    if ($basename === '') {
        return 'no';
    }
    if ($basename[0] === '.' && strpos($basename, '.', 1) === false) {
        return 'yes';
    }
    $ext = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
    if ($ext === '') {
        return 'yes';
    }

    static $compressible = [
        'php', 'phtml', 'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs',
        'css', 'scss', 'sass', 'less',
        'html', 'htm', 'xml', 'xsl', 'xslt', 'svg',
        'vue', 'astro', 'twig', 'mustache', 'hbs', 'liquid',
        'json', 'jsonl', 'yaml', 'yml', 'toml', 'csv', 'tsv',
        'sql', 'ini', 'conf', 'cfg', 'env', 'properties',
        'md', 'markdown', 'txt', 'log', 'rst', 'adoc',
        'pot', 'po', 'rss', 'atom', 'srt', 'vtt', 'webvtt',
        'sh', 'bash', 'patch', 'diff',
    ];
    if (in_array($ext, $compressible, true)) {
        return 'yes';
    }

    static $incompressible = [
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'tar',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif',
        'tiff', 'tif', 'bmp', 'ico',
        'mp3', 'm4a', 'aac', 'ogg', 'opus', 'flac', 'wav',
        'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'pdf', 'psd', 'sketch', 'fig', 'iso', 'dmg', 'mo', 'phar',
    ];
    if (in_array($ext, $incompressible, true)) {
        return 'no';
    }

    return 'unknown';
}

/**
 * Probes the first bytes of a file to decide if it looks like text.
 */
function path_head_looks_like_text(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return false;
    }
    $head = (string) fread($fp, 64);
    fclose($fp);
    if ($head === '') {
        return false;
    }
    if (strpos($head, "\x00") !== false) {
        return false;
    }
    if (preg_match('/[\x01-\x08\x0B\x0E-\x1F\x7F]/', $head)) {
        return false;
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($head, 'UTF-8')) {
        return false;
    }
    return true;
}

/**
 * Returns true if $path is a generated cache file, VCS/dev artifact, or OS junk.
 */
function path_is_default_skipped(string $path): bool
{
    $needle_haystack = '/' . trim($path, '/') . '/';

    static $cache_dirs = [
        '/wp-content/cache/',
        '/wp-content/upgrade/',
        '/wp-content/wpcomsh-cache/',
        '/wp-content/wflogs/',
    ];
    foreach ($cache_dirs as $needle) {
        if (strpos($needle_haystack, $needle) !== false) {
            return true;
        }
    }

    static $junk_components = [
        '.git', '.svn', '.hg', '.bzr',
        'node_modules',
        '.idea', '.vscode',
        '.cache', '.npm', '.yarn', '.pnpm-store',
    ];
    foreach ($junk_components as $needle) {
        if (strpos($needle_haystack, '/' . $needle . '/') !== false) {
            return true;
        }
    }

    $basename = basename($path);
    static $junk_basenames = [
        '.DS_Store', '._.DS_Store',
        'Thumbs.db', 'desktop.ini', 'ehthumbs.db',
    ];
    if (in_array($basename, $junk_basenames, true)) {
        return true;
    }

    if ($basename !== '' && $basename[0] === '.' && isset($basename[1]) && $basename[1] === '#') {
        return true;
    }
    if (strlen($basename) >= 3 && $basename[0] === '#' && substr($basename, -1) === '#') {
        return true;
    }
    if (preg_match('/(?:~|\.(?:swp|swo|swn|bak|orig|rej))$/', $basename) === 1) {
        return true;
    }

    return false;
}

/**
 * Returns the index of the first entry lexicographically after $after.
 *
 * @param list<string> $entries
 */
function position_after_entry(array $entries, string $after): int
{
    $low = 0;
    $high = count($entries);
    while ($low < $high) {
        $mid = (int) (($low + $high) / 2);
        $entry = $entries[$mid];
        if (strcmp($entry, $after) <= 0) {
            $low = $mid + 1;
        } else {
            $high = $mid;
        }
    }
    return $low;
}
