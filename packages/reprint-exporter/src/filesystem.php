<?php

namespace Reprint\Exporter;

use InvalidArgumentException;

/**
 * Deduplicates and resolves a list of paths, discarding empty entries.
 *
 * @param array<int, mixed> $paths
 * @return list<string>
 */
function normalize_path_list(array $paths): array
{
    $normalized = [];
    foreach ($paths as $path) {
        if (!is_string($path)) {
            continue;
        }
        $path = trim($path);
        if ($path === "") {
            continue;
        }
        $real = realpath($path);
        $final = $real !== false ? $real : $path;
        $final = rtrim($final, "/");
        if ($final === "") {
            continue;
        }
        $normalized[$final] = true;
    }
    return array_keys($normalized);
}

/**
 * Walks parent directories upward from each start path to find WordPress installations.
 *
 * @param list<string> $start_paths
 * @return array{searched: list<string>, roots: list<array<string, mixed>>}
 */
function detect_wp_roots(array $start_paths): array
{
    $start_paths = normalize_path_list($start_paths);
    $seen = [];
    $roots = [];

    foreach ($start_paths as $start) {
        $current = $start;
        while ($current !== "" && !isset($seen[$current])) {
            $seen[$current] = true;
            $wp_load_path = $current . "/wp-load.php";
            $wp_config_path = $current . "/wp-config.php";
            $has_wp_load = file_exists($wp_load_path);
            $has_wp_config = file_exists($wp_config_path);
            $has_wp_content = is_dir($current . "/wp-content");
            if ($has_wp_load || $has_wp_config) {
                $roots[$current] = [
                    "path" => $current,
                    "wp_load" => $has_wp_load,
                    "wp_load_path" => $has_wp_load ? $wp_load_path : null,
                    "wp_config" => $has_wp_config,
                    "wp_config_path" => $has_wp_config ? $wp_config_path : null,
                    "wp_content" => $has_wp_content,
                ];
            }

            $parent = dirname($current);
            if ($parent === $current || $parent === "") {
                break;
            }
            $current = $parent;
        }
    }

    return [
        "searched" => array_keys($seen),
        "roots" => array_values($roots),
    ];
}

/**
 * Resolves directory paths from config.
 *
 * @param array<string, mixed> $config
 * @return list<string>
 */
function resolve_directories(array $config): array
{
    $directories_input = $config["directory"] ?? null;
    if (!$directories_input) {
        throw new InvalidArgumentException(
            "directory is required for files operation",
        );
    }

    $directories = [];
    $dir_list = is_array($directories_input)
        ? $directories_input
        : [$directories_input];

    foreach ($dir_list as $directory) {
        if (!is_string($directory)) {
            throw new InvalidArgumentException(
                "directory entries must be non-empty strings",
            );
        }
        $directory = trim($directory);
        assert_valid_path($directory, "directory entry");

        $real_directory = realpath($directory);
        if ($real_directory === false) {
            throw new InvalidArgumentException(
                "directory does not exist or is not accessible: {$directory}\n" .
                "Current working directory: " .
                getcwd() .
                "\n" .
                "Script directory: " .
                __DIR__
            );
        }

        $directories[] = $real_directory;
    }

    return $directories;
}

/**
 * Returns true when traversing $candidate would only duplicate or re-enter
 * one of the already-scheduled roots.
 *
 * @param list<string> $roots
 */
function should_skip_index_root(string $candidate, array $roots): bool
{
    foreach ($roots as $root) {
        if ($candidate === $root) {
            return true;
        }
        if ($candidate === "/" || str_starts_with($root . "/", $candidate . "/")) {
            return true;
        }
    }

    return false;
}
