<?php
/**
 * Host analyzer loader.
 *
 * Loads the runtime manifest, the HostAnalyzer interface, shared preflight
 * extraction helpers, all host analyzer implementations, and the registry.
 */

require_once __DIR__ . '/class-runtime-manifest.php';
require_once __DIR__ . '/preflight-extractors.php';
require_once __DIR__ . '/interface-host-analyzer.php';
require_once __DIR__ . '/class-default-host-analyzer.php';
require_once __DIR__ . '/class-wpcloud-host-analyzer.php';
require_once __DIR__ . '/class-siteground-host-analyzer.php';
require_once __DIR__ . '/class-host-analyzers.php';
