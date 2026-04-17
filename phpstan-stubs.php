<?php
/**
 * PHPStan stubs for symbols unavailable on the analysis runtime.
 *
 * PDO\SQLite was introduced in PHP 8.4. The sqlite-database-integration
 * submodule references it, but CI runs PHPStan on PHP 8.2.
 */

namespace PDO {
    class SQLite extends \PDO {
        public function createFunction(string $function_name, callable $callback, int $num_args = -1, int $flags = 0): bool {}
    }
}
