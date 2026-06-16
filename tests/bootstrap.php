<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the MySQLDumpProducer class
require_once __DIR__ . '/../packages/reprint-exporter/src/class-mysql-dump-producer.php';

// Load the FileTreeProducer class
require_once __DIR__ . '/../packages/reprint-exporter/src/class-file-tree-producer.php';

// Load exporter support classes used by standalone class tests
require_once __DIR__ . '/../packages/reprint-exporter/src/class-gzip-output-stream.php';
require_once __DIR__ . '/../packages/reprint-exporter/src/class-resource-budget.php';

// Local path-package installs can be stale until composer reinstall.
if (!function_exists('Reprint\\Exporter\\build_pdo_dsn')) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/utils.php';
}

if (!class_exists(\Reprint\Exporter\Site_Export_HMAC_Client::class)) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/class-hmac-client.php';
}

if (!class_exists(\Reprint\Exporter\Site_Export_HMAC_Server::class)) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/class-hmac-server.php';
}

if (!class_exists(\Reprint\Exporter\Site_Export_HTTP_Server::class)) {
    require_once __DIR__ . '/../packages/reprint-exporter/src/class-http-server.php';
}

// Load the test base class
require_once __DIR__ . '/FileSyncProducer/FileSyncProducerTestBase.php';
