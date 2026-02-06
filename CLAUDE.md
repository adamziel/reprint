# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress site export/import system that enables resumable, cursor-based synchronization of both database content and filesystem data over HTTP. The system is designed to work on resource-constrained shared hosting environments by carefully managing memory and execution time.

## Core Architecture

The codebase follows a producer-consumer pattern with two main components:

### Export Side (Server)
- **export.php**: HTTP endpoint that serves as the export API, handling authentication and routing requests to the appropriate producer
- **MySQLDumpProducer**: Generates SQL dump fragments with cursor-based resumption, supporting batched INSERT statements and all MySQL data types
- **FileTreeProducer / FileListProducer**: Streams filesystem contents (full tree or explicit list) in chunks with support for symlinks and cursor-based resumption

### Import Side (Client)
- **import.php**: CLI script that downloads from export.php using streaming multipart parsing, no buffering of entire response
- **MultipartStreamParser**: Incremental multipart/mixed parser that processes chunks as they arrive

### Supporting Classes
- **MysqlValueFormatter**: Formats MySQL values by type (NULL, numeric, binary hex, quoted strings)
- **ColumnTypeCache**: Queries and caches INFORMATION_SCHEMA.COLUMNS data
- **FileSnapshotStorage / SqliteSnapshotStorage**: Pluggable snapshot storage for deletion tracking

## Key Design Patterns

### Cursor-Based Reentrancy
Both producers support pausing and resuming via JSON cursors that encode complete state:
- Current table/file position
- Accumulated rows/chunks waiting to emit
- Last processed primary key values or byte offsets

Cursors are JSON strings internally, base64-encoded for HTTP transmission in X-Cursor (outgoing) and X-Export-Cursor (incoming) headers.

### Resource Budgeting
The system tracks memory and execution time limits to gracefully end requests before hitting host limits. This prevents process termination and allows resumption.

### Streaming Multipart Transport
Uses multipart/mixed content-type to split large files into chunks while transmitting per-chunk metadata (cursor, size, path). This allows splitting arbitrary-sized files across multiple HTTP requests.

## Development Commands

### Running Tests

```bash
# Run all PHPUnit tests
composer test

# Run only fast tests (skip large dataset tests)
composer test:fast

# Run only large dataset tests
composer test:large

# Run with coverage (requires Xdebug)
composer test:coverage

# Run specific test file
cd tests && vendor/bin/phpunit MySQLDumpProducer/BasicDumpTest.php

# Run specific test method
cd tests && vendor/bin/phpunit --filter testRoundTripIntegrity
```

### Running E2E Tests

```bash
# From tests/e2e directory
cd tests/e2e

# Verify Docker and dependencies are installed
./verify-setup.sh

# Run all end-to-end scenarios
./run-all-tests.sh

# Run a single scenario
cd scenarios/vanilla-wp && ./run-test.sh
```

### Database Configuration

Tests use environment variables defined in tests/phpunit.xml:
- DB_HOST (default: 127.0.0.1)
- DB_USER (default: root)
- DB_PASS (default: my-secret-pw)
- DB_NAME (default: test_mysql_dump)

Override with environment variables if needed.

## Important Implementation Details

### Symlink Security

Symlinks are NOT automatically recreated during import for security reasons (directory traversal, absolute path exploits). Instead, they are recorded in a `symlinks.json` manifest file that users must review and manually recreate if appropriate. See markdown/SYMLINKS.md for details.

### SQL Dump Batching

MySQLDumpProducer accumulates rows internally (default 250 rows per batch) and emits complete multi-row INSERT statements. This is memory-efficient and produces dumps compatible with standard MySQL import tools.

### File Synchronization Phases

FileTreeProducer operates in three phases visible via get_progress():
1. **scanning**: Directory traversal to enumerate files
2. **sorting**: Sorting files by (ctime, path) for deterministic ordering
3. **streaming**: Emitting file chunks with resumption support

### Primary Key Handling

MySQLDumpProducer supports multiple primary key scenarios:
- Simple PK: Uses last PK value for cursor
- Composite PK: Uses all PK columns in cursor
- No PK: Falls back to OFFSET-based pagination (less efficient)

### Test Database Isolation

PHPUnit tests automatically create/drop test databases. The naming convention is:
- Export database: `test_mysql_dump`
- Import database: `test_mysql_dump_import`

## File Organization

- Root PHP files: Main export/import scripts and producer classes
- markdown/: Architecture documentation (read these for deep understanding)
- tests/: PHPUnit test suite organized by component
- tests/e2e/: End-to-end Docker-based integration tests
- exports/: Git-ignored directory for test exports

## Testing Philosophy

Every test follows a 5-step pattern:
1. Setup: Create tables and insert test data
2. Export: Generate SQL dump or file sync
3. Assert: Verify output contains expected content
4. Round-trip: Import to new database/directory
5. Verify: Compare original and imported data for integrity

This ensures SQL is correct, valid, and preserves data without loss or corruption.

## Common Gotchas

- **Cursor encoding**: Producers work with JSON strings. export.php handles base64 encoding for HTTP. Never pass base64 to producer constructors.
- **Memory limits**: Large dataset tests require at least 512MB PHP memory_limit
- **Execution time**: LargeDatasetReentrancyTest processes 200,000+ rows and may take 30-60 seconds
- **MySQL version**: Minimum MySQL 5.7 required (for JSON type support)
- **Character encoding**: Tests assume utf8mb4 support

## Documentation

Comprehensive architecture docs are in markdown/:
- ARCHITECTURE.md: MySQL export component diagram and data flow
- FILESYNC-USAGE.md: Complete FileTreeProducer API guide
- SYMLINKS.md: Symlink handling and security model
- MYSQL-DUMP-PRODUCER.md: MySQLDumpProducer architecture and usage

Always consult these when working on the respective components.

## CLI API Design

### Progress Computation

Progress is computed client-side by reading state files:
- `.import-state.json`: Current command, status, cursor, stage
- `.import-index.jsonl`: Local file index (line count = files indexed)
- `.import-remote-index.jsonl`: Remote file index (for delta comparison)
- `.import-download-list.jsonl`: Files pending download
- `filesystem-root/`: Actual downloaded files (recursive size/count)
- `db.sql`: SQL dump file size

This keeps the protocol minimal while enabling rich progress visualization.
