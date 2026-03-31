#!/usr/bin/env php
<?php
// Signal to import.php's CLI guard that we are the entry point.
define('IMPORTER_PHAR_ENTRY', true);
Phar::mapPhar('importer.phar');
require 'phar://importer.phar/packages/streaming-importer/src/import.php';
__HALT_COMPILER();
