<?php
/**
 * HTTP runtime entrypoint for the exporter package.
 *
 * Composer owns class and helper loading. This file exists for direct
 * require/use by the WordPress plugin and tests; it installs the runtime
 * bootstrap without dispatching a request.
 */

require_once __DIR__ . '/bootstrap.php';
