<?php
/**
 * Target runtime applier loader.
 *
 * Loads the RuntimeApplier interface, shared helpers, route handler
 * implementations, all applier classes, and the registry.
 */

// Route handler implementations (host-specific)
require_once __DIR__ . '/route-handlers/wpcloud-thumbnail-generator.php';

// Shared code generation helpers
require_once __DIR__ . '/runtime-php-generator.php';

require_once __DIR__ . '/interface-runtime-applier.php';
require_once __DIR__ . '/class-nginx-fpm-applier.php';
require_once __DIR__ . '/class-php-builtin-applier.php';
require_once __DIR__ . '/class-runtime-appliers.php';
