<?php
/**
 * Loader for MySQL lexer, parser, and query stream classes.
 *
 * The lexer, parser, and grammar come from the WordPress/sqlite-database-integration
 * submodule at lib/sqlite-database-integration/. The naive query stream is local
 * to this project.
 *
 * If you see "failed to open stream" errors, run:
 *   git submodule update --init
 */

$sdi = dirname(__DIR__, 3) . '/lib/sqlite-database-integration/wp-includes';

// Generic parser framework
require_once $sdi . '/parser/class-wp-parser-token.php';
require_once $sdi . '/parser/class-wp-parser-node.php';
require_once $sdi . '/parser/class-wp-parser-grammar.php';
require_once $sdi . '/parser/class-wp-parser.php';

// MySQL lexer and parser
require_once $sdi . '/mysql/mysql-grammar.php';
require_once $sdi . '/mysql/class-wp-mysql-token.php';
require_once $sdi . '/mysql/class-wp-mysql-lexer.php';
require_once $sdi . '/mysql/class-wp-mysql-parser.php';

// Local: naive query stream (not part of the submodule)
require_once __DIR__ . '/class-wp-mysql-naive-query-stream.php';
