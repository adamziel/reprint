# AGENTS.md

Instructions for AI coding agents working on this repository.

## What this project does

Reprint is a WordPress site migration tool. It exports a WordPress site's files and database over HTTP from a source server, and imports them into a target environment. The entire process is resumable — every operation uses cursor-based pagination and can be interrupted and restarted without data loss.

## Repository structure

```
packages/reprint-exporter/src/     – Export engine (runs on the source WordPress site)
  export.php                       – HTTP API: authentication, routing, file indexing, file fetching, SQL dumping
  class-file-tree-producer.php     – Streams directory trees with cursor-based resumption
  class-mysql-dump-producer.php    – Generates SQL dump fragments with batched INSERTs
  class-http-server.php            – HTTP server helpers (multipart streaming, resource budgeting)

packages/reprint-importer/src/     – Import client (runs on the target)
  import.php                       – CLI entrypoint: preflight, files-sync, db-sync, db-apply, apply-runtime
  lib/host/                        – Host analyzers: detect source hosting provider, produce RuntimeManifest
  lib/target-runtime/              – Runtime appliers: consume RuntimeManifest, write server config files
  lib/url-rewrite/                 – URL rewriting for db-apply
  lib/mysql-query-stream/          – Streaming MySQL query parser

reprint-exporter-wp/               – WordPress plugin wrapping the exporter
tests/                             – PHPUnit tests (organized by component)
tests/e2e/                         – Docker-based E2E tests (49 scenarios)
markdown/                          – Architecture documentation
```

## How to run tests

```bash
composer test               # All PHPUnit tests
composer test:fast           # Skip large dataset tests
composer test:large          # Only large dataset tests
composer analyze             # PHPStan static analysis

# E2E (requires Docker)
cd tests/e2e
npm run test -- tests/import-01-basic-file-sync.test.js   # Single scenario
./run-all-tests.sh                                         # All scenarios
```

PHPUnit tests need a MySQL server. Default connection: `root:my-secret-pw@127.0.0.1/test_mysql_dump` (override via `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` env vars).

## Key concepts to understand before making changes

### Cursor-based reentrancy

Every producer (SQL dump, file tree, file index) can pause mid-stream and resume later via a JSON cursor. The cursor encodes the complete state: current position, accumulated batch, last processed key. Cursors are JSON internally, base64-encoded for HTTP headers. Never pass base64 to producer constructors — `export.php` handles encoding.

### Resource budgeting

The exporter tracks memory and execution time limits. When approaching either limit, it ends the response gracefully with a cursor for resumption. This is non-negotiable — shared hosting environments kill processes that exceed limits.

### Multipart/mixed transport

HTTP responses use multipart/mixed content-type. Each part carries metadata (cursor, path, size) in headers and content bytes in the body. Large files are split across multiple HTTP requests. The importer's `MultipartStreamParser` processes chunks incrementally without buffering entire responses.

### Server-side directory dedup

The file indexer uses `should_skip_index_root()` (in `export.php`) to prevent duplicate traversal. Each directory's `realpath()` is checked against configured roots — duplicates, parents of scheduled roots, and symlink cycles are all skipped. This is essential for WP.com Atomic sites where symlinks create infinite loops.

### Host analyzer → RuntimeManifest → Runtime applier

The `apply-runtime` command has a three-stage pipeline:
1. **Host analyzer** reads preflight data, detects the source hosting provider
2. **RuntimeManifest** is a pure-data intermediate representation (constants, INI, routes, paths_to_remove, extra_directories)
3. **Runtime applier** writes server-specific config files for the target

Adding a new source host or target runtime means implementing one interface without touching the other.

### SQL crash recovery

When the export server crashes mid-SQL-stream, the importer detects transport failures (missing completion chunk, curl errors), saves its cursor, persists accumulated SQL in `.sql-buffer`, and exits with code 2. The retry loop reloads the buffer and continues. The `finally` block never masks the original exception.

## Patterns to follow

### Testing pattern

Every test follows: setup → export → assert → round-trip import → verify integrity. If you add a feature, add a test that proves data survives a full round-trip.

### E2E test naming

E2E tests are numbered sequentially: `import-NN-description.test.js`. Check the highest number before adding a new one.

### Exit codes

CLI commands return 0 (done), 1 (failure), or 2 (partial — needs re-running). Callers wrap commands in a loop that retries on exit code 2. If you add error handling, make sure transient errors produce exit code 2, not 1.

### Audit logging

Significant events go to `.import-audit.log` via `audit_log()`. Always log operations that modify the filesystem or skip expected work (file deletions, volatile files, skipped paths).

## Things that are easy to get wrong

- **Cursor encoding**: Producers expect JSON cursors. `export.php` handles base64 wrapping for HTTP. Mixing these up produces silent failures.
- **Memory limits**: Large dataset tests need 512MB+ PHP `memory_limit`. If tests OOM, check this first.
- **WP.com Atomic layout**: ABSPATH ≠ document root. The document root (`/srv/htdocs/`) contains `wp-content/` while ABSPATH points to `__wp__/` (shared WordPress core). Always test with multi-root configurations.
- **Symlink cycles**: `/srv/htdocs/srv` → `/srv` is a real pattern on Atomic sites. Any directory traversal code must handle this or it will loop forever.
- **`finally` blocks and exceptions**: PHP replaces the in-flight exception when `finally` throws. Always check for an existing exception before throwing in `finally`.
- **Atomic file writes**: State files (`.import-state.json`, `.import-status.json`) are written via temp file + rename. Never write them directly or a crash mid-write corrupts state.
