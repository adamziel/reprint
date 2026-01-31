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
            $this->current_result_set = $this->db->query($query);
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

        // Format the first row
        $formatted_values = [];
        foreach ($this->current_column_names as $col) {
            $value = $this->current_row[$col] ?? null;
            $data_type =
                $this->current_column_types[$col]["data_type"] ?? "varchar";
            $formatted_values[] = $this->format_value($value, $data_type);
        }
        $first_row_sql = "(" . implode(",", $formatted_values) . ")";

        // Clear current row (we've processed it)
        $this->current_row = null;
        $this->rows_in_batch = 1; // We're emitting the first row

        // Fetch next row to determine terminator
        $has_next_row = $this->fetch_and_store_row();

        // Generate INSERT header with first row included
        $sql = "INSERT INTO `{$this->current_table}` ({$column_list}) VALUES\n";

        if (!$has_next_row) {
            // First row is the only row - end INSERT statement
            $sql .= $first_row_sql . ";";
            $this->current_sql_fragment = $sql;
            $this->state = self::STATE_NEXT_TABLE;
        } elseif ($this->rows_in_batch >= $this->batch_size) {
            // Batch is full after first row - end this INSERT, start new one
            $sql .= $first_row_sql . ";";
            $this->current_sql_fragment = $sql;
            $this->state = self::STATE_START_INSERT;
        } else {
            // Continue this INSERT with more rows
            $sql .= $first_row_sql . ",";
            $this->current_sql_fragment = $sql;
            $this->state = self::STATE_EMIT_ROW;
        }

        return true;
    }

    /**
     * Emits a single row with appropriate terminator (, or ;).
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

        // Format the current row
        $formatted_values = [];
        foreach ($this->current_column_names as $col) {
            $value = $this->current_row[$col] ?? null;
            $data_type =
                $this->current_column_types[$col]["data_type"] ?? "varchar";
            $formatted_values[] = $this->format_value($value, $data_type);
        }
        $row_sql = "(" . implode(",", $formatted_values) . ")";

        // Clear current row (we've processed it)
        $this->current_row = null;
        $this->rows_in_batch++;

        // Fetch next row to determine terminator
        $has_next_row = $this->fetch_and_store_row();

        if (!$has_next_row) {
            // No more rows - end INSERT and move to next table
            $this->current_sql_fragment = $row_sql . ";";
            $this->state = self::STATE_NEXT_TABLE;
        } elseif ($this->rows_in_batch >= $this->batch_size) {
            // Batch is full - end this INSERT, start new one
            $this->current_sql_fragment = $row_sql . ";";
            $this->state = self::STATE_START_INSERT;
        } else {
            // Continue this INSERT
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
        $result = $this->db->query(
            "SHOW CREATE TABLE `{$this->current_table}`",
        );
        $row = $result->fetch(PDO::FETCH_ASSOC);

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
                "SELECT " . implode(", ", $select_parts) . " FROM `{$table}`";
        } else {
            $query = "SELECT * FROM `{$table}`";
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

        $db_name = $this->db->query("SELECT DATABASE()")->fetchColumn();
        $stmt = $this->db->prepare(
            "SELECT COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION",
        );
        $stmt->execute([$db_name, $table]);

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
        return json_encode([
            "current_table" => $this->current_table,
            "current_pk_columns" => $this->current_pk_columns,
            "last_pk_values" => $this->last_pk_values,
            "current_offset" => $this->current_offset,
            "state" => $this->state,
            "current_row" => $this->current_row,
            "rows_in_batch" => $this->rows_in_batch,
            "current_column_names" => $this->current_column_names,
        ]);
    }

    /**
     * Initializes the producer from a cursor.
     *
     * @param string $cursor JSON string (NOT base64-encoded). Must be valid JSON.
     * @throws InvalidArgumentException if cursor is not valid JSON
     */
    private function initialize_from_cursor($cursor)
    {
        $cursor_data = json_decode($cursor, true);
        if ($cursor_data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Invalid cursor format: cursor must be valid JSON. ' .
                'JSON error: ' . json_last_error_msg() . '. ' .
                'Received: ' . substr($cursor, 0, 100)
            );
        }
        if (is_array($cursor_data)) {
            $this->current_table = $cursor_data["current_table"] ?? null;
            $this->current_pk_columns =
                $cursor_data["current_pk_columns"] ?? null;
            $this->last_pk_values = $cursor_data["last_pk_values"] ?? null;
            $this->current_offset = $cursor_data["current_offset"] ?? 0;
            $this->state = $cursor_data["state"] ?? self::STATE_INIT;
            $this->current_row = $cursor_data["current_row"] ?? null;
            $this->rows_in_batch = $cursor_data["rows_in_batch"] ?? 0;
            $this->current_column_names =
                $cursor_data["current_column_names"] ?? null;

            // Initialize tables_to_process if not already set
            if ($this->tables_to_process === null) {
                $this->initialize_tables_to_process();

                // Position the array pointer to the current table
                if ($this->current_table) {
                    reset($this->tables_to_process);
                    while (
                        ($table = current($this->tables_to_process)) !== false
                    ) {
                        if ($table === $this->current_table) {
                            break; // Found it, pointer is now at current_table
                        }
                        next($this->tables_to_process);
                    }
                }
            }

            // Restore column types if we're in the middle of a table
            if ($this->current_table) {
                $this->current_column_types = $this->get_column_types(
                    $this->current_table,
                );
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

        $database_name = $this->db->query("SELECT DATABASE()")->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
        );
        $stmt->execute([$database_name, $table_name]);

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
}
