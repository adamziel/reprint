<?php

namespace Reprint\Importer;

// Returns the importer version string. Inside the phar, reads the baked-in
// VERSION file. In development, falls back to `git describe`.
function get_importer_version(): string {
    // When running from the phar, the VERSION file is baked in at build time.
    $version_file = dirname(__DIR__) . '/VERSION';
    if (file_exists($version_file)) {
        return trim(file_get_contents($version_file));
    }

    // Development fallback: derive from git.
    $tag = trim(shell_exec('git describe --exact-match --tags HEAD 2>/dev/null') ?: '');
    if ($tag !== '') {
        return $tag;
    }
    $latest = trim(shell_exec("git tag -l 'v*' --sort=-v:refname 2>/dev/null | head -1") ?: '');
    return ($latest !== '' ? $latest : 'v0.0.0') . '-trunk';
}
