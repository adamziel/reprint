<?php
/**
 * Thin PDO-compatible adapter wrapping WP_SQLite_Driver.
 *
 * MySQLDumpProducer and endpoint_db_index use PDO::prepare/query/quote
 * and PDOStatement::execute/fetch/fetchAll/fetchColumn. This adapter
 * implements exactly those methods by routing every SQL statement through
 * WP_SQLite_Driver, which translates MySQL syntax to SQLite on the fly.
 *
 * The result is transparent: the dump producer sends MySQL queries, the
 * driver translates them, SQLite answers, and the producer gets rows back
 * in the shape it expects.
 */

/**
 * Wraps a WP_SQLite_Driver instance to look like a PDO connection.
 *
 * Only the methods that MySQLDumpProducer and endpoint code actually call
 * are implemented. Anything else will throw a clear error rather than
 * silently misbehaving.
 */
class SqliteDriverPDO
{
    /** @var WP_SQLite_Driver */
    private $driver;

    /** @var PDO The raw SQLite PDO for quote() delegation. */
    private $raw_pdo;

    public function __construct(WP_SQLite_Driver $driver, PDO $raw_pdo)
    {
        $this->driver = $driver;
        $this->raw_pdo = $raw_pdo;
    }

    /**
     * Prepares a statement for execution.
     *
     * Returns a SqliteDriverPDOStatement that will substitute parameters
     * and execute through the driver when execute() is called.
     */
    public function prepare(string $sql): SqliteDriverPDOStatement
    {
        return new SqliteDriverPDOStatement($this->driver, $sql);
    }

    /**
     * Executes a query immediately and returns the result set.
     */
    public function query(string $sql): SqliteDriverPDOStatement
    {
        $stmt = new SqliteDriverPDOStatement($this->driver, $sql);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Quotes a string for safe inclusion in a query.
     * Delegates to the underlying raw SQLite PDO.
     */
    public function quote(string $value, int $type = PDO::PARAM_STR): string
    {
        return $this->raw_pdo->quote($value, $type);
    }

    /**
     * Returns the underlying WP_SQLite_Driver for direct access.
     */
    public function get_driver(): WP_SQLite_Driver
    {
        return $this->driver;
    }
}

/**
 * PDOStatement-compatible wrapper for WP_SQLite_Driver query results.
 *
 * Collects all result rows eagerly (SQLite result sets are small enough)
 * and serves them through fetch/fetchAll/fetchColumn.
 */
class SqliteDriverPDOStatement
{
    /** @var WP_SQLite_Driver */
    private $driver;

    /** @var string */
    private $sql;

    /** @var array Stored result rows after execution. */
    private $rows = [];

    /** @var int Current position for fetch(). */
    private $position = 0;

    public function __construct(WP_SQLite_Driver $driver, string $sql)
    {
        $this->driver = $driver;
        $this->sql = $sql;
    }

    /**
     * Executes the prepared statement.
     *
     * Substitutes ? placeholders with quoted parameter values, sends
     * the query through WP_SQLite_Driver, and stores the result rows.
     *
     * @param array|null $params Positional parameters (0-indexed).
     * @return bool True on success.
     */
    public function execute($params = null): bool
    {
        // Merge in any parameters set via bindValue().
        if ($params === null && $this->bound_params !== null) {
            $params = $this->bound_params;
        }

        $sql = $this->sql;

        if ($params !== null && count($params) > 0) {
            // Substitute ? placeholders from right to left so positions
            // don't shift as we splice in quoted values.
            $positions = [];
            $len = strlen($sql);
            $in_single = false;
            $in_double = false;
            for ($i = 0; $i < $len; $i++) {
                $ch = $sql[$i];
                if ($ch === "'" && !$in_double) {
                    $in_single = !$in_single;
                } elseif ($ch === '"' && !$in_single) {
                    $in_double = !$in_double;
                } elseif ($ch === '?' && !$in_single && !$in_double) {
                    $positions[] = $i;
                }
            }

            // Replace from the end to preserve earlier offsets.
            for ($i = count($positions) - 1; $i >= 0; $i--) {
                if (!array_key_exists($i, $params)) {
                    continue;
                }
                $value = $params[$i];
                if ($value === null) {
                    $quoted = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $quoted = (string) $value;
                } else {
                    // Use the driver's underlying PDO for safe quoting.
                    $quoted = "'" . str_replace("'", "''", (string) $value) . "'";
                }
                $sql = substr_replace($sql, $quoted, $positions[$i], 1);
            }
        }

        // Named parameters (:name style) — substitute them too.
        if ($params !== null) {
            foreach ($params as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if ($value === null) {
                    $quoted = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $quoted = (string) $value;
                } else {
                    $quoted = "'" . str_replace("'", "''", (string) $value) . "'";
                }
                $sql = str_replace($key, $quoted, $sql);
            }
        }

        $this->driver->query($sql);
        $this->rows = $this->driver->get_results() ?: [];

        // WP_SQLite_Driver returns results as arrays of objects.
        // Convert to associative arrays for PDO compatibility.
        foreach ($this->rows as $i => $row) {
            if (is_object($row)) {
                $this->rows[$i] = (array) $row;
            }
        }

        $this->position = 0;

        return true;
    }

    /**
     * Fetches the next row from the result set.
     *
     * @param int $mode Fetch mode (ignored — always returns associative array).
     * @return array|false Associative array or false when exhausted.
     */
    public function fetch($mode = PDO::FETCH_ASSOC)
    {
        if ($this->position >= count($this->rows)) {
            return false;
        }
        return $this->rows[$this->position++];
    }

    /**
     * Returns all remaining rows from the result set.
     *
     * @param int $mode Fetch mode. Supports FETCH_ASSOC (default) and FETCH_COLUMN.
     * @return array
     */
    public function fetchAll($mode = PDO::FETCH_ASSOC)
    {
        $remaining = array_slice($this->rows, $this->position);
        $this->position = count($this->rows);

        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(function ($row) {
                return reset($row);
            }, $remaining);
        }

        return $remaining;
    }

    /**
     * Returns a single column from the next row.
     *
     * @param int $column_number 0-indexed column number.
     * @return mixed|false The column value, or false if no more rows.
     */
    public function fetchColumn(int $column_number = 0)
    {
        $row = $this->fetch();
        if ($row === false) {
            return false;
        }
        $values = array_values($row);
        return $values[$column_number] ?? false;
    }

    /**
     * Binds a value to a named or positional parameter.
     *
     * This is a no-op stub — parameters are substituted inline during
     * execute(). The method exists so that call sites like
     * $stmt->bindValue(':name', $val, PDO::PARAM_STR) don't fatal.
     */
    public function bindValue($parameter, $value, int $type = PDO::PARAM_STR): bool
    {
        // Store for use during execute() — but our execute() takes params
        // directly, so we stash them here and merge in execute().
        if (!isset($this->bound_params)) {
            $this->bound_params = [];
        }
        $this->bound_params[$parameter] = $value;
        return true;
    }

    /** @var array|null Parameters bound via bindValue(). */
    private $bound_params = null;
}
