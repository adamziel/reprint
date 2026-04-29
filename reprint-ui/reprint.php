<?php
/**
 * Root-level entry point for the Reprint wizard.
 *
 * Deployed to /srv/htdocs/reprint.php so nginx/WordPress serves it
 * directly (a subdirectory with index.php gets canonicalized by WP
 * into a 403 on WP Cloud Atomic). All supporting files live under
 * /srv/htdocs/reprint-ui/.
 */
require __DIR__ . '/reprint-ui/index.php';
