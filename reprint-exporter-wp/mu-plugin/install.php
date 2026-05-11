<?php
/**
 * Activation/deactivation glue for the Reprint Exporter mu-plugin
 * fast-path.
 *
 * On plugin activation, attempts to install the bundled mu-plugin loader
 * at wp-content/mu-plugins/0-reprint-exporter.php so the next API
 * request bypasses regular-plugin load. Tries symlink first (fast, picks
 * up plugin updates automatically) and falls back to a file copy when
 * symlink() isn't available. On deactivation, removes the installed file
 * only if it's recognisably ours — foreign files at the same path are
 * left alone.
 *
 * Every operation here is BEST-EFFORT. A fatal in an activation/
 * deactivation hook would surface as a generic "plugin could not be
 * activated" error in wp-admin and possibly leave WP in a half-state,
 * so we catch every failure mode, record it in an option, and return
 * cleanly. The admin page in wordpress/site-export.php reads that
 * option to surface install state to the operator.
 *
 * If install never works, the regular-plugin route at
 * reprint-exporter-wp/index.php continues to handle API requests as
 * before — slower but functional. Nothing here is load-bearing.
 */

if (!defined('REPRINT_MU_PLUGIN_FILENAME')) {
    /** The filename used inside wp-content/mu-plugins/. The leading "0-"
     *  makes it sort first in wp_get_mu_plugins()'s alphabetical scan. */
    define('REPRINT_MU_PLUGIN_FILENAME', '0-reprint-exporter.php');
}

if (!defined('REPRINT_MU_PLUGIN_STATUS_OPTION')) {
    /** Site option name where install/uninstall outcomes are recorded. */
    define('REPRINT_MU_PLUGIN_STATUS_OPTION', 'reprint_mu_plugin_status');
}

/**
 * Distinctive substring expected to appear near the top of any copy
 * of the loader we installed. Used to recognise "ours" so we don't
 * stomp on a custom mu-plugin a site author may have written at the
 * same filename.
 */
function reprint_mu_plugin_marker(): string {
    return '@reprint-mu-plugin-loader';
}

/** Absolute path to the loader file inside the plugin directory. */
function reprint_mu_plugin_source_path(): string {
    return dirname(__DIR__) . '/mu-plugin/' . REPRINT_MU_PLUGIN_FILENAME;
}

/** Absolute path to the install target inside wp-content/mu-plugins/.
 *  Returns '' if WPMU_PLUGIN_DIR isn't defined (e.g. caller invoked
 *  this outside a normal WP boot). */
function reprint_mu_plugin_target_path(): string {
    if (defined('WPMU_PLUGIN_DIR')) {
        return rtrim(WPMU_PLUGIN_DIR, '/') . '/' . REPRINT_MU_PLUGIN_FILENAME;
    }
    if (defined('WP_CONTENT_DIR')) {
        return rtrim(WP_CONTENT_DIR, '/') . '/mu-plugins/' . REPRINT_MU_PLUGIN_FILENAME;
    }
    return '';
}

/**
 * Returns:
 *   true  — file at $path is a copy or symlink we installed
 *   false — file exists but is not recognisable as ours; LEAVE ALONE
 *   null  — file doesn't exist, or we couldn't read it
 *
 * Symlinks: resolved via realpath() and compared to the source path.
 * Regular files: scanned for the loader marker near the top. The
 * marker is stable across plugin updates so old copies are still
 * recognised as ours after the plugin upgrades.
 */
function reprint_mu_plugin_file_is_ours(string $path): ?bool {
    $exists = file_exists($path);
    $is_link = is_link($path);
    if (!$exists && !$is_link) {
        return null;
    }

    if ($is_link) {
        $resolved = @realpath($path);
        $expected = @realpath(reprint_mu_plugin_source_path());
        if ($resolved !== false && $expected !== false && $resolved === $expected) {
            return true;
        }
        // Dangling symlink, or points somewhere else. Try reading the
        // file too — if the symlink target still has our marker, we
        // installed it.
    }

    $fp = @fopen($path, 'rb');
    if (!$fp) {
        return null;
    }
    $head = fread($fp, 2048);
    fclose($fp);
    if ($head === false) {
        return null;
    }
    return strpos($head, reprint_mu_plugin_marker()) !== false;
}

/**
 * Best-effort install of the mu-plugin loader. Records outcome in the
 * REPRINT_MU_PLUGIN_STATUS_OPTION option. Returns the status array (also
 * stored in the option) for convenience in tests.
 *
 * @return array{kind: string, install_method: ?string, message: string, target_path: string, last_attempt: int}
 */
function reprint_install_mu_plugin(): array {
    $source = reprint_mu_plugin_source_path();
    $target = reprint_mu_plugin_target_path();
    $status = [
        'kind' => 'install-failed',
        'install_method' => null,
        'message' => '',
        'target_path' => $target,
        'last_attempt' => time(),
    ];

    if ($target === '') {
        $status['message'] = 'WPMU_PLUGIN_DIR is not defined; cannot locate mu-plugins directory.';
        update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
        return $status;
    }

    if (!is_readable($source)) {
        $status['message'] = "Source file is missing or unreadable: {$source}";
        update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
        return $status;
    }

    $mu_dir = dirname($target);
    if (!is_dir($mu_dir)) {
        // wp_mkdir_p would be nicer (recursive, WP-aware permissions)
        // but is only available after wp-includes/functions.php loads.
        // Activation hooks run inside the regular WP request lifecycle
        // so it's normally available — but fall back to mkdir() to
        // keep this file usable from contexts where it isn't.
        $created = function_exists('wp_mkdir_p')
            ? wp_mkdir_p($mu_dir)
            : @mkdir($mu_dir, 0755, true);
        if (!$created && !is_dir($mu_dir)) {
            $status['message'] = "Could not create mu-plugins directory at {$mu_dir}.";
            update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
            return $status;
        }
    }

    // Refuse to overwrite a file we didn't write.
    if (file_exists($target) || is_link($target)) {
        $is_ours = reprint_mu_plugin_file_is_ours($target);
        if ($is_ours === false) {
            $status['kind'] = 'foreign';
            $status['message'] = "A different file already exists at {$target}; not overwriting. Move or remove it manually to enable the fast path.";
            update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
            return $status;
        }
        // Ours, or unreadable. Remove first so the symlink/copy below
        // writes into a clean slot. Unlink failure is non-fatal — the
        // subsequent write will surface a more specific error.
        @unlink($target);
    }

    // Try symlink. Picks up plugin upgrades automatically, fast.
    if (function_exists('symlink') && @symlink($source, $target)) {
        $status['kind'] = 'active';
        $status['install_method'] = 'symlink';
        $status['message'] = "Installed as symlink to {$source}.";
        update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
        return $status;
    }

    // Fall back to a file copy. Will go stale on plugin updates; the
    // admin notice mentions this.
    if (@copy($source, $target)) {
        $status['kind'] = 'active';
        $status['install_method'] = 'copy';
        $status['message'] = 'Installed as a file copy. Re-activate the plugin after upgrades to refresh.';
        update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
        return $status;
    }

    $error = error_get_last();
    $status['message'] = 'Both symlink() and copy() failed. '
        . ($error['message'] ?? 'No error message available from PHP.');
    update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
    return $status;
}

/**
 * Best-effort removal of the mu-plugin loader. Leaves foreign files
 * alone. Records outcome in the same option used by install().
 *
 * @return array{kind: string, message: string, target_path: string, last_attempt: int}
 */
function reprint_uninstall_mu_plugin(): array {
    $target = reprint_mu_plugin_target_path();
    $status = [
        'kind' => 'missing',
        'message' => '',
        'target_path' => $target,
        'last_attempt' => time(),
    ];

    if ($target === '' || (!file_exists($target) && !is_link($target))) {
        delete_option(REPRINT_MU_PLUGIN_STATUS_OPTION);
        $status['message'] = 'mu-plugin was not installed; nothing to remove.';
        return $status;
    }

    $is_ours = reprint_mu_plugin_file_is_ours($target);
    if ($is_ours === false) {
        $status['kind'] = 'foreign';
        $status['message'] = "Did not remove an unrecognised file at {$target} during deactivation. Remove it manually if needed.";
        update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
        return $status;
    }

    if (!@unlink($target)) {
        $error = error_get_last();
        $status['kind'] = 'uninstall-failed';
        $status['message'] = "Could not remove the mu-plugin file at {$target}: "
            . ($error['message'] ?? 'unknown error') . '. Remove it manually to fully deactivate the fast path.';
        update_option(REPRINT_MU_PLUGIN_STATUS_OPTION, $status, false);
        return $status;
    }

    delete_option(REPRINT_MU_PLUGIN_STATUS_OPTION);
    $status['message'] = 'mu-plugin removed.';
    return $status;
}

/**
 * Reports current install state. Combines the stored option (which
 * remembers the *last* attempt) with a fresh check of the filesystem
 * (so an externally-edited or deleted mu-plugin file is reflected
 * immediately instead of stale-reading the option).
 *
 * Possible 'kind' values:
 *   - 'active'          — installed and recognised as ours
 *   - 'missing'         — no file at the target, plugin handles requests
 *                         via the regular-plugin route
 *   - 'foreign'         — something else is at the install target;
 *                         fast path is NOT active and we won't replace it
 *   - 'install-failed'  — last install attempt failed (stored reason)
 *   - 'uninstall-failed'— last uninstall attempt failed (stored reason)
 *
 * @return array{kind: string, install_method?: ?string, message: string, target_path: string, last_attempt?: int}
 */
function reprint_get_mu_plugin_status(): array {
    $target = reprint_mu_plugin_target_path();
    $stored = get_option(REPRINT_MU_PLUGIN_STATUS_OPTION, []);
    if (!is_array($stored)) {
        $stored = [];
    }

    if ($target !== '' && (file_exists($target) || is_link($target))) {
        $is_ours = reprint_mu_plugin_file_is_ours($target);
        if ($is_ours === true) {
            return [
                'kind' => 'active',
                'install_method' => is_link($target) ? 'symlink' : 'copy',
                'target_path' => $target,
                'message' => $stored['message'] ?? 'mu-plugin is installed.',
            ];
        }
        if ($is_ours === false) {
            return [
                'kind' => 'foreign',
                'target_path' => $target,
                'message' => "A different file is present at {$target}. The fast path is NOT active; move or remove the file to allow auto-install on the next plugin activation.",
            ];
        }
        // Unreadable. Fall through to stored status.
    }

    if (!empty($stored['kind'])) {
        return $stored;
    }

    return [
        'kind' => 'missing',
        'target_path' => $target,
        'message' => 'mu-plugin is not installed. The plugin will try to install it the next time it is activated.',
    ];
}
