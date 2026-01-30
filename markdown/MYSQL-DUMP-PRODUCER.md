# MySQLDumpProducer

## Overview

`MySQLDumpProducer` is a refactored version of `DatabaseRowsReader` that outputs SQL fragments directly instead of raw row data. It maintains the same reentrant, cursor-driven semantics but produces ready-to-write SQL statements.

## Key Differences from DatabaseRowsReader

| Aspect | DatabaseRowsReader | MySQLDumpProducer |
|--------|-------------------|-------------------|
| **Output** | Raw row data as entities | SQL fragments (CREATE TABLE, INSERT) |
| **Entity Type** | `database_row`, `sql_query` | `sql_fragment` only |
| **Buffering** | No buffering (row-by-row) | Batches rows internally, emits complete INSERTs |
| **Metadata** | None | Stores column types internally |
| **Dependencies** | Just PDO | PDO + ColumnTypeCache + MysqlValueFormatter |

## Architecture

### State Machine

```
INIT
  ↓
NEXT_TABLE ←─────────────┐
  ↓                       │
CREATE_TABLE              │
  ↓                       │
TABLE_HEADER              │
  ↓                       │
ACCUMULATE_ROWS ←─┐       │
  ↓               │       │
  ├─ batch full? ─┤       │
  ↓               │       │
EMIT_INSERT ──────┴───────┤
  ↓                       │
  └─ more rows? ──────────┘
  ↓
FINISHED
```

### Internal Batching

The producer accumulates rows internally until the batch is full (default 250 rows), then emits a complete INSERT statement:

```php
// Accumulation phase (internal, not emitted)
Row 1 → accumulated_rows[]
Row 2 → accumulated_rows[]
...
Row 250 → accumulated_rows[]

// Emission phase (one call to next_sql_fragment())
→ Entity("sql_fragment", "INSERT INTO ... VALUES (...), (...), ...;")

// Batch cleared, accumulation resumes
Row 251 → accumulated_rows[]
...
```

## Usage

### Basic Iteration

```php
$producer = new MySQLDumpProducer($pdo, [
    'create_table_query' => true,
    'batch_size' => 250,
]);

while ($producer->next_sql_fragment()) {
    $entity = $producer->get_entity();
    $sql = $entity->get_data();

    // Write SQL fragment directly
    fwrite($output, $sql . "\n");
}
```

### With Cursor Resumption

```php
// Start or resume
$producer = new MySQLDumpProducer($pdo, [
    'cursor' => $saved_cursor ?? null,
    'batch_size' => 250,
]);

$fragments_processed = 0;

while ($producer->next_sql_fragment() && $fragments_processed < 100) {
    $entity = $producer->get_entity();
    fwrite($output, $entity->get_data() . "\n");
    $fragments_processed++;
}

// Save cursor for next run
if (!$producer->is_finished()) {
    $cursor = $producer->get_reentrancy_cursor();
    save_cursor($cursor);
}
```

## SQL Fragment Types

The producer emits different SQL fragments depending on the state:

### 1. CREATE TABLE Statement
```sql
--
-- Table structure for table `actor`
--

CREATE TABLE `actor` (
  `actor_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(45) NOT NULL,
  `last_name` varchar(45) NOT NULL,
  PRIMARY KEY (`actor_id`)
);
```

### 2. Table Header Comment
```sql

--
-- Dumping data for table `actor`
--
```

### 3. INSERT Statement (batched)
```sql
INSERT INTO `actor` (`actor_id`,`first_name`,`last_name`) VALUES
(1,'PENELOPE','GUINESS'),
(2,'NICK','WAHLBERG'),
...
(250,'JULIA','FAWCETT');
```

## Memory Characteristics

### Per-Fragment Memory Usage

| Component | Memory Usage |
|-----------|-------------|
| Accumulated rows | ~batch_size × row_size |
| Column metadata | ~num_columns × 100 bytes |
| Result set buffer | ~1000 rows (PDO fetch buffer) |
| SQL string generation | Temporary during emit |

**Total**: O(batch_size) relative to row size

### Compared to SqlDumpWriter

`SqlDumpWriter` used `InsertStatementBuilder` which had similar memory characteristics, but:
- **MySQLDumpProducer**: Generates SQL on-demand, one fragment at a time
- **SqlDumpWriter**: Buffered and wrote immediately with `fwrite()`

The producer separates SQL **generation** from **writing**, making it more flexible.

## Cursor State

The cursor includes:
- `current_table`: Which table we're processing
- `current_pk_columns`: Primary key structure
- `last_pk_values`: Last processed PK (for resumption)
- `current_offset`: Offset for tables without PKs
- `state`: Current state machine position
- `accumulated_rows`: Rows waiting to be emitted as INSERT
- `current_column_names`: Column order for current table

This allows **exact resumption** mid-batch.

## Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `create_table_query` | bool | true | Emit CREATE TABLE statements |
| `batch_size` | int | 250 | Rows per INSERT statement |
| `tables_to_process` | array | null | Specific tables (null = all) |
| `cursor` | string | null | Resume from cursor |

## Benefits Over Previous Architecture

### 1. Separation of Concerns
- **Generation** (MySQLDumpProducer) vs **Writing** (consumer's responsibility)
- Can test SQL generation without I/O
- Can write to different destinations (file, socket, HTTP response, etc.)

### 2. Simplified Error Handling
- Consumer can catch and handle write errors
- Producer focuses only on correct SQL generation
- Easier to add retry logic

### 3. Better Composability
```php
// Add headers manually
fwrite($output, "SET AUTOCOMMIT=0;\n");

// Use producer for data
while ($producer->next_sql_fragment()) {
    fwrite($output, $producer->get_entity()->get_data() . "\n");
}

// Add footers manually
fwrite($output, "COMMIT;\n");
```

### 4. True Streaming
Each `next_sql_fragment()` call produces exactly one SQL fragment:
- No hidden buffering
- Predictable memory usage
- Can rate-limit or pause between fragments

## Example: Streaming to HTTP

```php
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="dump.sql"');

$producer = new MySQLDumpProducer($pdo);

while ($producer->next_sql_fragment()) {
    echo $producer->get_entity()->get_data() . "\n";
    flush();

    // Rate limiting
    usleep(10000);
}
```

## Comparison: Processing 1 Million Rows

### Original Architecture (SqlDumpWriter)
```
DatabaseRowsReader → SqlDumpWriter → fwrite()
                     ↓
                InsertStatementBuilder
                     ↓
                MysqlValueFormatter
```
- SqlDumpWriter controls everything
- Tightly coupled to file I/O

### New Architecture (MySQLDumpProducer)
```
MySQLDumpProducer → Consumer (your code) → fwrite()/send()/etc.
       ↓
 MysqlValueFormatter
```
- Producer generates SQL
- Consumer decides what to do with it
- Loosely coupled, more flexible

## Integration with Existing Code

You can still use the simple approach:

```php
// Simple wrapper that works like SqlDumpWriter
$producer = new MySQLDumpProducer($pdo);

fwrite($output, "SET AUTOCOMMIT=0;\n");

while ($producer->next_sql_fragment()) {
    fwrite($output, $producer->get_entity()->get_data() . "\n");
}

fwrite($output, "COMMIT;\n");
```

Or create a dedicated writer class that consumes the producer.

## Future Enhancements

With this architecture, it's easy to add:
- **Compression**: Gzip each fragment before writing
- **Encryption**: Encrypt fragments on-the-fly
- **Network streaming**: Send directly to remote server
- **Progress tracking**: Count fragments, estimate completion
- **Filtering**: Transform/filter SQL before writing
- **Parallel processing**: Multiple producers, merge streams

## Testing

The producer can be tested without a database by mocking PDO, since it:
- Outputs pure SQL strings
- Has no I/O dependencies
- State is fully encapsulated in cursor

This makes unit testing much easier than the original SqlDumpWriter approach.
