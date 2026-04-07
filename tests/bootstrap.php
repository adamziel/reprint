<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the MySQLDumpProducer class
require_once __DIR__ . '/../packages/streaming-exporter/src/class-mysql-dump-producer.php';

// Load the FileTreeProducer class
require_once __DIR__ . '/../packages/streaming-exporter/src/class-file-tree-producer.php';

// Local path-package installs can be stale until composer reinstall.
if (!function_exists('build_pdo_dsn')) {
    require_once __DIR__ . '/../packages/streaming-exporter/src/utils.php';
}

if (!class_exists('Site_Export_HMAC_Client')) {
    require_once __DIR__ . '/../packages/streaming-exporter/src/class-hmac-client.php';
}

if (!class_exists('Site_Export_HMAC_Server')) {
    require_once __DIR__ . '/../packages/streaming-exporter/src/class-hmac-server.php';
}

if (!class_exists('Site_Export_HTTP_Server')) {
    require_once __DIR__ . '/../packages/streaming-exporter/src/class-http-server.php';
}

// Load push-related classes
if (!class_exists('MultipartBodyStream')) {
    require_once __DIR__ . '/../packages/streaming-exporter/src/class-multipart-body-stream.php';
}

if (!class_exists('ChunkWriter')) {
    require_once __DIR__ . '/../packages/streaming-exporter/src/class-chunk-writer.php';
}

// Load push functions (parse_multipart_body, rewrite_table_names, etc.).
// We can't require export.php directly because it does require_once on
// its local utils.php, which collides with the identical vendor copy
// already loaded by Composer (same content, different paths → fatal).
// The push-test-bootstrap defines these functions standalone.
require_once __DIR__ . '/Push/push-test-bootstrap.php';

// Load the test base class
require_once __DIR__ . '/FileSyncProducer/FileSyncProducerTestBase.php';
