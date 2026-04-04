<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the MySQLDumpProducer class
require_once __DIR__ . '/../packages/streaming-exporter/src/class-mysql-dump-producer.php';

// Load the FileTreeProducer class
require_once __DIR__ . '/../packages/streaming-exporter/src/class-file-tree-producer.php';

// Load utility functions covered by export utility tests.
require_once __DIR__ . '/../packages/streaming-exporter/src/utils.php';

// Load the HMAC client/server classes used by auth tests.
require_once __DIR__ . '/../packages/streaming-exporter/src/class-hmac-client.php';
require_once __DIR__ . '/../packages/streaming-exporter/src/class-hmac-server.php';

// Load the test base class
require_once __DIR__ . '/FileSyncProducer/FileSyncProducerTestBase.php';
