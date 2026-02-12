<?php

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the MySQLDumpProducer class
require_once __DIR__ . '/../wordpress-plugin/generic/class-mysql-dump-producer.php';

// Load the FileTreeProducer class
require_once __DIR__ . '/../wordpress-plugin/generic/class-file-tree-producer.php';

// Load the test base class
require_once __DIR__ . '/FileSyncProducer/FileSyncProducerTestBase.php';
