<?php

namespace Reprint\Exporter\Command;

use Exception;
use InvalidArgumentException;
use PDO;
use Throwable;
use function Reprint\Exporter\parse_size;

final class PreflightCommand extends ExportCommand
{
    public function execute(array $config): array
    {
        // -- Resolve filesystem roots --
        // Determine which directories to scan: either from the client-provided
        // "directory" config, or by auto-detecting from cwd/DOCUMENT_ROOT/__DIR__.
        $directories = [];
        $dir_error = null;
        $has_root_input = array_key_exists("directory", $config) && $config["directory"] !== null;
        if ($has_root_input) {
            try {
                $directories = resolve_directories($config);
            } catch (Exception $e) {
                $dir_error = $e->getMessage();
            }
        }
    
        $search_roots = [];
        if (!empty($directories)) {
            $search_roots = $directories;
        } else {
            $filtered = array_filter(
                [
                    getcwd() ?: null,
                    $_SERVER["DOCUMENT_ROOT"] ?? null,
                    isset($_SERVER["SCRIPT_FILENAME"])
                        ? dirname($_SERVER["SCRIPT_FILENAME"])
                        : null,
                    dirname(__DIR__),
                ],
                fn($value) => $value !== null && $value !== "",
            );
            $search_roots = normalize_path_list($filtered);
        }
    
        // -- Detect WordPress installations --
        // Walk parent directories to find wp-load.php / wp-config.php.
        $wp_detect = detect_wp_roots($search_roots);
        $detected_root_paths = [];
        foreach ($wp_detect["roots"] as $root) {
            if (!empty($root["path"])) {
                $detected_root_paths[] = $root["path"];
            }
        }
        $detected_root_paths = normalize_path_list($detected_root_paths);
    
        $wp_load_path = null;
        foreach ($wp_detect["roots"] as $root) {
            if (!empty($root["wp_load_path"]) && is_readable($root["wp_load_path"])) {
                $wp_load_path = $root["wp_load_path"];
                break;
            }
        }
        $preflight_error = null;
        if (!$has_root_input && $wp_load_path === null) {
            $preflight_error =
                "wp-load.php not found and no root directories were provided";
        }
    
        $scan_roots = !empty($directories) ? $directories : $detected_root_paths;
        if (empty($scan_roots)) {
            $scan_roots = $search_roots;
        }
        $scan_roots = normalize_path_list($scan_roots);
    
        $wp_scan_roots = normalize_path_list(
            array_merge($scan_roots, $detected_root_paths),
        );
    
        // -- Probe each directory --
        // Check accessibility, read .htaccess files, and collect disk space info.
        $dir_checks = [];
        $htaccess_files = [];
        $wp_paths = [];
        if (!empty($scan_roots)) {
            foreach ($scan_roots as $dir) {
                $exists = is_dir($dir);
                $readable = $exists && is_readable($dir);
                $openable = false;
                $disk_free = null;
                $disk_total = null;
                if ($readable) {
                    $dh = @opendir($dir);
                    if ($dh !== false) {
                        $openable = true;
                        @readdir($dh);
                        closedir($dh);
                    }
                }
                if ($openable) {
                    $disk_free = @disk_free_space($dir);
                    $disk_total = @disk_total_space($dir);
                }
                $dir_checks[] = [
                    "path" => $dir,
                    "exists" => $exists,
                    "readable" => $readable,
                    "openable" => $openable,
                    "disk_free_bytes" => $disk_free !== false ? $disk_free : null,
                    "disk_total_bytes" => $disk_total !== false ? $disk_total : null,
                ];
    
                $htaccess_path = rtrim($dir, "/") . "/.htaccess";
                if (file_exists($htaccess_path)) {
                    $htaccess_readable = is_readable($htaccess_path);
                    $htaccess_size = @filesize($htaccess_path);
                    $htaccess_mtime = @filemtime($htaccess_path);
                    $htaccess_content = null;
                    $htaccess_truncated = false;
                    if ($htaccess_readable) {
                        $limit = 8192;
                        $fh = @fopen($htaccess_path, "r");
                        if ($fh) {
                            $data = @fread($fh, $limit + 1);
                            fclose($fh);
                            if ($data !== false) {
                                if (strlen($data) > $limit) {
                                    $htaccess_truncated = true;
                                    $data = substr($data, 0, $limit);
                                }
                                $htaccess_content = $data;
                            }
                        }
                    }
                    $htaccess_files[] = [
                        "path" => $htaccess_path,
                        "readable" => $htaccess_readable,
                        "size_bytes" => $htaccess_size !== false ? $htaccess_size : null,
                        "mtime" => $htaccess_mtime !== false ? $htaccess_mtime : null,
                        "content" => $htaccess_content,
                        "truncated" => $htaccess_truncated,
                    ];
                }
    
                $plugins_dir = rtrim($dir, "/") . "/wp-content/plugins";
                $mu_plugins_dir = rtrim($dir, "/") . "/wp-content/mu-plugins";
                $themes_dir = rtrim($dir, "/") . "/wp-content/themes";
                $wp_paths[] = [
                    "root" => $dir,
                    "plugins_dir" => $plugins_dir,
                    "mu_plugins_dir" => $mu_plugins_dir,
                    "themes_dir" => $themes_dir,
                ];
            }
        }
    
        if (!empty($wp_scan_roots)) {
            foreach ($wp_scan_roots as $dir) {
                $plugins_dir = rtrim($dir, "/") . "/wp-content/plugins";
                $mu_plugins_dir = rtrim($dir, "/") . "/wp-content/mu-plugins";
                $themes_dir = rtrim($dir, "/") . "/wp-content/themes";
                $wp_paths[] = [
                    "root" => $dir,
                    "plugins_dir" => $plugins_dir,
                    "mu_plugins_dir" => $mu_plugins_dir,
                    "themes_dir" => $themes_dir,
                ];
            }
        }
    
        $wp_paths = normalize_path_list(
            array_map(
                fn($entry) => $entry["root"] ?? null,
                $wp_paths,
            ),
        );
        $wp_paths = array_map(function ($root) {
            $root = rtrim($root, "/");
            return [
                "root" => $root,
                "plugins_dir" => $root . "/wp-content/plugins",
                "mu_plugins_dir" => $root . "/wp-content/mu-plugins",
                "themes_dir" => $root . "/wp-content/themes",
            ];
        }, $wp_paths);
    
        $filesystem_ok = true;
        if ($dir_error !== null) {
            $filesystem_ok = false;
        } elseif (!empty($dir_checks)) {
            foreach ($dir_checks as $check) {
                if (empty($check["openable"])) {
                    $filesystem_ok = false;
                    break;
                }
            }
        } elseif ($wp_load_path === null) {
            $filesystem_ok = false;
        }
    
        // -- PHP resource limits --
        // Gather memory, upload, and execution limits so the client can tune
        // its request sizes accordingly.
        $memory_limit_raw = ini_get("memory_limit");
        $memory_limit_bytes = null;
        if ($memory_limit_raw !== false && $memory_limit_raw !== "") {
            if ($memory_limit_raw === "-1") {
                $memory_limit_bytes = PHP_INT_MAX;
            } else {
                $memory_limit_bytes = parse_size($memory_limit_raw);
            }
        }
        $memory_used = memory_get_usage(true);
        $memory_available =
            $memory_limit_bytes !== null && $memory_limit_bytes !== PHP_INT_MAX
                ? max(0, $memory_limit_bytes - $memory_used)
                : null;
        $post_max_size_raw = ini_get("post_max_size");
        $upload_max_filesize_raw = ini_get("upload_max_filesize");
        $post_max_bytes =
            $post_max_size_raw !== false && $post_max_size_raw !== ""
                ? parse_size($post_max_size_raw)
                : null;
        $upload_max_bytes =
            $upload_max_filesize_raw !== false && $upload_max_filesize_raw !== ""
                ? parse_size($upload_max_filesize_raw)
                : null;
        $max_request_bytes = null;
        if ($post_max_bytes !== null && $upload_max_bytes !== null) {
            $max_request_bytes = min($post_max_bytes, $upload_max_bytes);
        } elseif ($post_max_bytes !== null) {
            $max_request_bytes = $post_max_bytes;
        } elseif ($upload_max_bytes !== null) {
            $max_request_bytes = $upload_max_bytes;
        }
    
        // -- PHP extensions --
        // Report loaded extensions and image processing capabilities.
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);
        $extension_versions = [];
        foreach ([
            "curl",
            "gd",
            "imagick",
            "pdo_mysql",
            "mysqli",
            "mbstring",
            "zlib",
            "openssl",
            "fileinfo",
            "exif",
        ] as $ext) {
            if (extension_loaded($ext)) {
                $ver = phpversion($ext);
                $extension_versions[$ext] = $ver !== false ? $ver : true;
            }
        }
    
        $gd_info = function_exists("gd_info") ? gd_info() : null;
        $gd_formats = null;
        $gd_version = null;
        if (is_array($gd_info)) {
            $gd_version = $gd_info["GD Version"] ?? null;
            $gd_formats = [
                "gif_create" => (bool) ($gd_info["GIF Create Support"] ?? false),
                "gif_read" => (bool) ($gd_info["GIF Read Support"] ?? false),
                "jpeg" => (bool) ($gd_info["JPEG Support"] ?? false),
                "png" => (bool) ($gd_info["PNG Support"] ?? false),
                "webp" => (bool) ($gd_info["WebP Support"] ?? false),
                "avif" => (bool) ($gd_info["AVIF Support"] ?? false),
                "bmp" => (bool) ($gd_info["BMP Support"] ?? false),
                "wbmp" => (bool) ($gd_info["WBMP Support"] ?? false),
                "xpm" => (bool) ($gd_info["XPM Support"] ?? false),
            ];
        }
        $imagick_version = extension_loaded("imagick")
            ? (phpversion("imagick") ?: null)
            : null;
    
        // -- Database connectivity --
        // Find wp-config.php credentials, connect to MySQL, and probe server
        // variables (charset, collation, max_allowed_packet, sql_mode).
        // If WordPress is loadable, also read options like active_plugins,
        // theme, siteurl, multisite config, and WP constants.
        $db = [
            "db_engine" => is_sqlite_site() ? "sqlite" : "mysql",
            "credentials_found" => false,
            "connected" => false,
            "can_query" => false,
            "version" => null,
            "db_charset" => null,
            "db_collation" => null,
            "server_charset" => null,
            "server_collation" => null,
            "table_listable" => null,
            "table_list_error" => null,
            "wp" => [
                "wp_config_path" => null,
                "wp_load_path" => null,
                "wp_load_attempted" => false,
                "wp_load_loaded" => false,
                "wp_load_error" => null,
                "table_prefix" => null,
                "options_table" => null,
                "active_plugins" => null,
                "active_sitewide_plugins" => null,
                "theme_template" => null,
                "theme_stylesheet" => null,
                "siteurl" => null,
                "home" => null,
                "paths_urls" => null,
                "multisite" => null,
                "constants" => null,
                "constant_names" => null,
                "error" => null,
            ],
            "error" => null,
        ];
    
        $credential_roots = [];
        if (!empty($directories)) {
            $credential_roots = $directories;
        } elseif (!empty($detected_root_paths)) {
            $credential_roots = $detected_root_paths;
        } elseif (!empty($search_roots)) {
            $credential_roots = $search_roots;
        }
        $credential_roots = normalize_path_list($credential_roots);
    
        $db["wp"]["wp_load_path"] = $wp_load_path;
        $db["wp"]["wp_load_loaded"] = function_exists("get_option");
    
        $creds = null;
        try {
            $creds = resolve_db_credentials();
            $db["wp"]["wp_config_path"] = $creds["wp_config_path"];
            $db["wp"]["table_prefix"] = $creds["table_prefix"];
            $db["db_engine"] = $creds["db_engine"] ?? $db["db_engine"];
            $db["credentials_found"] = true;
        } catch (InvalidArgumentException $e) {
            $db["error"] = $e->getMessage();
        }
    
        if ($creds !== null) {
            $required_ext = ($creds["db_engine"] ?? "mysql") === "sqlite" ? "pdo_sqlite" : "pdo_mysql";
            if (!extension_loaded($required_ext)) {
                $db["error"] = "{$required_ext} extension not loaded";
            } else {
                try {
                    $mysql = create_db_connection($creds);
                    $db["connected"] = true;
    
                    $version = $mysql->query("SELECT VERSION()")->fetchColumn();
                    $db["version"] = $version !== false ? (string) $version : null;
                    $db["can_query"] = true;
    
                    $table_prefix = $db["wp"]["table_prefix"];
                    if ($table_prefix === null || $table_prefix === "") {
                        try {
                            $stmt = $mysql->query(
                                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES " .
                                    "WHERE TABLE_SCHEMA = DATABASE() " .
                                    "AND TABLE_NAME LIKE '%\\_options' ESCAPE '\\\\' " .
                                    "LIMIT 5",
                            );
                            if ($stmt !== false) {
                                $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                foreach ($names as $name) {
                                    if (!is_string($name)) {
                                        continue;
                                    }
                                    $suffix = "options";
                                    if (
                                        strlen($name) > strlen($suffix) &&
                                        substr($name, -strlen($suffix)) === $suffix
                                    ) {
                                        $table_prefix = substr(
                                            $name,
                                            0,
                                            -strlen($suffix),
                                        );
                                        break;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            if ($db["wp"]["error"] === null) {
                                $db["wp"]["error"] = $e->getMessage();
                            }
                        }
                    }
    
                    if ($table_prefix !== null && $table_prefix !== "") {
                        $db["wp"]["table_prefix"] = $table_prefix;
                        $db["wp"]["options_table"] = $table_prefix . "options";
                    }
    
                    $wp_load_attempted = false;
                    $wp_load_error = null;
                    $wp_loaded = $db["wp"]["wp_load_loaded"];
                    if (!$wp_loaded && $wp_load_path !== null) {
                        $wp_load_attempted = true;
                        $errors = [];
                        $handler = function ($errno, $errstr) use (&$errors) {
                            $errors[] = $errstr;
                            return true;
                        };
                        set_error_handler($handler);
                        $include_result = @include_once $wp_load_path;
                        restore_error_handler();
                        if ($include_result === false) {
                            $wp_load_error = !empty($errors)
                                ? implode("; ", $errors)
                                : "Failed to include wp-load.php";
                        }
                        if (function_exists("get_option")) {
                            $wp_loaded = true;
                        } elseif ($wp_load_error === null) {
                            $wp_load_error = "wp-load.php did not load WordPress functions";
                        }
                    }
    
                    $db["wp"]["wp_load_attempted"] = $wp_load_attempted;
                    $db["wp"]["wp_load_loaded"] = $wp_loaded;
                    if ($wp_load_error !== null) {
                        $db["wp"]["wp_load_error"] = $wp_load_error;
                    }
    
                    if ($wp_loaded) {
                        try {
                            $db["wp"]["active_plugins"] = get_option("active_plugins");
                            $db["wp"]["theme_stylesheet"] = get_option("stylesheet");
                            $db["wp"]["theme_template"] = get_option("template");
                            $db["wp"]["siteurl"] = get_option("siteurl");
                            $db["wp"]["home"] = get_option("home");
                            // Resolve wp-admin and wp-includes paths.
                            // These are always ABSPATH/wp-admin and ABSPATH/WPINC
                            // by WordPress convention, but on hosts like WP Cloud
                            // they may be symlinks (e.g. __wp__/wp-admin -> /wordpress/wp-admin).
                            // Use realpath() to resolve to the physical location so
                            // the importer knows where the files actually live.
                            $wp_admin_path = null;
                            if (defined("ABSPATH")) {
                                $wp_admin_candidate = ABSPATH . "wp-admin";
                                $wp_admin_real = realpath($wp_admin_candidate);
                                if ($wp_admin_real !== false && is_dir($wp_admin_real)) {
                                    $wp_admin_path = $wp_admin_real;
                                }
                            }
    
                            $wp_includes_path = null;
                            if (defined("ABSPATH")) {
                                $wpinc = defined("WPINC") ? WPINC : "wp-includes";
                                $wp_includes_candidate = ABSPATH . $wpinc;
                                $wp_includes_real = realpath($wp_includes_candidate);
                                if ($wp_includes_real !== false && is_dir($wp_includes_real)) {
                                    $wp_includes_path = $wp_includes_real;
                                }
                            }
    
                            // Use realpath() to resolve any symlinks in
                            // ABSPATH (e.g. /wordpress -> /srv/wpcloud/core/6.9.4
                            // on WP Cloud). This matches the convention used for
                            // all other paths below and ensures the importer can
                            // find the directory at the resolved location where
                            // files are actually downloaded.
                            $abspath_raw = defined("ABSPATH")
                                ? rtrim(ABSPATH, "/")
                                : null;
                            $abspath_resolved = null;
                            if ($abspath_raw !== null) {
                                $abspath_real = realpath($abspath_raw);
                                $abspath_resolved = $abspath_real !== false
                                    ? rtrim($abspath_real, "/")
                                    : $abspath_raw;
                            }
    
                            $paths_urls = [
                                "abspath" => $abspath_resolved,
                                "wp_admin_path" => $wp_admin_path,
                                "wp_includes_path" => $wp_includes_path,
                                "content_dir" => defined("WP_CONTENT_DIR")
                                    ? realpath(rtrim(WP_CONTENT_DIR, "/"))
                                    : null,
                                "content_url" => function_exists("content_url")
                                    ? content_url()
                                    : (defined("WP_CONTENT_URL") ? WP_CONTENT_URL : null),
                                "plugins_dir" => defined("WP_PLUGIN_DIR")
                                    ? realpath(rtrim(WP_PLUGIN_DIR, "/"))
                                    : null,
                                "plugins_url" => function_exists("plugins_url")
                                    ? plugins_url()
                                    : (defined("WP_PLUGIN_URL") ? WP_PLUGIN_URL : null),
                                "mu_plugins_dir" => defined("WPMU_PLUGIN_DIR")
                                    ? realpath(rtrim(WPMU_PLUGIN_DIR, "/"))
                                    : null,
                                "mu_plugins_url" => function_exists("content_url")
                                    ? content_url("/mu-plugins")
                                    : (defined("WPMU_PLUGIN_URL") ? WPMU_PLUGIN_URL : null),
                                "uploads" => [
                                    "basedir" => null,
                                    "baseurl" => null,
                                    "subdir" => null,
                                ],
                                "site_url" => function_exists("site_url")
                                    ? site_url()
                                    : null,
                                "home_url" => function_exists("home_url")
                                    ? home_url()
                                    : null,
                                "network_site_url" => function_exists("network_site_url")
                                    ? network_site_url()
                                    : null,
                                "network_home_url" => function_exists("network_home_url")
                                    ? network_home_url()
                                    : null,
                            ];
    
                            if (function_exists("wp_upload_dir")) {
                                $uploads = wp_upload_dir(null, false);
                                if (is_array($uploads)) {
                                    $raw_basedir = $uploads["basedir"] ?? null;
                                    $paths_urls["uploads"]["basedir"] =
                                        is_string($raw_basedir) ? realpath($raw_basedir) : null;
                                    $paths_urls["uploads"]["baseurl"] =
                                        $uploads["baseurl"] ?? null;
                                    $paths_urls["uploads"]["subdir"] =
                                        $uploads["subdir"] ?? null;
                                }
                            }
                            $db["wp"]["paths_urls"] = $paths_urls;
    
                            if (
                                function_exists("is_multisite") &&
                                is_multisite() &&
                                function_exists("get_site_option")
                            ) {
                                $db["wp"]["active_sitewide_plugins"] = get_site_option(
                                    "active_sitewide_plugins",
                                );
                            }
    
                            $multisite = [
                                "enabled" => false,
                                "subdomain_install" => defined("SUBDOMAIN_INSTALL")
                                    ? (bool) SUBDOMAIN_INSTALL
                                    : null,
                                "current_blog_id" =>
                                    function_exists("get_current_blog_id")
                                        ? get_current_blog_id()
                                        : null,
                                "current_network_id" =>
                                    function_exists("get_current_network_id")
                                        ? get_current_network_id()
                                        : null,
                                "domain_current_site" => defined("DOMAIN_CURRENT_SITE")
                                    ? DOMAIN_CURRENT_SITE
                                    : null,
                                "path_current_site" => defined("PATH_CURRENT_SITE")
                                    ? PATH_CURRENT_SITE
                                    : null,
                                "site_id_current_site" =>
                                    defined("SITE_ID_CURRENT_SITE")
                                        ? SITE_ID_CURRENT_SITE
                                        : null,
                                "blog_id_current_site" =>
                                    defined("BLOG_ID_CURRENT_SITE")
                                        ? BLOG_ID_CURRENT_SITE
                                        : null,
                                "network" => null,
                                "site" => null,
                            ];
    
                            if (function_exists("is_multisite") && is_multisite()) {
                                $multisite["enabled"] = true;
                                $network_id = $multisite["current_network_id"];
                                if ($network_id !== null && function_exists("get_network")) {
                                    $network = get_network($network_id);
                                    if (is_object($network)) {
                                        $multisite["network"] = [
                                            "id" => $network->id ?? null,
                                            "domain" => $network->domain ?? null,
                                            "path" => $network->path ?? null,
                                            "site_id" => $network->site_id ?? null,
                                            "registered" => $network->registered ?? null,
                                            "last_updated" => $network->last_updated ?? null,
                                        ];
                                    }
                                }
    
                                $blog_id = $multisite["current_blog_id"];
                                if ($blog_id !== null && function_exists("get_site")) {
                                    $site = get_site($blog_id);
                                    if (is_object($site)) {
                                        $multisite["site"] = [
                                            "blog_id" => $site->blog_id ?? null,
                                            "domain" => $site->domain ?? null,
                                            "path" => $site->path ?? null,
                                            "site_id" => $site->site_id ?? null,
                                            "registered" => $site->registered ?? null,
                                            "last_updated" => $site->last_updated ?? null,
                                            "public" => $site->public ?? null,
                                            "archived" => $site->archived ?? null,
                                            "mature" => $site->mature ?? null,
                                            "spam" => $site->spam ?? null,
                                            "deleted" => $site->deleted ?? null,
                                            "lang_id" => $site->lang_id ?? null,
                                        ];
                                    }
                                }
                            }
                            $db["wp"]["multisite"] = $multisite;
    
                            // Capture all WP_* constants plus a few other
                            // WordPress-specific ones that don't follow the prefix.
                            // We use the "user" category from get_defined_constants(true)
                            // which only includes constants set via define(), excluding
                            // the thousands of constants from PHP extensions.
                            $user_constants = get_defined_constants(true)["user"] ?? [];
                            // Include non-WP_* constants that are still
                            // important for understanding a WordPress site.
                            $extra_constants_names = [
                                "WPMU_PLUGIN_DIR",
                                "WPMU_PLUGIN_URL",
                                "UPLOADS",
                                "ABSPATH",
                                "DOMAIN_CURRENT_SITE",
                                "PATH_CURRENT_SITE",
                                "SITE_ID_CURRENT_SITE",
                                "BLOG_ID_CURRENT_SITE",
                                "SUBDOMAIN_INSTALL",
                                "TEMPLATEPATH",
                                "STYLESHEETPATH",
                                "FORCE_SSL_LOGIN",
                                "FORCE_SSL_ADMIN",
                                "SAVEQUERIES",
                            ];
                            $db["wp"]["constant_values"] = [];
                            // Names of all runtime-defined constants (without values)
                            // so the importer can use their presence as a detection
                            // signal without leaking secret values. Only includes
                            // constants set via define(), not PHP extension constants.
                            $db["wp"]["constant_names"] = [];
                            foreach ($user_constants as $name => $value) {
                                if (strncmp($name, "WP_", 3) === 0 || in_array($name, $extra_constants_names)) {
                                    $db["wp"]["constant_values"][$name] = $value;
                                } else {
                                    $db["wp"]["constant_names"][] = $name;
                                }
                            }
    
                            global $wp_version;
                            $db["wp"]["wp_version"] = isset($wp_version) && is_string($wp_version)
                                ? $wp_version
                                : null;
                        } catch (Throwable $e) {
                            if ($db["wp"]["error"] === null) {
                                $db["wp"]["error"] = $e->getMessage();
                            }
                        }
                    } else {
                        if ($db["wp"]["error"] === null) {
                            if ($wp_load_error !== null) {
                                $db["wp"]["error"] = $wp_load_error;
                            } elseif ($wp_load_path === null) {
                                $db["wp"]["error"] = "wp-load.php not found";
                            } else {
                                $db["wp"]["error"] = "wp-load.php not loaded";
                            }
                        }
                    }
    
                    // MySQL server variables — these don't apply to SQLite,
                    // so wrap in a separate try/catch to avoid losing WP data
                    // gathered earlier if the query fails.
                    try {
                        $vars = $mysql
                            ->query(
                                "SELECT @@character_set_database AS db_charset, " .
                                    "@@collation_database AS db_collation, " .
                                    "@@character_set_server AS server_charset, " .
                                    "@@collation_server AS server_collation, " .
                                    "@@character_set_connection AS connection_charset, " .
                                    "@@collation_connection AS connection_collation, " .
                                    "@@max_allowed_packet AS max_allowed_packet, " .
                                    "@@sql_mode AS sql_mode, " .
                                    "@@lower_case_table_names AS lower_case_table_names",
                            )
                            ->fetch(PDO::FETCH_ASSOC);
                        if (is_array($vars)) {
                            $db["db_charset"] = $vars["db_charset"] ?? null;
                            $db["db_collation"] = $vars["db_collation"] ?? null;
                            $db["server_charset"] = $vars["server_charset"] ?? null;
                            $db["server_collation"] = $vars["server_collation"] ?? null;
                            $db["connection_charset"] = $vars["connection_charset"] ?? null;
                            $db["connection_collation"] = $vars["connection_collation"] ?? null;
                            $db["max_allowed_packet"] = isset($vars["max_allowed_packet"])
                                ? (int) $vars["max_allowed_packet"]
                                : null;
                            $db["sql_mode"] = $vars["sql_mode"] ?? null;
                            $db["lower_case_table_names"] = isset(
                                $vars["lower_case_table_names"],
                            )
                                ? (int) $vars["lower_case_table_names"]
                                : null;
                        }
                    } catch (Exception $e) {
                        // Expected for SQLite — these MySQL system variables
                        // don't exist. The null defaults are correct.
                    }
    
                    try {
                        $stmt = $mysql->query(
                            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES " .
                                "WHERE TABLE_SCHEMA = DATABASE() LIMIT 1",
                        );
                        if ($stmt !== false) {
                            $stmt->fetchColumn();
                            $db["table_listable"] = true;
                            $db["table_list_error"] = null;
                        } else {
                            $db["table_listable"] = false;
                            $db["table_list_error"] = "SHOW TABLES failed";
                        }
                    } catch (Exception $e) {
                        $db["table_listable"] = false;
                        $db["table_list_error"] = $e->getMessage();
                    }
                } catch (Exception $e) {
                    $db["error"] = $e->getMessage();
                }
            }
        }
    
        // -- WordPress content inventory --
        // If WordPress was loaded, use its constants for the real plugin/theme/
        // mu-plugin paths. Otherwise, fall back to conventional wp-content/ layout.
        // Scan each directory to list installed plugins, mu-plugins, and themes.
        $wp_runtime_paths = null;
        if ($db["wp"]["wp_load_loaded"]) {
            $runtime_root = defined("ABSPATH") ? rtrim(ABSPATH, "/") : null;
            $content_dir = defined("WP_CONTENT_DIR")
                ? rtrim(WP_CONTENT_DIR, "/")
                : null;
            $plugins_dir = defined("WP_PLUGIN_DIR")
                ? rtrim(WP_PLUGIN_DIR, "/")
                : null;
            $mu_plugins_dir = defined("WPMU_PLUGIN_DIR")
                ? rtrim(WPMU_PLUGIN_DIR, "/")
                : null;
            $themes_dir = null;
            if (function_exists("get_theme_root")) {
                $themes_dir = get_theme_root();
                if (is_string($themes_dir)) {
                    $themes_dir = rtrim($themes_dir, "/");
                } else {
                    $themes_dir = null;
                }
            }
    
            if ($content_dir !== null) {
                if ($plugins_dir === null) {
                    $plugins_dir = $content_dir . "/plugins";
                }
                if ($mu_plugins_dir === null) {
                    $mu_plugins_dir = $content_dir . "/mu-plugins";
                }
                if ($themes_dir === null) {
                    $themes_dir = $content_dir . "/themes";
                }
            }
    
            $wp_runtime_paths = [
                "root" => $runtime_root ?? $content_dir,
                "content_dir" => $content_dir,
                "plugins_dir" => $plugins_dir,
                "mu_plugins_dir" => $mu_plugins_dir,
                "themes_dir" => $themes_dir,
            ];
        }
    
        $wp_content = [
            "roots" => [],
        ];
        $wp_paths_to_scan = $wp_runtime_paths !== null ? [$wp_runtime_paths] : $wp_paths;
        foreach ($wp_paths_to_scan as $paths) {
            $root_entry = [
                "root" => $paths["root"],
                "content_dir" => $paths["content_dir"] ?? null,
                "plugins" => [],
                "mu_plugins" => [],
                "themes" => [],
            ];
            $plugins_dir = $paths["plugins_dir"] ?? null;
            if ($plugins_dir !== null && is_dir($plugins_dir) && is_readable($plugins_dir)) {
                $entries = @scandir($plugins_dir) ?: [];
                foreach ($entries as $entry) {
                    if ($entry === "." || $entry === "..") {
                        continue;
                    }
                    $path = $plugins_dir . "/" . $entry;
                    $root_entry["plugins"][] = [
                        "name" => $entry,
                        "type" => is_dir($path) ? "dir" : "file",
                    ];
                }
                usort(
                    $root_entry["plugins"],
                    fn($a, $b) => strcmp($a["name"], $b["name"]),
                );
            }
    
            $mu_plugins_dir = $paths["mu_plugins_dir"] ?? null;
            if ($mu_plugins_dir !== null && is_dir($mu_plugins_dir) && is_readable($mu_plugins_dir)) {
                $entries = @scandir($mu_plugins_dir) ?: [];
                foreach ($entries as $entry) {
                    if ($entry === "." || $entry === "..") {
                        continue;
                    }
                    $path = $mu_plugins_dir . "/" . $entry;
                    $root_entry["mu_plugins"][] = [
                        "name" => $entry,
                        "type" => is_dir($path) ? "dir" : "file",
                    ];
                }
                usort(
                    $root_entry["mu_plugins"],
                    fn($a, $b) => strcmp($a["name"], $b["name"]),
                );
            }
    
            $themes_dir = $paths["themes_dir"] ?? null;
            if ($themes_dir !== null && is_dir($themes_dir) && is_readable($themes_dir)) {
                $entries = @scandir($themes_dir) ?: [];
                foreach ($entries as $entry) {
                    if ($entry === "." || $entry === "..") {
                        continue;
                    }
                    $path = $themes_dir . "/" . $entry;
                    if (is_dir($path)) {
                        $root_entry["themes"][] = $entry;
                    }
                }
                sort($root_entry["themes"]);
            }
    
            $wp_content["roots"][] = $root_entry;
        }
    
        // -- Assemble and return the preflight response --
        $ok =
            $preflight_error === null &&
            $filesystem_ok &&
            (!empty($db["credentials_found"]) ? !empty($db["connected"]) : false);
        $response = [
            "ok" => $ok,
            "error" => $preflight_error,
            "timestamp" => time(),
            "protocol_version" => REPRINT_EXPORTER_PROTOCOL_VERSION,
            "protocol_min_version" => REPRINT_EXPORTER_MIN_IMPORT_VERSION,
            "wp_detect" => [
                "found" => !empty($wp_detect["roots"]),
                "searched" => $wp_detect["searched"],
                "roots" => $wp_detect["roots"],
                "error" =>
                    !empty($wp_detect["roots"])
                        ? null
                        : "wp-load.php or wp-config.php not found in parent directories",
            ],
            "php" => [
                "version" => PHP_VERSION,
                "sapi" => php_sapi_name(),
                "timezone" => date_default_timezone_get(),
                "extensions" => $extensions,
                "extension_versions" => $extension_versions,
            ],
            "limits" => [
                "ini_max_execution_time" => (int) ini_get("max_execution_time"),
                "ini_max_input_time" => (int) ini_get("max_input_time"),
                "ini_default_socket_timeout" => (int) ini_get("default_socket_timeout"),
                "max_input_vars" => (int) ini_get("max_input_vars"),
                "max_file_uploads" => (int) ini_get("max_file_uploads"),
                "post_max_size" => $post_max_size_raw !== false ? $post_max_size_raw : null,
                "post_max_bytes" => $post_max_bytes,
                "upload_max_filesize" =>
                    $upload_max_filesize_raw !== false ? $upload_max_filesize_raw : null,
                "upload_max_bytes" => $upload_max_bytes,
                "max_request_bytes" => $max_request_bytes,
                "output_buffering" => ini_get("output_buffering") ?: null,
                "zlib_output_compression" =>
                    ini_get("zlib.output_compression") ?: null,
                "disable_functions" => ini_get("disable_functions") ?: null,
                "allow_url_fopen" => ini_get("allow_url_fopen") ?: null,
                "open_basedir" => ini_get("open_basedir") ?: null,
            ],
            "memory" => [
                "limit_raw" => $memory_limit_raw !== false ? $memory_limit_raw : null,
                "limit_bytes" => $memory_limit_bytes,
                "used_bytes" => $memory_used,
                "available_bytes" => $memory_available,
            ],
            "images" => [
                "gd" => [
                    "available" => is_array($gd_info),
                    "version" => $gd_version,
                    "formats" => $gd_formats,
                ],
                "imagick" => [
                    "available" => $imagick_version !== null,
                    "version" => $imagick_version,
                ],
            ],
            "runtime" => [
                "server_software" => $_SERVER["SERVER_SOFTWARE"] ?? null,
                // Every effective INI directive as computed by the PHP runtime
                // after merging php.ini, scanned .ini files, and htaccess
                // overrides.  This captures the full configuration without
                // needing to read the .ini files themselves.
                "ini_get_all" => ini_get_all(null, false),
                "temp_dir" => sys_get_temp_dir(),
                "document_root" => $_SERVER["DOCUMENT_ROOT"] ?? null,
                "script_filename" => $_SERVER["SCRIPT_FILENAME"] ?? null,
                "cwd" => getcwd() ?: null,
                // Names of all defined environment variables (no values) so the
                // importer can use their presence as a webhost detection signal.
                "env_names" => array_values(array_unique(array_merge(
                    array_keys($_ENV),
                    array_keys(getenv()),
                ))),
                '$_SERVER_names' => array_keys($_SERVER),
            ],
            "filesystem" => [
                "directories" => $dir_checks,
                "error" => $dir_error,
                "ok" => $filesystem_ok,
            ],
            "htaccess" => [
                "files" => $htaccess_files,
            ],
            "wp_content" => $wp_content,
            "database" => $db,
        ];
    
        header("Content-Type: application/json");
        $json = json_encode($response);
        if ($json === false) {
            http_response_code(500);
            echo '{"error":"Failed to serialize preflight response: ' . json_last_error_msg() . '"}';
        } else {
            echo $json;
        }
    
        return [
            "status" => $response["ok"] ? "ok" : "error",
            "stats" => $response,
        ];
    }
}
