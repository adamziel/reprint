<?php

namespace Reprint\Importer\Filesystem;

use RuntimeException;

final class FlatDocumentRootBuilder
{
    /**
     * Build a conventional WordPress document root from an imported filesystem tree.
     */
    public static function build(
        string $fs_root,
        string $flatten_to,
        array $preflight,
        bool $force = false,
        ?callable $audit = null
    ): array {
        $flatten_to = rtrim($flatten_to, "/");

        if (!is_dir($fs_root)) {
            throw new RuntimeException(
                "Fs root does not exist: {$fs_root}",
            );
        }

        $paths_urls = $preflight["database"]["wp"]["paths_urls"] ?? null;
        $abspath = null;
        $wp_admin_path = null;
        $wp_includes_path = null;
        $content_dir = null;
        $plugins_dir = null;
        $mu_plugins_dir = null;
        $uploads_basedir = null;

        if (is_array($paths_urls)) {
            $abspath = self::clean_path($paths_urls["abspath"] ?? null);
            $wp_admin_path = self::clean_path($paths_urls["wp_admin_path"] ?? null);
            $wp_includes_path = self::clean_path($paths_urls["wp_includes_path"] ?? null);
            $content_dir = self::clean_path($paths_urls["content_dir"] ?? null);
            $plugins_dir = self::clean_path($paths_urls["plugins_dir"] ?? null);
            $mu_plugins_dir = self::clean_path($paths_urls["mu_plugins_dir"] ?? null);
            $uploads_basedir = self::clean_path(
                $paths_urls["uploads"]["basedir"] ?? null,
            );
        }

        if ($abspath === null) {
            $roots = $preflight["wp_detect"]["roots"] ?? [];
            if (!empty($roots)) {
                $abspath = self::clean_path($roots[0]["path"] ?? null);
            }
        }

        if ($abspath === null) {
            throw new RuntimeException(
                "Cannot determine WordPress ABSPATH from preflight data. " .
                    "Run preflight first to detect the WordPress installation.",
            );
        }

        $local_abspath = $fs_root . $abspath;
        if (!is_dir($local_abspath)) {
            throw new RuntimeException(
                "WordPress ABSPATH directory not found in fs root: {$local_abspath} " .
                    "(remote ABSPATH: {$abspath}). Has the file sync completed?",
            );
        }

        $local_wp_admin = $wp_admin_path !== null
            ? $fs_root . $wp_admin_path
            : null;
        $local_wp_includes = $wp_includes_path !== null
            ? $fs_root . $wp_includes_path
            : null;
        $local_content_dir = $content_dir !== null
            ? $fs_root . $content_dir
            : null;
        $local_plugins_dir = $plugins_dir !== null
            ? $fs_root . $plugins_dir
            : null;
        $local_mu_plugins_dir = $mu_plugins_dir !== null
            ? $fs_root . $mu_plugins_dir
            : null;
        $local_uploads_basedir = $uploads_basedir !== null
            ? $fs_root . $uploads_basedir
            : null;

        $wp_admin_detached = $wp_admin_path !== null
            && $wp_admin_path !== $abspath . "/wp-admin";
        $wp_includes_detached = $wp_includes_path !== null
            && $wp_includes_path !== $abspath . "/wp-includes";
        $content_detached = $content_dir !== null
            && strpos($content_dir, $abspath . "/") !== 0;
        $plugins_detached = $plugins_dir !== null
            && $content_dir !== null
            && strpos($plugins_dir, $content_dir . "/") !== 0;
        $mu_plugins_detached = $mu_plugins_dir !== null
            && $content_dir !== null
            && strpos($mu_plugins_dir, $content_dir . "/") !== 0;
        $uploads_detached = $uploads_basedir !== null
            && $content_dir !== null
            && strpos($uploads_basedir, $content_dir . "/") !== 0;

        $need_exploded_content =
            $plugins_detached || $mu_plugins_detached || $uploads_detached;

        if (!is_dir($flatten_to)) {
            if (!mkdir($flatten_to, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create flatten-to directory: {$flatten_to}",
                );
            }
            self::audit(
                $audit,
                "FLAT-DOCUMENT-ROOT | Created directory: {$flatten_to}",
            );
        }

        self::audit(
            $audit,
            sprintf(
                "FLAT-DOCUMENT-ROOT | abspath=%s wp_admin=%s wp_includes=%s " .
                    "content_dir=%s content_detached=%s " .
                    "plugins_detached=%s mu_plugins_detached=%s uploads_detached=%s",
                $abspath,
                $wp_admin_path ?? "(from abspath)",
                $wp_includes_path ?? "(from abspath)",
                $content_dir ?? "(not set)",
                $content_detached ? "yes" : "no",
                $plugins_detached ? "yes" : "no",
                $mu_plugins_detached ? "yes" : "no",
                $uploads_detached ? "yes" : "no",
            ),
        );

        $created = 0;
        $refreshed = 0;
        $forced = 0;

        $skip_from_abspath = [];
        if ($content_detached || $need_exploded_content) {
            $skip_from_abspath["wp-content"] = true;
        }
        if ($wp_admin_detached) {
            $skip_from_abspath["wp-admin"] = true;
        }
        if ($wp_includes_detached) {
            $skip_from_abspath["wp-includes"] = true;
        }

        $entries = @scandir($local_abspath);
        if ($entries === false) {
            throw new RuntimeException(
                "Failed to scan ABSPATH directory: {$local_abspath}",
            );
        }

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            if (isset($skip_from_abspath[$entry])) {
                self::audit(
                    $audit,
                    "FLAT-DOCUMENT-ROOT | Skipping '{$entry}' from ABSPATH " .
                        "(will be sourced from resolved location)",
                );
                continue;
            }

            self::place_symlink(
                $local_abspath . "/" . $entry,
                $flatten_to . "/" . $entry,
                $force,
                $created,
                $refreshed,
                $forced,
                $audit,
            );
        }

        if ($wp_admin_detached && $local_wp_admin !== null && is_dir($local_wp_admin)) {
            self::place_symlink(
                $local_wp_admin,
                $flatten_to . "/wp-admin",
                $force,
                $created,
                $refreshed,
                $forced,
                $audit,
            );
        }
        if ($wp_includes_detached && $local_wp_includes !== null && is_dir($local_wp_includes)) {
            self::place_symlink(
                $local_wp_includes,
                $flatten_to . "/wp-includes",
                $force,
                $created,
                $refreshed,
                $forced,
                $audit,
            );
        }

        $wp_config_in_flatten = $flatten_to . "/wp-config.php";
        if (!file_exists($wp_config_in_flatten)) {
            $parent_of_abspath = dirname($abspath);
            $local_parent_wp_config = $fs_root . $parent_of_abspath . "/wp-config.php";
            if (file_exists($local_parent_wp_config)) {
                self::place_symlink(
                    $local_parent_wp_config,
                    $wp_config_in_flatten,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                    $audit,
                );
                self::audit(
                    $audit,
                    "FLAT-DOCUMENT-ROOT | Symlinked wp-config.php from ABSPATH parent: " .
                        "{$parent_of_abspath}/wp-config.php",
                );
            }
        }

        if ($need_exploded_content && $local_content_dir !== null) {
            $wp_content_target = $flatten_to . "/wp-content";
            self::ensure_real_directory(
                $wp_content_target,
                $force,
                $forced,
                $audit,
            );

            if (is_dir($local_content_dir)) {
                $content_entries = @scandir($local_content_dir) ?: [];
                $skip_from_content = [];
                if ($plugins_detached) {
                    $skip_from_content["plugins"] = true;
                }
                if ($mu_plugins_detached) {
                    $skip_from_content["mu-plugins"] = true;
                }
                if ($uploads_detached) {
                    $skip_from_content["uploads"] = true;
                }

                foreach ($content_entries as $entry) {
                    if ($entry === "." || $entry === "..") {
                        continue;
                    }
                    if (isset($skip_from_content[$entry])) {
                        continue;
                    }
                    self::place_symlink(
                        $local_content_dir . "/" . $entry,
                        $wp_content_target . "/" . $entry,
                        $force,
                        $created,
                        $refreshed,
                        $forced,
                        $audit,
                    );
                }
            }

            if ($plugins_detached && is_dir($local_plugins_dir)) {
                self::place_symlink(
                    $local_plugins_dir,
                    $wp_content_target . "/plugins",
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                    $audit,
                );
            }
            if ($mu_plugins_detached && is_dir($local_mu_plugins_dir)) {
                self::place_symlink(
                    $local_mu_plugins_dir,
                    $wp_content_target . "/mu-plugins",
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                    $audit,
                );
            }
            if ($uploads_detached && is_dir($local_uploads_basedir)) {
                self::place_symlink(
                    $local_uploads_basedir,
                    $wp_content_target . "/uploads",
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                    $audit,
                );
            }
        } elseif ($content_detached && $local_content_dir !== null) {
            if (is_dir($local_content_dir)) {
                self::place_symlink(
                    $local_content_dir,
                    $flatten_to . "/wp-content",
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                    $audit,
                );
            } else {
                self::audit(
                    $audit,
                    "FLAT-DOCUMENT-ROOT | Warning: content_dir not found in fs root: " .
                        "{$local_content_dir} (remote: {$content_dir})",
                    true,
                );
            }
        }

        self::audit(
            $audit,
            sprintf(
                "FLAT-DOCUMENT-ROOT | Complete: %d created, %d refreshed, %d force-replaced",
                $created,
                $refreshed,
                $forced,
            ),
            true,
        );

        return [
            "status" => "complete",
            "flatten_to" => $flatten_to,
            "fs_root" => $fs_root,
            "abspath" => $abspath,
            "wp_admin_path" => $wp_admin_path,
            "wp_includes_path" => $wp_includes_path,
            "content_dir" => $content_dir,
            "content_detached" => $content_detached,
            "created" => $created,
            "refreshed" => $refreshed,
            "force_replaced" => $forced,
        ];
    }

    private static function clean_path($value): ?string
    {
        return PathUtils::clean_path_value($value);
    }

    private static function place_symlink(
        string $source,
        string $target,
        bool $force,
        int &$created,
        int &$refreshed,
        int &$forced,
        ?callable $audit
    ): void {
        $abs_source = realpath($source);
        if ($abs_source === false) {
            $parent_real = realpath(dirname($source));
            if ($parent_real === false) {
                throw new RuntimeException(
                    "Cannot resolve source path for symlink: {$source}",
                );
            }
            $abs_source = $parent_real . "/" . basename($source);
        }

        $target_parent_real = realpath(dirname($target));
        if ($target_parent_real === false) {
            throw new RuntimeException(
                "Cannot resolve target parent directory: " . dirname($target),
            );
        }

        $link_value = PathUtils::relative_path($target_parent_real, $abs_source);

        if (is_link($target)) {
            $current_link_target = readlink($target);
            if ($current_link_target === $link_value) {
                $refreshed++;
                return;
            }

            unlink($target);
            self::audit(
                $audit,
                "FLAT-DOCUMENT-ROOT | Refreshed symlink: {$target} (was -> {$current_link_target})",
            );
            if (!symlink($link_value, $target)) {
                throw new RuntimeException(
                    "Failed to create symlink: {$target} -> {$link_value}",
                );
            }
            $refreshed++;
            return;
        }

        if (file_exists($target)) {
            if (!$force) {
                throw new RuntimeException(
                    "Cannot create symlink at {$target}: a non-symlink " .
                        (is_dir($target) ? "directory" : "file") .
                        " already exists. Use --force to remove it and replace with a symlink.",
                );
            }

            $type = is_dir($target) ? "directory" : "file";
            self::audit(
                $audit,
                "FLAT-DOCUMENT-ROOT FORCE | Removing conflicting {$type}: {$target}",
                true,
            );

            if (is_dir($target)) {
                self::remove_directory_recursive($target);
            } else {
                unlink($target);
            }
            $forced++;
        }

        if (!symlink($link_value, $target)) {
            throw new RuntimeException(
                "Failed to create symlink: {$target} -> {$link_value}",
            );
        }
        self::audit(
            $audit,
            "FLAT-DOCUMENT-ROOT | Created symlink: {$target} -> {$link_value}",
        );
        $created++;
    }

    private static function ensure_real_directory(
        string $path,
        bool $force,
        int &$forced,
        ?callable $audit
    ): void {
        if (is_link($path)) {
            if (!$force) {
                throw new RuntimeException(
                    "Cannot create real directory at {$path}: a symlink already " .
                        "exists. Use --force to remove it.",
                );
            }
            self::audit(
                $audit,
                "FLAT-DOCUMENT-ROOT FORCE | Replacing symlink with real directory: {$path}",
                true,
            );
            unlink($path);
            $forced++;
        }

        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create directory: {$path}",
                );
            }
            self::audit(
                $audit,
                "FLAT-DOCUMENT-ROOT | Created directory: {$path}",
            );
        }
    }

    private static function remove_directory_recursive(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            throw new RuntimeException("Failed to scan directory for removal: {$dir}");
        }
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $path = $dir . "/" . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::remove_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private static function audit(?callable $audit, string $message, bool $to_console = true): void
    {
        if ($audit === null) {
            return;
        }

        $audit($message, $to_console);
    }
}
