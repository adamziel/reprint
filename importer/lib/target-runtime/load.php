<?php
/**
 * Target runtime applier loader.
 *
 * Loads all runtime appliers. A runtime applier takes a RuntimeManifest
 * and writes the configuration files needed to serve the imported site
 * on a specific server platform.
 */

// Error handler implementations (host-specific)
require_once __DIR__ . '/error-handlers/wpcloud-thumbnail-generator.php';

require_once __DIR__ . '/class-runtime-applier.php';
require_once __DIR__ . '/class-nginx-fpm-applier.php';
require_once __DIR__ . '/class-php-builtin-applier.php';
