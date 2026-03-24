<?php
/**
 * Runtime environment loader.
 *
 * Loads all runtime environment components: the manifest, host analyzers,
 * and runtime appliers. Follow the same pattern as url-rewrite/load.php.
 */

require_once __DIR__ . '/class-runtime-manifest.php';
require_once __DIR__ . '/class-host-analyzer.php';
require_once __DIR__ . '/class-wpcloud-host-analyzer.php';
require_once __DIR__ . '/class-siteground-host-analyzer.php';
require_once __DIR__ . '/class-runtime-applier.php';
require_once __DIR__ . '/class-nginx-fpm-applier.php';
require_once __DIR__ . '/class-php-builtin-applier.php';
