# MySQL Export Architecture

## Component Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                        export-mysql.php                          │
│                     (Command-line script)                        │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             │ creates
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                     DatabaseRowsReader                           │
│                   (Existing, unchanged)                          │
│                                                                   │
│  • Reads tables one by one                                       │
│  • Chunks rows (1000 at a time)                                  │
│  • Emits Entity objects                                          │
│    - sql_query (CREATE TABLE)                                    │
│    - database_row (table + record)                               │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             │ consumed by
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                      SqlDumpWriter                               │
│                   (Main orchestrator)                            │
│                                                                   │
│  • Writes SQL headers                                            │
│  • Routes entities:                                              │
│    - sql_query → write CREATE TABLE                             │
│    - database_row → accumulate in builder                       │
│  • Manages builder lifecycle                                     │
│  • Writes SQL footer                                             │
└──────────┬──────────────────────────┬───────────────────────────┘
           │                          │
           │ uses                     │ uses
           ▼                          ▼
┌──────────────────────┐   ┌──────────────────────────────────────┐
│  ColumnTypeCache     │   │   InsertStatementBuilder             │
│                      │   │                                       │
│  • Queries schema    │   │  • Accumulates rows                  │
│  • Caches types      │   │  • Builds multi-row INSERT           │
│  • Returns metadata  │   │  • Flushes when batch full           │
└──────────┬───────────┘   └──────────┬───────────────────────────┘
           │                          │
           │ provides types           │ uses
           │                          ▼
           │               ┌──────────────────────────────────────┐
           └──────────────►│   MysqlValueFormatter                │
                           │                                       │
                           │  • Formats values by type             │
                           │    - NULL → NULL                      │
                           │    - Numeric → raw                    │
                           │    - Binary → 0xHEX                   │
                           │    - String → PDO::quote()            │
                           └───────────────────────────────────────┘
```

## Data Flow

```
1. User runs export-mysql.php
   ↓
2. Script creates DatabaseRowsReader with create_table_query=true
   ↓
3. Script creates SqlDumpWriter with reader + output stream
   ↓
4. SqlDumpWriter.write() starts:
   ├─ Writes SQL header
   │
   ├─ Loop: while reader.next_sql_fragment()
   │  │
   │  ├─ If entity type = "sql_query":
   │  │  ├─ Flush any pending INSERT
   │  │  └─ Write CREATE TABLE statement
   │  │
   │  └─ If entity type = "database_row":
   │     ├─ Check if new table:
   │     │  ├─ Flush previous builder
   │     │  ├─ Get column types from ColumnTypeCache
   │     │  └─ Create new InsertStatementBuilder
   │     │
   │     ├─ Add row to builder
   │     │
   │     └─ If builder.should_flush():
   │        ├─ Format each value with MysqlValueFormatter
   │        ├─ Generate INSERT statement
   │        └─ Write to output stream
   │
   ├─ Flush final builder
   │
   └─ Write SQL footer
```

## Class Responsibilities

### MysqlValueFormatter (Static Utility)
**Purpose**: Format individual values based on MySQL data type

**Key Methods**:
- `format_value($value, $data_type, $pdo)` → string

**Logic**:
1. Check if NULL → return "NULL"
2. Check if numeric type → return raw value
3. Check if binary type → return hex encoding
4. Otherwise → return PDO::quote($value)

### ColumnTypeCache (Metadata Service)
**Purpose**: Query and cache column type information

**Key Methods**:
- `__construct($pdo)`
- `get_column_types($table_name)` → array

**Logic**:
1. Check cache for table
2. If not cached:
   - Query INFORMATION_SCHEMA.COLUMNS
   - Store in cache array
3. Return column metadata

### InsertStatementBuilder (Statement Generator)
**Purpose**: Accumulate rows and generate batched INSERT statements

**Key Methods**:
- `__construct($table, $column_types, $pdo, $batch_size)`
- `add_row($record)` → void
- `should_flush()` → bool
- `has_rows()` → bool
- `flush()` → string (SQL statement)

**Logic**:
1. Accumulate rows in array
2. When batch is full:
   - Format each value using MysqlValueFormatter
   - Build multi-row VALUES clause
   - Return complete INSERT statement
   - Clear batch

### SqlDumpWriter (Orchestrator)
**Purpose**: Consume entities and orchestrate the export

**Key Methods**:
- `__construct($reader, $output, $pdo, $batch_size)`
- `write()` → void

**Logic**:
1. Write header
2. For each entity:
   - CREATE TABLE → write directly
   - Data row → accumulate in builder
3. Write footer

## Type Detection Strategy

```
PHP PDO Result
     ↓
  (All values come as strings or native PHP types)
     ↓
     ↓ But we need actual MySQL types!
     ↓
INFORMATION_SCHEMA.COLUMNS
     ↓
  (Query once per table)
     ↓
Cache: {
  "actor_id": {"data_type": "smallint", "column_type": "smallint(5) unsigned"},
  "first_name": {"data_type": "varchar", "column_type": "varchar(45)"},
  "last_update": {"data_type": "timestamp", "column_type": "timestamp"}
}
     ↓
  (Use data_type for formatting decision)
     ↓
MysqlValueFormatter.format_value()
     ↓
Correct SQL representation
```

## Memory Efficiency

```
Database (millions of rows)
     ↓
DatabaseRowsReader
     ├─ Reads 1000 rows at a time
     ↓
InsertStatementBuilder
     ├─ Batches 250 rows (configurable)
     ↓
SqlDumpWriter
     ├─ Writes immediately with fwrite()
     ↓
Output stream (file or stdout)
     ├─ No buffering of entire database
     ↓
Memory usage: O(1000 + 250) = O(1) relative to database size
```

## Batching Example

```sql
-- First batch (250 rows)
INSERT INTO `actor` (`actor_id`,`first_name`,`last_update`) VALUES
(1,'PENELOPE','2006-02-15 04:34:33'),
(2,'NICK','2006-02-15 04:34:33'),
...
(250,'SPENCER','2006-02-15 04:34:33');

-- Second batch (250 rows)
INSERT INTO `actor` (`actor_id`,`first_name`,`last_update`) VALUES
(251,'WILLIAM','2006-02-15 04:34:33'),
(252,'JULIA','2006-02-15 04:34:33'),
...
(500,'MARIA','2006-02-15 04:34:33');

-- Final batch (remaining rows)
INSERT INTO `actor` (`actor_id`,`first_name`,`last_update`) VALUES
(501,'GARY','2006-02-15 04:34:33'),
...
(512,'BEN','2006-02-15 04:34:33');
```

## Extension Points

Want to customize the behavior? Here are the key extension points:

1. **Custom value formatting**: Extend `MysqlValueFormatter`
2. **Different batch sizes**: Pass `batch_size` parameter
3. **Table filtering**: Modify `DatabaseRowsReader` options
4. **Output format**: Extend `SqlDumpWriter` to change headers/footers
5. **Compression**: Wrap output stream with compression filter

## Performance Characteristics

| Aspect | Complexity | Notes |
|--------|-----------|-------|
| Time | O(n) | n = number of rows |
| Space | O(1) | Constant memory relative to DB size |
| I/O | Streaming | Writes as it processes |
| Network | Minimal | One schema query per table |

## Error Handling

```
DatabaseRowsReader
     ↓ PDO exceptions
     ├─ Connection errors
     ├─ Query errors
     └─ Propagate to main script

SqlDumpWriter
     ↓ File I/O errors
     ├─ fwrite() failures
     └─ Propagate to main script

export-mysql.php
     ↓ Catches all exceptions
     ├─ Write error to STDERR
     └─ Exit with code 1
```

## Thread Safety

Not thread-safe by design:
- PDO connections are not thread-safe
- Output stream writes are not atomic
- Designed for single-threaded export process

For parallel exports, run multiple processes with different table filters.
