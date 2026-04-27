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

$sdi = null;
foreach ([
    dirname(__DIR__, 5) . '/lib/sqlite-database-integration/wp-includes',
    dirname(__DIR__, 6) . '/lib/sqlite-database-integration/wp-includes',
] as $candidate) {
    if (file_exists($candidate . '/parser/class-wp-parser-token.php')) {
        $sdi = $candidate;
        break;
    }
}

if ($sdi === null) {
    throw new RuntimeException(
        'sqlite-database-integration is missing. Run: git submodule update --init'
    );
}

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
// Local: fast query stream — strcspn-driven scanner that falls back to
// the naive parser on any unrecoverable state. Loaded after the naive
// parser because it instantiates one as a fallback.
require_once __DIR__ . '/class-wp-mysql-fast-query-stream.php';
