<?php

/**
 * PHPUnit bootstrap file for MySQL Dump Producer tests.
 */

// Load Composer autoloader
require_once __DIR__ . "/../vendor/autoload.php";

// Load required classes
require_once __DIR__ . "/../class-mysql-dump-producer.php";

// Display test environment info
echo "\n";
echo "MySQL Dump Producer Test Suite\n";
echo "==============================\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PDO MySQL: " .
    (extension_loaded("pdo_mysql") ? "Available" : "NOT AVAILABLE") .
    "\n";
echo "\n";

if (!extension_loaded("pdo_mysql")) {
    echo "ERROR: PDO MySQL extension is required for tests.\n";
    exit(1);
}
