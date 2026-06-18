<?php

namespace Reprint\Importer\Session;

final class ExportDirectoryResolver
{
    /**
     * Extract root directories from preflight wp_detect data.
     *
     * @param callable|null $audit Receives log message strings.
     */
    public static function root_directories_from_preflight(
        array $preflight_data,
        ?callable $audit = null
    ): array {
        $roots = $preflight_data["wp_detect"]["roots"] ?? [];
        if (!is_array($roots) || empty($roots)) {
            return [];
        }

        $dirs = [];
        foreach ($roots as $root) {
            $path = $root["path"] ?? null;
            if (is_string($path) && $path !== "") {
                $dirs[] = rtrim($path, "/");
            }
        }

        $dirs = array_values(array_unique($dirs));
        if (!empty($dirs) && $audit !== null) {
            $audit(
                "DIRECTORY AUTO-DETECT | from preflight wp_detect.roots: " .
                implode(", ", $dirs),
            );
        }

        return $dirs;
    }

    /**
     * Build the list of directories the server should traverse.
     *
     * Starts from the wp_detect roots and adds paths that live outside those
     * roots, such as WP_CONTENT_DIR, document_root, and auto prepend/append
     * directories.
     *
     * @param callable|null $audit Receives log message strings.
     */
    public static function export_directories(
        array $preflight_data,
        ?string $extra_directory = null,
        ?callable $audit = null
    ): array {
        $dirs = self::root_directories_from_preflight($preflight_data, $audit);
        if (empty($dirs)) {
            return [];
        }

        $extra_paths = [
            "document_root" => rtrim($preflight_data["runtime"]["document_root"] ?? "", "/"),
            "content_dir" => rtrim($preflight_data["database"]["wp"]["paths_urls"]["content_dir"] ?? "", "/"),
        ];

        if ($extra_directory !== null && $extra_directory !== "") {
            $extra_paths["extra_directory"] = rtrim($extra_directory, "/");
        }

        $ini_all = $preflight_data["runtime"]["ini_get_all"] ?? [];
        foreach (["auto_prepend_file", "auto_append_file"] as $ini_key) {
            $ini_path = $ini_all[$ini_key] ?? "";
            if (is_string($ini_path) && $ini_path !== "" && $ini_path[0] === "/") {
                $ini_dir = rtrim(dirname($ini_path), "/");
                if ($ini_dir !== "" && $ini_dir !== "/") {
                    $extra_paths[$ini_key] = $ini_dir;
                }
            }
        }

        foreach ($extra_paths as $label => $path) {
            if ($path === "") {
                continue;
            }

            if (self::is_covered_by_any_root($path, $dirs)) {
                continue;
            }

            $dirs[] = $path;
            if ($audit !== null) {
                $audit(
                    "DIRECTORY AUTO-DETECT | adding {$label} outside roots: " .
                    $path,
                );
            }
        }

        return $dirs;
    }

    private static function is_covered_by_any_root(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if (
                $path === $root ||
                str_starts_with($path, $root . "/")
            ) {
                return true;
            }
        }

        return false;
    }
}
