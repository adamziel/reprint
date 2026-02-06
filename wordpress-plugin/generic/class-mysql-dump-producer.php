<?php

namespace WordPress\DataLiberation;

use PDO;
use PDOStatement;

/**
 * Produces MySQL dump SQL fragments one at a time, maintaining reentrant cursor semantics.
 *
 * Streams SQL output without buffering:
 * - Emits SQL header (SET statements)
 * - For each table: CREATE TABLE, INSERT statements (one row at a time)
 * - Emits SQL footer (COMMIT)
 * - Maintains cursor for resumable exports
 * - Self-contained with no external dependencies except PDO
 */
class MySQLDumpProducer
{
    /**
     * State constants for the finite state machine
     */
    const STATE_INIT = "init";
    const STATE_EMIT_HEADER = "emit_header";
    const STATE_NEXT_TABLE = "next_table";
    const STATE_CREATE_TABLE = "create_table";
    const STATE_TABLE_HEADER = "table_header";
    const STATE_START_INSERT = "start_insert";
    const STATE_EMIT_ROW = "emit_row";
    const STATE_EMIT_OVERSIZED_UPDATE = "emit_oversized_update";
    const STATE_EMIT_FOOTER = "emit_footer";
    const STATE_FINISHED = "finished";

    /**
     * The database connection.
     *
     * @var PDO
     */
    private $db;

    /**
     * The current SQL fragment ready to be retrieved.
     *
     * @var string|null
     */
    private $current_sql_fragment = null;

    /**
     * The primary key columns for the current table.
     *
     * @var array|null
     */
    private $current_pk_columns = null;

    /**
     * The values of the last processed primary key.
     *
     * @var array|null
     */
    private $last_pk_values = null;

    /**
     * The offset for tables without primary keys.
     *
     * @var int
     */
    private $current_offset = 0;

    /**
     * The current table being processed.
     *
     * @var string|null
     */
    private $current_table = null;

    /**
     * The current query result set.
     *
     * @var PDOStatement|null
     */
    private $current_result_set = null;

    /**
     * Counter for rows fetched from the current query.
     * Used to detect when a new query returns 0 rows (table exhausted).
     *
     * @var int
     */
    private $rows_fetched_from_current_query = 0;

    /**
     * The list of tables to process.
     *
     * @var array
     */
    private $tables_to_process;

    /**
     * The current state of the producer.
     *
     * @var string
     */
    private $state = self::STATE_INIT;

    /**
     * Cached column type information, indexed by table name.
     * Structure: ['table_name' => ['column_name' => ['data_type' => '...', 'column_type' => '...']]]
     *
     * @var array
     */
    private $column_type_cache = [];

    /**
     * The current row being emitted (one at a time for memory efficiency).
     *
     * @var array|null
     */
    private $current_row = null;

    /**
     * Counter for rows emitted in the current INSERT statement.
     *
     * @var int
     */
    private $rows_in_batch = 0;

    /**
     * Column types for the current table.
     *
     * @var array|null
     */
    private $current_column_types = null;

    /**
     * Column names for the current table (from first row).
     *
     * @var array|null
     */
    private $current_column_names = null;

    /**
     * The batch size (rows per INSERT statement).
     *
     * @var int
     */
    private $batch_size;

    /**
     * Whether to emit CREATE TABLE statements.
     *
     * @var bool
     */
    private $emit_create_table;

    /**
     * String encoding mode for non-binary string columns.
     *
     * Options:
     * - "raw": Use PDO::quote() with charset conversion (default, MySQL standard)
     * - "0xbinary": Hex-encode all strings as 0x... (preserves exact bytes)
     * - "base64": Base64-encode all strings (preserves exact bytes, more compact than hex)
     *
     * @var string
     */
    private $string_encoding;

    /**
     * Maximum size (in bytes) for a single SQL statement.
     * Rows that would produce larger statements are split using UPDATE + CONCAT().
     * Should be set below MySQL's max_allowed_packet to ensure successful imports.
     *
     * @var int
     */
    private $max_statement_size;

    /**
     * Optional query time limit (milliseconds) for SELECT statements.
     *
     * @var int|null
     */
    private $query_time_limit_ms = null;

    /**
     * Queue of oversized column chunks to emit as UPDATE statements.
     * Structure: [['column' => name, 'chunks' => [...], 'chunk_index' => int], ...]
     *
     * @var array
     */
    private $oversized_queue = [];

    /**
     * Primary key values for the current oversized row, used for UPDATE WHERE clause.
     *
     * @var array|null
     */
    private $oversized_pk_values = null;

    /**
     * The state to return to after finishing oversized updates.
     *
     * @var string|null
     */
    private $state_after_oversized = null;

    /**
     * Tracks the actual byte size of the current INSERT statement being built.
     * Reset when starting a new INSERT, incremented as rows are added.
     *
     * @var int
     */
    private $current_statement_size = 0;

    /**
     * Constructor.
     *
     * @param PDO   $db      The database connection to use.
     * @param array $options The options to configure the producer.
     */
    public function __construct(PDO $db, $options = [])
    {
        $this->db = $db;
        $this->tables_to_process = $options["tables_to_process"] ?? null;
        $this->batch_size = $options["batch_size"] ?? 250;
        $this->emit_create_table = $options["create_table_query"] ?? true;

        // Handle string encoding option
        $this->string_encoding = $options["string_encoding"] ?? "raw";

        // Maximum statement size - auto-detect from MySQL's max_allowed_packet if not specified
        if (isset($options["max_statement_size"])) {
            $this->max_statement_size = $options["max_statement_size"];
        } else {
            $this->max_statement_size = $this->detect_max_statement_size();
        }

        if (isset($options["query_time_limit_ms"])) {
            $limit = (int) $options["query_time_limit_ms"];
            $this->query_time_limit_ms = $limit > 0 ? $limit : null;
        }

        // Validate string_encoding value
        $valid_encodings = ["raw", "0xbinary", "base64"];
        if (!in_array($this->string_encoding, $valid_encodings)) {
            throw new \InvalidArgumentException(
                "Invalid string_encoding value '{$this->string_encoding}'. " .
                    "Must be one of: " .
                    implode(", ", $valid_encodings),
            );
        }

        if (isset($options["cursor"])) {
            $this->initialize_from_cursor($options["cursor"]);
        }
    }

    /**
     * Gets the current SQL fragment.
     *
     * @return string|null The SQL fragment string.
     */
    public function get_sql_fragment(): ?string
    {
        return $this->current_sql_fragment;
    }

    /**
     * Checks if the producer has finished.
     *
     * @return bool True if finished.
     */
    public function is_finished(): bool
    {
        return self::STATE_FINISHED === $this->state;
    }

    /**
     * Advances to the next SQL fragment.
     *
     * @return bool Whether another fragment was generated.
     */
    public function next_sql_fragment()
    {
        if ($this->is_finished()) {
            return false;
        }

        if (self::STATE_INIT === $this->state) {
            if (null === $this->tables_to_process) {
                $this->initialize_tables_to_process();
            }
            $this->state = self::STATE_EMIT_HEADER;
        }

        while (true) {
            switch ($this->state) {
                case self::STATE_EMIT_HEADER:
                    $this->emit_sql_header();
                    $this->state = self::STATE_NEXT_TABLE;
                    return true;

                case self::STATE_NEXT_TABLE:
                    if ($this->move_to_next_table()) {
                        $this->state = $this->emit_create_table
                            ? self::STATE_CREATE_TABLE
                            : self::STATE_TABLE_HEADER;
                    } else {
                        $this->state = self::STATE_EMIT_FOOTER;
                    }
                    break;

                case self::STATE_EMIT_FOOTER:
                    $this->emit_sql_footer();
                    $this->state = self::STATE_FINISHED;
                    return true;

                case self::STATE_CREATE_TABLE:
                    $this->emit_create_table_statement();
                    $this->state = self::STATE_TABLE_HEADER;
                    return true;

                case self::STATE_TABLE_HEADER:
                    $this->emit_table_header_comment();
                    $this->state = self::STATE_START_INSERT;
                    return true;

                case self::STATE_START_INSERT:
                    return $this->emit_insert_header();

                case self::STATE_EMIT_ROW:
                    return $this->emit_row();

                case self::STATE_EMIT_OVERSIZED_UPDATE:
                    // emit_oversized_update returns true if it emitted an UPDATE,
                    // false if the queue is empty and we should continue to next state
                    if ($this->emit_oversized_update()) {
                        return true;
                    }
                    // Queue is empty, state has been updated - continue the loop
                    break;

                case self::STATE_FINISHED:
                    return false;
            }
        }

        return false;
    }

    /**
     * Accumulates the next row from the current table.
     *
     * @return bool Whether a row was accumulated.
     */
    /**
     * Fetches the next row from the database and stores it in current_row.
     * Handles query pagination and retry logic.
     *
     * @return bool True if a row was fetched, false if no more rows.
     */
    private function fetch_and_store_row()
    {
        if (!$this->current_result_set) {
            $query = $this->build_select_query();
            try {
                $this->current_result_set = $this->db->query($query);
            } catch (\PDOException $e) {
                throw new \RuntimeException(
                    "Database query `{$query}` failed for table `{$this->current_table}`: " . $e->getMessage(),
                );
            }
            $this->rows_fetched_from_current_query = 0;
        }

        $record = $this->current_result_set->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            $this->current_result_set = null;

            // Check if we should try fetching from next batch
            if ($this->rows_fetched_from_current_query === 0) {
                // New query returned no rows - table exhausted
                return false;
            }

            // Query had rows but is now exhausted - try next batch if cursor exists
            if ($this->last_pk_values !== null || $this->current_offset > 0) {
                // Recursively fetch from next batch
                return $this->fetch_and_store_row();
            }

            // No cursor and no rows
            return false;
        }

        // Increment counter - we successfully fetched a row
        $this->rows_fetched_from_current_query++;

        // Store column names from first row
        if ($this->current_column_names === null) {
            $this->current_column_names = array_keys($record);
        }

        // Update cursor position
        if ($this->current_pk_columns && count($this->current_pk_columns) > 0) {
            $this->last_pk_values = [];
            foreach ($this->current_pk_columns as $col) {
                $this->last_pk_values[$col] = $record[$col] ?? null;
            }
        } else {
            $this->current_offset++;
        }

        // Store the row
        $this->current_row = $record;
        return true;
    }
    /**
     * Emits the INSERT statement header (INSERT INTO ... VALUES) with the first row.
     * Always includes the first row to prevent dangling INSERT statements when
     * data disappears after cursor save.
     *
     * If the row is oversized, large columns are replaced with empty strings
     * and UPDATE statements are queued to populate them incrementally.
     *
     * @return bool True if header was emitted, false if no rows available.
     */
    private function emit_insert_header()
    {
        // Fetch first row if we don't have one
        if ($this->current_row === null) {
            if (!$this->fetch_and_store_row()) {
                // No rows available, skip to next table
                $this->state = self::STATE_NEXT_TABLE;
                return false;
            }
        }

        // Build column list with backticks
        $column_list = implode(
            ",",
            array_map(function ($col) {
                return "`{$col}`";
            }, $this->current_column_names),
        );

        // Build the INSERT header
        $header = "INSERT INTO `{$this->current_table}` ({$column_list}) VALUES\n";

        // Reset statement size tracking for this new INSERT
        $this->current_statement_size = strlen($header);

        // Format the first row (handles oversized columns based on current_statement_size)
        $first_row_sql = $this->format_row_for_insert($this->current_row);

        // Track the row size (including comma/semicolon terminator)
        $this->current_statement_size += strlen($first_row_sql) + 1;

        // Clear current row (we've processed it)
        $this->current_row = null;
        $this->rows_in_batch = 1; // We're emitting the first row

        // Fetch next row to determine terminator
        $has_next_row = $this->fetch_and_store_row();

        // If we have oversized updates, we MUST end this INSERT statement with a semicolon
        // because we'll emit UPDATE statements next. We can't leave a dangling comma.
        $has_oversized = $this->has_pending_oversized_updates();

        if (!$has_next_row) {
            // First row is the only row - end INSERT statement
            $sql = $header . $first_row_sql . ";";
            $this->current_sql_fragment = $sql;
            $this->current_statement_size = 0; // Statement complete
            if ($has_oversized) {
                $this->state_after_oversized = self::STATE_NEXT_TABLE;
                $this->state = self::STATE_EMIT_OVERSIZED_UPDATE;
            } else {
                $this->state = self::STATE_NEXT_TABLE;
            }
        } elseif ($this->rows_in_batch >= $this->batch_size || $has_oversized) {
            // Batch is full after first row OR we have oversized updates - end this INSERT
            $sql = $header . $first_row_sql . ";";
            $this->current_sql_fragment = $sql;
            $this->current_statement_size = 0; // Statement complete, will reset on next INSERT
            if ($has_oversized) {
                $this->state_after_oversized = self::STATE_START_INSERT;
                $this->state = self::STATE_EMIT_OVERSIZED_UPDATE;
            } else {
                $this->state = self::STATE_START_INSERT;
            }
        } else {
            // Continue this INSERT with more rows (no oversized updates pending)
            $sql = $header . $first_row_sql . ",";
            $this->current_sql_fragment = $sql;
            $this->state = self::STATE_EMIT_ROW;
        }

        return true;
    }

    /**
     * Emits a single row with appropriate terminator (, or ;).
     *
     * If the row is oversized, large columns are replaced with empty strings
     * and UPDATE statements are queued to populate them incrementally.
     *
     * @return bool True if row was emitted.
     */
    private function emit_row()
    {
        // We should always have a current row when entering this state
        if ($this->current_row === null) {
            // This shouldn't happen, but handle gracefully
            $this->state = self::STATE_NEXT_TABLE;
            return false;
        }

        // Format the current row (handles oversized columns based on current_statement_size)
        $row_sql = $this->format_row_for_insert($this->current_row);

        // Track the row size (including newline and comma/semicolon)
        $this->current_statement_size += strlen($row_sql) + 2;

        // Clear current row (we've processed it)
        $this->current_row = null;
        $this->rows_in_batch++;

        // Fetch next row to determine terminator
        $has_next_row = $this->fetch_and_store_row();

        // If we have oversized updates, we MUST end this INSERT statement with a semicolon
        // because we'll emit UPDATE statements next. We can't leave a dangling comma.
        $has_oversized = $this->has_pending_oversized_updates();

        if (!$has_next_row) {
            // No more rows - end INSERT and move to next table
            $this->current_sql_fragment = $row_sql . ";";
            $this->current_statement_size = 0; // Statement complete
            if ($has_oversized) {
                $this->state_after_oversized = self::STATE_NEXT_TABLE;
                $this->state = self::STATE_EMIT_OVERSIZED_UPDATE;
            } else {
                $this->state = self::STATE_NEXT_TABLE;
            }
        } elseif ($this->rows_in_batch >= $this->batch_size || $has_oversized) {
            // Batch is full OR we have oversized updates - end this INSERT
            $this->current_sql_fragment = $row_sql . ";";
            $this->current_statement_size = 0; // Statement complete, will reset on next INSERT
            if ($has_oversized) {
                $this->state_after_oversized = self::STATE_START_INSERT;
                $this->state = self::STATE_EMIT_OVERSIZED_UPDATE;
            } else {
                $this->state = self::STATE_START_INSERT;
            }
        } else {
            // Continue this INSERT (no oversized updates pending)
            $this->current_sql_fragment = $row_sql . ",";
            // Stay in STATE_EMIT_ROW
        }

        return true;
    }

    /**
     * Emits the CREATE TABLE statement for the current table.
     */
    private function emit_create_table_statement()
    {
        try {
            $query = "SHOW CREATE TABLE `{$this->current_table}`";
            $result = $this->db->query($query);
            $row = $result->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to get CREATE TABLE for `{$this->current_table}`: " . $e->getMessage() . " Query: {$query}",
            );
        }

        $sql = null;
        if ($row) {
            if (isset($row["Create Table"])) {
                $sql = $row["Create Table"];
            } elseif (isset($row["Create View"])) {
                $sql = $row["Create View"];
            }
        }

        if ($sql) {
            $header = "--\n-- Table structure for table `{$this->current_table}`\n--\n\n";
            $this->current_sql_fragment = $header . $sql . ";";
        } else {
            $keys = $row ? implode(", ", array_keys($row)) : "(no row returned)";
            throw new \RuntimeException(
                "SHOW CREATE TABLE `{$this->current_table}` returned no usable SQL. " .
                "Available keys: {$keys}"
            );
        }
    }

    /**
     * Emits the SQL file header with SET statements.
     */
    private function emit_sql_header()
    {
        $header =
            "SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;\n" .
            "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n" .
            "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';\n" .
            "SET AUTOCOMMIT=0;\n";
        $this->current_sql_fragment = $header;
    }

    /**
     * Emits the SQL file footer with COMMIT and restore statements.
     */
    private function emit_sql_footer()
    {
        $footer =
            "\nCOMMIT;\n" .
            "SET SQL_MODE=@OLD_SQL_MODE;\n" .
            "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n" .
            "SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\n";
        $this->current_sql_fragment = $footer;
    }

    /**
     * Emits a comment header for the table data section.
     */
    private function emit_table_header_comment()
    {
        $comment = "\n--\n-- Dumping data for table `{$this->current_table}`\n--\n";
        $this->current_sql_fragment = $comment;
    }

    /**
     * Builds a SELECT query for the current table.
     *
     * @return string The SELECT query.
     */
    private function build_select_query()
    {
        $table = $this->current_table;
        $select = "SELECT";
        if ($this->query_time_limit_ms !== null) {
            $select .= " /*+ MAX_EXECUTION_TIME(" .
                $this->query_time_limit_ms .
                ") */";
        }

        // For binary encodings, fetch all non-numeric columns as binary to avoid charset conversion
        if ($this->string_encoding !== "raw" && $this->current_column_types) {
            $select_parts = [];
            foreach ($this->current_column_types as $col_name => $col_info) {
                $data_type = strtoupper($col_info["data_type"]);

                // Don't cast numeric or already-binary types
                if (
                    $this->is_numeric_type($data_type) ||
                    $this->is_binary_type($data_type)
                ) {
                    $select_parts[] = "`{$col_name}`";
                } else {
                    // Cast to binary to get raw bytes without charset conversion
                    $select_parts[] = "CAST(`{$col_name}` AS BINARY) AS `{$col_name}`";
                }
            }
            $query =
                $select .
                " " .
                implode(", ", $select_parts) .
                " FROM `{$table}`";
        } else {
            $query = $select . " * FROM `{$table}`";
        }

        if ($this->current_pk_columns && count($this->current_pk_columns) > 0) {
            if ($this->last_pk_values) {
                $where_conditions = $this->build_pk_where_clause();
                $query .= " WHERE {$where_conditions}";
            }

            $order_cols = array_map(function ($col) {
                return "`{$col}` ASC";
            }, $this->current_pk_columns);
            $query .= " ORDER BY " . implode(", ", $order_cols);
            // Use batch_size as LIMIT to avoid over-fetching
            $query .= " LIMIT {$this->batch_size}";
        } else {
            // For tables without PK, use offset pagination with larger LIMIT
            if ($this->current_offset > 0) {
                $query .= " LIMIT 1000 OFFSET {$this->current_offset}";
            } else {
                $query .= " LIMIT 1000";
            }
        }

        return $query;
    }

    /**
     * Builds a WHERE clause for cursor-based pagination.
     *
     * @return string The WHERE clause conditions.
     */
    private function build_pk_where_clause()
    {
        if (!$this->last_pk_values || count($this->current_pk_columns) === 0) {
            return "1=1";
        }

        $pk_cols = $this->current_pk_columns;

        if (count($pk_cols) === 1) {
            $col = $pk_cols[0];
            $value = $this->last_pk_values[$col];
            return $this->build_comparison($col, $value, ">");
        }

        // Composite primary key - lexicographic ordering
        $conditions = [];
        $prefix_conditions = [];

        foreach ($pk_cols as $index => $col) {
            $value = $this->last_pk_values[$col];

            $current_condition_parts = $prefix_conditions;
            $current_condition_parts[] = $this->build_comparison(
                $col,
                $value,
                ">",
            );
            $conditions[] =
                "(" . implode(" AND ", $current_condition_parts) . ")";

            $prefix_conditions[] = $this->build_comparison($col, $value, "=");
        }

        return "(" . implode(" OR ", $conditions) . ")";
    }

    /**
     * Builds a comparison clause for a column and value.
     *
     * @param string $column   The column name.
     * @param mixed  $value    The value to compare against.
     * @param string $operator The comparison operator.
     * @return string The comparison clause.
     */
    private function build_comparison($column, $value, $operator)
    {
        if ($value === null) {
            return $operator === "="
                ? "`{$column}` IS NULL"
                : "`{$column}` IS NOT NULL";
        }

        if (is_numeric($value)) {
            return "`{$column}` {$operator} {$value}";
        } else {
            $quoted = $this->db->quote($value);
            return "`{$column}` {$operator} {$quoted}";
        }
    }

    /**
     * Detects and returns the primary key columns for a table.
     *
     * @param string $table The table name.
     * @return array Array of primary key column names.
     */
    private function get_primary_key_columns($table)
    {
        $pk_columns = [];

        $query = "SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME = ?
            AND CONSTRAINT_NAME = 'PRIMARY'
            ORDER BY ORDINAL_POSITION";
        try {
            $db_name = $this->db->query("SELECT DATABASE()")->fetchColumn();
            $stmt = $this->db->prepare($query);
            $stmt->execute([$db_name, $table]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to get primary key columns for `{$table}`: " . $e->getMessage() . " Query: {$query}",
            );
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pk_columns[] = $row["COLUMN_NAME"];
        }

        return $pk_columns;
    }

    /**
     * Moves to the next table in the list.
     *
     * @return bool Whether there is another table to process.
     */
    private function move_to_next_table()
    {
        // Ensure tables_to_process is initialized
        if ($this->tables_to_process === null) {
            return false;
        }

        if (!$this->current_table) {
            $this->current_table = reset($this->tables_to_process);
        } else {
            $this->current_table = next($this->tables_to_process);
        }

        if ($this->current_table) {
            // Reset state for new table
            $this->current_pk_columns = $this->get_primary_key_columns(
                $this->current_table,
            );
            $this->last_pk_values = null;
            $this->current_offset = 0;
            $this->current_column_types = $this->get_column_types(
                $this->current_table,
            );
            $this->current_column_names = null;
            $this->current_row = null;
            $this->rows_in_batch = 0;

            // Reset oversized row tracking
            $this->oversized_queue = [];
            $this->oversized_pk_values = null;
            $this->state_after_oversized = null;

            // Reset statement size tracking
            $this->current_statement_size = 0;
        }

        return (bool) $this->current_table;
    }

    /**
     * Initializes the list of tables to process (base tables only, no views).
     */
    private function initialize_tables_to_process()
    {
        $this->tables_to_process = [];

        $db_name = $this->db->query("SELECT DATABASE()")->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ?
               AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
        );
        $stmt->execute([$db_name]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->tables_to_process[] = $row["TABLE_NAME"];
        }
    }

    /**
     * Gets the reentrancy cursor for resuming.
     *
     * @return string JSON string (NOT base64-encoded). Caller is responsible for base64 encoding if needed for HTTP transmission.
     */
    public function get_reentrancy_cursor()
    {
        // Binary data in current_row and oversized_queue chunks must be base64-encoded
        // for JSON serialization, as raw binary can't be JSON-encoded.
        $encoded_current_row = $this->encode_row_for_cursor($this->current_row);
        $encoded_oversized_queue = $this->encode_oversized_queue_for_cursor($this->oversized_queue);

        $json = json_encode([
            "current_table" => $this->current_table,
            "current_pk_columns" => $this->current_pk_columns,
            "last_pk_values" => $this->last_pk_values,
            "current_offset" => $this->current_offset,
            "state" => $this->state,
            "current_row" => $encoded_current_row,
            "rows_in_batch" => $this->rows_in_batch,
            "current_column_names" => $this->current_column_names,
            // Oversized row tracking
            "oversized_queue" => $encoded_oversized_queue,
            "oversized_pk_values" => $this->oversized_pk_values,
            "state_after_oversized" => $this->state_after_oversized,
            // Statement size tracking
            "current_statement_size" => $this->current_statement_size,
        ]);
        if ($json === false) {
            throw new \RuntimeException(
                "Failed to encode reentrancy cursor: " . json_last_error_msg(),
            );
        }
        return $json;
    }

    /**
     * Encodes a row's values for JSON cursor serialization.
     * Binary data is base64-encoded with a marker prefix.
     *
     * @param array|null $row The row data.
     * @return array|null The encoded row.
     */
    private function encode_row_for_cursor($row)
    {
        if ($row === null) {
            return null;
        }

        $encoded = [];
        foreach ($row as $col => $value) {
            if ($value !== null && !mb_check_encoding($value, 'UTF-8')) {
                // Binary data - base64 encode with marker
                $encoded[$col] = ['__binary__' => base64_encode($value)];
            } else {
                $encoded[$col] = $value;
            }
        }
        return $encoded;
    }

    /**
     * Decodes a row's values from JSON cursor serialization.
     *
     * @param array|null $row The encoded row data.
     * @return array|null The decoded row.
     */
    private function decode_row_from_cursor($row)
    {
        if ($row === null) {
            return null;
        }

        $decoded = [];
        foreach ($row as $col => $value) {
            if (is_array($value) && isset($value['__binary__'])) {
                $decoded[$col] = base64_decode($value['__binary__']);
            } else {
                $decoded[$col] = $value;
            }
        }
        return $decoded;
    }

    /**
     * Encodes the oversized queue for JSON cursor serialization.
     *
     * @param array $queue The oversized queue.
     * @return array The encoded queue.
     */
    private function encode_oversized_queue_for_cursor($queue)
    {
        $encoded = [];
        foreach ($queue as $item) {
            $encoded_chunks = [];
            foreach ($item['chunks'] as $chunk) {
                // Always base64-encode chunks as they may contain binary data
                $encoded_chunks[] = base64_encode($chunk);
            }
            $encoded[] = [
                'column' => $item['column'],
                'data_type' => $item['data_type'],
                'chunks' => $encoded_chunks,
                'chunk_index' => $item['chunk_index'],
            ];
        }
        return $encoded;
    }

    /**
     * Decodes the oversized queue from JSON cursor serialization.
     *
     * @param array $queue The encoded queue.
     * @return array The decoded queue.
     */
    private function decode_oversized_queue_from_cursor($queue)
    {
        if (!is_array($queue)) {
            return [];
        }
        $decoded = [];
        foreach ($queue as $item) {
            if (!is_array($item) || !isset($item['chunks']) || !is_array($item['chunks'])) {
                throw new \InvalidArgumentException(
                    "Invalid cursor: oversized_queue item must contain a 'chunks' array"
                );
            }
            $decoded_chunks = [];
            foreach ($item['chunks'] as $chunk) {
                $decoded_chunks[] = base64_decode($chunk);
            }
            $decoded[] = [
                'column' => $item['column'] ?? '',
                'data_type' => $item['data_type'] ?? '',
                'chunks' => $decoded_chunks,
                'chunk_index' => $item['chunk_index'] ?? 0,
            ];
        }
        return $decoded;
    }

    /**
     * Initializes the producer from a cursor.
     *
     * @param string $cursor JSON string (NOT base64-encoded). Must be valid JSON.
     * @throws \InvalidArgumentException if cursor is not valid JSON
     */
    private function initialize_from_cursor($cursor)
    {
        $cursor_data = json_decode($cursor, true);
        if ($cursor_data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                'Invalid cursor format: cursor must be valid JSON. ' .
                'JSON error: ' . json_last_error_msg() . '. ' .
                'Received: ' . substr($cursor, 0, 100)
            );
        }
        if (is_array($cursor_data)) {
            $this->current_table = $cursor_data["current_table"] ?? null;
            if ($this->current_table !== null && !is_string($this->current_table)) {
                throw new \InvalidArgumentException(
                    "Invalid cursor: current_table must be string or null, got " . gettype($this->current_table)
                );
            }
            $this->current_pk_columns =
                $cursor_data["current_pk_columns"] ?? null;
            $this->last_pk_values = $cursor_data["last_pk_values"] ?? null;
            $this->current_offset = $cursor_data["current_offset"] ?? 0;
            if (!is_int($this->current_offset) && !is_float($this->current_offset)) {
                throw new \InvalidArgumentException(
                    "Invalid cursor: current_offset must be numeric, got " . gettype($this->current_offset)
                );
            }
            $this->current_offset = (int) $this->current_offset;
            $this->state = $cursor_data["state"] ?? self::STATE_INIT;
            // Decode binary data in current_row
            $encoded_row = $cursor_data["current_row"] ?? null;
            $this->current_row = $this->decode_row_from_cursor($encoded_row);
            $this->rows_in_batch = $cursor_data["rows_in_batch"] ?? 0;
            if (!is_int($this->rows_in_batch) && !is_float($this->rows_in_batch)) {
                throw new \InvalidArgumentException(
                    "Invalid cursor: rows_in_batch must be numeric, got " . gettype($this->rows_in_batch)
                );
            }
            $this->rows_in_batch = (int) $this->rows_in_batch;
            $this->current_column_names =
                $cursor_data["current_column_names"] ?? null;

            // Restore oversized row tracking (decode base64-encoded binary data)
            $encoded_queue = $cursor_data["oversized_queue"] ?? [];
            $this->oversized_queue = $this->decode_oversized_queue_from_cursor($encoded_queue);
            $this->oversized_pk_values = $cursor_data["oversized_pk_values"] ?? null;
            $this->state_after_oversized = $cursor_data["state_after_oversized"] ?? null;

            // Restore statement size tracking
            $this->current_statement_size = $cursor_data["current_statement_size"] ?? 0;

            // Initialize tables_to_process if not already set
            if ($this->tables_to_process === null) {
                $this->initialize_tables_to_process();

                // Position the array pointer to the current table
                if ($this->current_table) {
                    $found = false;
                    reset($this->tables_to_process);
                    while (
                        ($table = current($this->tables_to_process)) !== false
                    ) {
                        if ($table === $this->current_table) {
                            $found = true;
                            break; // Found it, pointer is now at current_table
                        }
                        next($this->tables_to_process);
                    }
                    // Table was dropped between requests — advance to next
                    if (!$found) {
                        $this->current_table = null;
                        $this->state = self::STATE_INIT;
                    }
                }
            }

            // Restore column types if we're in the middle of a table
            if ($this->current_table) {
                $this->current_column_types = $this->get_column_types(
                    $this->current_table,
                );
                if (empty($this->current_column_types)) {
                    throw new \RuntimeException(
                        "Table `{$this->current_table}` was dropped between export requests " .
                        "(no columns found in INFORMATION_SCHEMA)"
                    );
                }
            }
        }
    }

    /**
     * Gets the column type information for a table.
     * Queries INFORMATION_SCHEMA once per table and caches results.
     *
     * @param string $table_name The table name.
     * @return array Associative array of column name => type information.
     */
    private function get_column_types($table_name)
    {
        if (isset($this->column_type_cache[$table_name])) {
            return $this->column_type_cache[$table_name];
        }

        try {
            $database_name = $this->db->query("SELECT DATABASE()")->fetchColumn();

            $stmt = $this->db->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
            );
            $stmt->execute([$database_name, $table_name]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to get column types for `{$table_name}`: " . $e->getMessage(),
            );
        }

        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[$row["COLUMN_NAME"]] = [
                "data_type" => $row["DATA_TYPE"],
                "column_type" => $row["COLUMN_TYPE"],
            ];
        }

        $this->column_type_cache[$table_name] = $columns;

        return $columns;
    }

    /**
     * Formats a value for inclusion in a MySQL INSERT statement.
     *
     * @param mixed  $value      The value to format.
     * @param string $data_type  The MySQL data type (from INFORMATION_SCHEMA).
     * @return string The formatted value ready for insertion into SQL.
     */
    private function format_value($value, $data_type)
    {
        // Handle NULL specially - no quotes, no escaping
        if ($value === null) {
            return "NULL";
        }

        $data_type = strtoupper($data_type);

        // Numeric types - output raw without quotes
        if ($this->is_numeric_type($data_type)) {
            return (string) $value;
        }

        // Binary types - always use hex encoding
        if ($this->is_binary_type($data_type)) {
            return $this->format_binary($value);
        }

        // String encoding modes for non-binary types
        switch ($this->string_encoding) {
            case "0xbinary":
                // Hex-encode to preserve exact bytes
                return $this->format_binary($value);

            case "base64":
                // Base64-encode to preserve exact bytes
                return $this->format_base64($value);

            case "raw":
            default:
                // Use PDO::quote() with charset conversion (standard MySQL behavior)
                return $this->db->quote($value);
        }
    }

    /**
     * Estimate the formatted SQL size of a value without building the full string.
     *
     * @param mixed  $value     The raw value.
     * @param string $data_type The MySQL data type (uppercase or not).
     * @return int Estimated size in bytes of the formatted SQL literal.
     */
    private function estimate_formatted_size($value, $data_type)
    {
        if ($value === null) {
            return 4; // NULL
        }

        $data_type = strtoupper($data_type);

        if ($this->is_numeric_type($data_type)) {
            return strlen((string) $value);
        }

        // Binary types are always hex-encoded regardless of string_encoding
        if ($this->is_binary_type($data_type)) {
            $len = strlen((string) $value);
            return $len === 0 ? 2 : (2 + ($len * 2)); // '' or 0x...
        }

        $len = strlen((string) $value);
        if ($len === 0) {
            return 2; // ''
        }

        switch ($this->string_encoding) {
            case "0xbinary":
                return 2 + ($len * 2); // 0x + hex

            case "base64":
                $b64_len = $this->estimate_base64_length($len);
                // FROM_BASE64('<data>') => 15 bytes overhead + base64 length
                return 15 + $b64_len;

            case "raw":
            default:
                $escaped_len = $this->estimate_escaped_length((string) $value);
                return $escaped_len + 2; // quotes
        }
    }

    /**
     * Estimate length of a base64-encoded string without encoding.
     */
    private function estimate_base64_length(int $raw_len): int
    {
        if ($raw_len === 0) {
            return 0;
        }
        return 4 * intdiv($raw_len + 2, 3);
    }

    /**
     * Estimate escaped length for MySQL string literals without allocation.
     *
     * Accounts for characters escaped by the MySQL driver:
     * NUL, LF, CR, Ctrl+Z, backslash, single quote, double quote.
     */
    private function estimate_escaped_length(string $value): int
    {
        $len = strlen($value);
        if ($len === 0) {
            return 0;
        }

        $extra = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            if (
                $ch === "\0" ||
                $ch === "\n" ||
                $ch === "\r" ||
                $ch === "\x1a" ||
                $ch === "\\" ||
                $ch === "'" ||
                $ch === "\""
            ) {
                $extra++;
            }
        }

        return $len + $extra;
    }

    /**
     * Determines if a MySQL data type is numeric.
     *
     * @param string $data_type The MySQL data type (uppercase).
     * @return bool True if numeric, false otherwise.
     */
    private function is_numeric_type($data_type)
    {
        $numeric_types = [
            "TINYINT",
            "SMALLINT",
            "MEDIUMINT",
            "INTEGER",
            "INT",
            "BIGINT",
            "DECIMAL",
            "NUMERIC",
            "FLOAT",
            "DOUBLE",
            "REAL",
            "BIT",
            "YEAR",
        ];

        foreach ($numeric_types as $type) {
            if (strpos($data_type, $type) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines if a MySQL data type is binary.
     *
     * @param string $data_type The MySQL data type (uppercase).
     * @return bool True if binary, false otherwise.
     */
    private function is_binary_type($data_type)
    {
        $binary_types = [
            "BINARY",
            "VARBINARY",
            "TINYBLOB",
            "BLOB",
            "MEDIUMBLOB",
            "LONGBLOB",
        ];

        foreach ($binary_types as $type) {
            if (strpos($data_type, $type) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Formats binary data as hex-encoded string with 0x prefix.
     *
     * @param string $value The binary value.
     * @return string The hex-encoded value with 0x prefix.
     */
    private function format_binary($value)
    {
        if ($value === "") {
            return "''";
        }

        return "0x" . bin2hex($value);
    }

    /**
     * Formats binary data as base64-encoded string with FROM_BASE64() wrapper.
     *
     * @param string $value The binary value.
     * @return string The base64-encoded value wrapped in FROM_BASE64().
     */
    private function format_base64($value)
    {
        if ($value === "") {
            return "''";
        }

        $base64 = base64_encode($value);
        return "FROM_BASE64('" . $base64 . "')";
    }

    /**
     * Detects the maximum safe statement size based on MySQL's max_allowed_packet.
     *
     * Uses 80% of max_allowed_packet to leave headroom for protocol overhead.
     * Falls back to 1MB if detection fails.
     *
     * @return int Maximum statement size in bytes.
     */
    private function detect_max_statement_size()
    {
        try {
            $result = $this->db->query("SELECT @@max_allowed_packet as max_allowed_packet");
            $row = $result->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['max_allowed_packet'])) {
                // Use 80% of max_allowed_packet to leave headroom for protocol overhead
                return (int)($row['max_allowed_packet'] * 0.8);
            }
        } catch (\PDOException $e) {
            // Fall through to default
        }

        // Default to 1MB if detection fails
        return 1024 * 1024;
    }

    /**
     * Formats a row for SQL INSERT, handling oversized columns.
     *
     * Uses the actual tracked current_statement_size to determine if adding this row
     * would exceed max_statement_size. If so, large columns are replaced with empty
     * strings and queued for subsequent UPDATE statements.
     *
     * @param array $row The row data.
     * @return string The formatted VALUES clause (e.g., "(1,'hello','')")
     */
    private function format_row_for_insert($row)
    {
        // First, estimate sizes without formatting (avoids large allocations)
        $estimated_sizes = [];
        $raw_values = [];

        foreach ($this->current_column_names as $col) {
            $value = $row[$col] ?? null;
            $raw_values[$col] = $value;
            $data_type = $this->current_column_types[$col]["data_type"] ?? "varchar";
            $estimated_sizes[$col] = $this->estimate_formatted_size($value, $data_type);
        }

        // Calculate this row's estimated size (values + commas + parens + newline)
        $row_size_est = array_sum($estimated_sizes) + count($estimated_sizes) + 3;

        // Check if adding this row would exceed max_statement_size
        // current_statement_size already includes the INSERT header (or accumulated rows)
        $projected_size = $this->current_statement_size + $row_size_est;

        // If within limits, format all values and return
        if ($projected_size <= $this->max_statement_size) {
            $formatted_values = [];
            foreach ($this->current_column_names as $col) {
                $data_type = $this->current_column_types[$col]["data_type"] ?? "varchar";
                $formatted_values[$col] = $this->format_value($raw_values[$col], $data_type);
            }
            return "(" . implode(",", array_values($formatted_values)) . ")";
        }

        // Row exceeds limit - need to split large columns
        // This requires a primary key to identify the row for UPDATE statements
        if (!$this->current_pk_columns || count($this->current_pk_columns) === 0) {
            // Cannot split rows in tables without primary key - emit as-is and hope for the best
            // The import might fail, but that's better than silently dropping data
            $formatted_values = [];
            foreach ($this->current_column_names as $col) {
                $data_type = $this->current_column_types[$col]["data_type"] ?? "varchar";
                $formatted_values[$col] = $this->format_value($raw_values[$col], $data_type);
            }
            return "(" . implode(",", array_values($formatted_values)) . ")";
        }

        // Store PK values for UPDATE WHERE clause
        $this->oversized_pk_values = [];
        foreach ($this->current_pk_columns as $pk_col) {
            $this->oversized_pk_values[$pk_col] = $row[$pk_col] ?? null;
        }

        // Find columns to split - sort by size descending
        $sorted_sizes = $estimated_sizes;
        arsort($sorted_sizes);

        $this->oversized_queue = [];
        $chunked_columns = [];

        // Calculate how much we need to trim to fit within max_statement_size
        $excess = $projected_size - $this->max_statement_size;

        foreach ($sorted_sizes as $col => $size) {
            // Don't split primary key columns - we need them for the WHERE clause
            if (in_array($col, $this->current_pk_columns)) {
                continue;
            }

            // Skip small columns (not worth splitting)
            if ($size < 1000) {
                continue;
            }

            // Check if we've trimmed enough
            if ($excess <= 0) {
                break;
            }

            // Get raw value for chunking
            $raw_value = $raw_values[$col] ?? null;
            if ($raw_value === null || $raw_value === '') {
                continue;
            }

            // Split this column into chunks
            $data_type = $this->current_column_types[$col]["data_type"] ?? "varchar";
            $chunks = $this->create_value_chunks($raw_value, $data_type, $col);

            if (count($chunks) > 1) {
                $chunked_columns[$col] = true;
                $excess -= ($size - 2); // Saved bytes (size minus the '' replacement)

                // Queue chunks for UPDATE statements
                $this->oversized_queue[] = [
                    'column' => $col,
                    'data_type' => $data_type,
                    'chunks' => $chunks,
                    'chunk_index' => 0,
                ];
            }
        }

        if (empty($chunked_columns)) {
            // Nothing to split - fall back to emitting full row
            $this->oversized_pk_values = null;
        }

        $formatted_values = [];
        foreach ($this->current_column_names as $col) {
            if (isset($chunked_columns[$col])) {
                $formatted_values[$col] = "''";
                continue;
            }
            $data_type = $this->current_column_types[$col]["data_type"] ?? "varchar";
            $formatted_values[$col] = $this->format_value($raw_values[$col], $data_type);
        }

        return "(" . implode(",", array_values($formatted_values)) . ")";
    }

    /**
     * Splits a large value into chunks that fit within max_statement_size.
     *
     * @param mixed $value The raw value.
     * @param string $data_type The MySQL data type.
     * @param string $column The column name.
     * @return array Array of raw value chunks.
     */
    private function create_value_chunks($value, $data_type, $column)
    {
        if ($value === null || $value === '') {
            return [$value];
        }

        // Calculate overhead for UPDATE statement:
        // UPDATE `table` SET `col` = CONCAT(`col`, <chunk>) WHERE `pk` = value;
        $update_overhead = strlen("UPDATE `{$this->current_table}` SET `{$column}` = CONCAT(`{$column}`, ) WHERE ;");
        $where_clause_size = $this->estimate_pk_where_size();
        $total_overhead = $update_overhead + $where_clause_size + 100; // Extra margin

        $max_chunk_raw_size = ($this->max_statement_size - $total_overhead);

        // Account for encoding overhead
        $data_type_upper = strtoupper($data_type);
        if ($this->is_binary_type($data_type_upper)) {
            // Hex encoding: 2x size + 2 for "0x" prefix
            $max_chunk_raw_size = (int)(($max_chunk_raw_size - 2) / 2);
        } elseif ($this->string_encoding === "base64") {
            // Base64: ~1.33x size + overhead for FROM_BASE64('')
            $max_chunk_raw_size = (int)(($max_chunk_raw_size - 20) / 1.34);
        } elseif ($this->string_encoding === "0xbinary") {
            // Hex encoding
            $max_chunk_raw_size = (int)(($max_chunk_raw_size - 2) / 2);
        } else {
            // Raw/quoted: roughly 1.1x for escaping
            $max_chunk_raw_size = (int)($max_chunk_raw_size / 1.1);
        }

        // Ensure minimum chunk size
        $max_chunk_raw_size = max($max_chunk_raw_size, 1000);

        $value_len = strlen($value);
        if ($value_len <= $max_chunk_raw_size) {
            return [$value];
        }

        // Split into chunks
        $chunks = [];
        for ($offset = 0; $offset < $value_len; $offset += $max_chunk_raw_size) {
            $chunks[] = substr($value, $offset, $max_chunk_raw_size);
        }

        return $chunks;
    }

    /**
     * Estimates the size of the WHERE clause for PK columns.
     *
     * @return int Estimated size in bytes.
     */
    private function estimate_pk_where_size()
    {
        if (!$this->oversized_pk_values) {
            return 50;
        }

        $size = 0;
        foreach ($this->oversized_pk_values as $col => $value) {
            $size += strlen($col) + 10; // `col` =
            if ($value === null) {
                $size += 10; // IS NULL
            } elseif (is_numeric($value)) {
                $size += strlen((string)$value);
            } else {
                $size += strlen((string)$value) * 1.1 + 2; // Quoted
            }
            $size += 5; // AND
        }

        return (int)$size;
    }

    /**
     * Emits an UPDATE statement for the next chunk of an oversized column.
     *
     * @return bool True if an UPDATE was emitted, false if queue is empty.
     */
    private function emit_oversized_update()
    {
        if (empty($this->oversized_queue)) {
            // No more chunks - return to previous state
            $this->state = $this->state_after_oversized ?? self::STATE_EMIT_ROW;
            $this->state_after_oversized = null;
            $this->oversized_pk_values = null;

            // Don't return a fragment here, let the state machine continue
            return false;
        }

        // Get current column being processed
        $current = &$this->oversized_queue[0];
        $column = $current['column'];
        $data_type = $current['data_type'];
        $chunk = $current['chunks'][$current['chunk_index']];

        // Format the chunk value
        $formatted_chunk = $this->format_value($chunk, $data_type);

        // Build WHERE clause
        $where_parts = [];
        foreach ($this->oversized_pk_values as $pk_col => $pk_value) {
            if ($pk_value === null) {
                $where_parts[] = "`{$pk_col}` IS NULL";
            } elseif (is_numeric($pk_value)) {
                $where_parts[] = "`{$pk_col}` = {$pk_value}";
            } else {
                $quoted = $this->db->quote($pk_value);
                $where_parts[] = "`{$pk_col}` = {$quoted}";
            }
        }
        $where_clause = implode(" AND ", $where_parts);

        // Build UPDATE statement
        $sql = "UPDATE `{$this->current_table}` SET `{$column}` = CONCAT(`{$column}`, {$formatted_chunk}) WHERE {$where_clause};";

        $this->current_sql_fragment = $sql;

        // Move to next chunk
        $current['chunk_index']++;

        // If this column is done, remove from queue
        if ($current['chunk_index'] >= count($current['chunks'])) {
            array_shift($this->oversized_queue);
        }

        return true;
    }

    /**
     * Checks if there are pending oversized updates.
     *
     * @return bool True if there are oversized updates pending.
     */
    private function has_pending_oversized_updates()
    {
        return !empty($this->oversized_queue);
    }
}
