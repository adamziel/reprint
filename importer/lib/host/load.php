<?php
/**
 * Host analyzer loader.
 *
 * Loads the runtime manifest and all host analyzers. A host analyzer reads
 * preflight data from the source site and produces a RuntimeManifest
 * describing what that site needs to run.
 */

require_once __DIR__ . '/class-runtime-manifest.php';
require_once __DIR__ . '/class-host-analyzer.php';
require_once __DIR__ . '/class-wpcloud-host-analyzer.php';
require_once __DIR__ . '/class-siteground-host-analyzer.php';
