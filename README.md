# WordPress Site Export - File Sync Architecture

## Usage

### Setup

1. Install the `wordpress-plugin` directory as a WordPress plugin on the source site.
2. Activate it and set a shared secret in the plugin settings (or in `secrets.php`).

### Full site migration

A complete migration has three steps: download the files, download the database, then
catch any files that changed while the database was being dumped.

All commands below use the same base invocation. We'll use `$URL` and `$DIR` as shorthand:

```bash
URL="https://example.com/?site-export-api"
DIR="./my-export"
SECRET="your-shared-secret"
```

**Step 0 — Preflight.** Make sure the server is reachable and the environment looks good:

```bash
php import.php preflight "$URL" "$DIR" --secret="$SECRET"
```

**Step 1 — Download files.** This first builds a full index of the remote directory tree,
then streams every file. It can be interrupted and resumed at any time — just re-run
the same command:

```bash
php import.php files-sync "$URL" "$DIR" --secret="$SECRET"
```

**Step 2 — Index the database.** This fetches table metadata (names, row counts, sizes)
into `db-tables.jsonl` for planning and diagnostics:

```bash
php import.php db-index "$URL" "$DIR" --secret="$SECRET"
```

**Step 3 — Download the database.** This streams a SQL dump into `db.sql`:

```bash
php import.php db-sync "$URL" "$DIR" --secret="$SECRET"
```

**Step 4 — Catch up on file changes.** While the database was being dumped, some files
may have changed. Running `files-sync` again auto-detects the completed sync and
performs a delta — only downloading the differences:

```bash
php import.php files-sync "$URL" "$DIR" --secret="$SECRET"
```

That's it. Your `$DIR` now contains `filesystem-root/` with the full directory tree
and `db.sql` with the database dump.

### CLI Commands

The importer client (`import.php`) accepts the following commands:

```
php import.php <command> <URL> <local-path> [options]
```

* `preflight` — Runs the preflight check and prints the full result as JSON. Exits with code 0 if OK, code 1 if not.
* `preflight-assert` — Runs the preflight check and prints a human-readable pass/fail summary. Exits with code 0 if migration looks feasible, code 1 if not.
* `files-sync` — Sync files. Auto-detects initial vs delta based on state: downloads the full tree on first run, only changes on subsequent runs.
* `files-index` — Optional. It's a standalone command to index the full remote file index without fetching file contents. `files-sync` does it implicitly
                  so this is mostly useful for testing and diagnostics.
* `db-sync` — Downloads the database as a SQL dump to `db.sql`.
* `db-index` — Indexes database tables and their statistics (name, row count, size) to `db-tables.jsonl`.

All commands except `preflight-assert` support `--abort` to abort the current sync and exit. For `files-sync`, this clears sync progress but keeps the local index and downloaded files — the next run performs a delta sync. For `db-sync` and `db-index`, it clears the output file so the next run starts from scratch. Interrupted commands automatically resume from the last saved cursor.

### Preflight

Before running any sync command, you must run a preflight check:

```
php import.php preflight <URL> <local-path> [options]
```

The preflight contacts the export server and collects environment details: PHP/MySQL versions, memory limits, filesystem access, database connectivity, WordPress version, plugins, themes, and directory layout. The result is stored in `.import-state.json` under the `preflight` key.

All other commands check that a preflight has been completed and refuse to start without one. Use `preflight-assert` instead if you only need a pass/fail exit code.

### Status file

When an external process (e.g. a web UI) needs to poll migration progress, it can read
`.import-status.json` in the output directory. This file is written atomically on every
state change, so readers never see a partial write.

Pass `--step=N` and `--steps=N` to embed pipeline position in the status file. For example,
a four-step pipeline would pass `--step=1 --steps=4` for the preflight, `--step=2 --steps=4`
for db-index, and so on.

The file contains a flat JSON object:

```json
{
  "step": 2,
  "steps": 4,
  "command": "files-sync",
  "status": "in_progress",
  "phase": "index",
  "error": null,
  "ts": 1707600000.123
}
```

| Field     | Type              | Description |
|-----------|-------------------|-------------|
| `step`    | `int \| null`     | Current pipeline step (1-indexed). `null` when `--step` is not passed. |
| `steps`   | `int \| null`     | Total pipeline steps. `null` when `--steps` is not passed. |
| `command` | `string \| null`  | Current command name (`preflight`, `files-sync`, `db-sync`, etc.). |
| `status`  | `string`          | One of `in_progress`, `partial`, `complete`, `error`, `aborted`. |
| `phase`   | `string \| null`  | Sub-phase within the command (e.g. `index`, `diff`, `fetch`), or `null`. Derived from the internal state's `stage` field. |
| `error`   | `string \| null`  | Error message when `status` is `error`, otherwise `null`. |
| `ts`      | `float`           | Unix timestamp with microsecond precision (`microtime(true)`). |

## Technical requirements

On the **migration source** side:

 - PHP 7.4+
 - ext-pdo + ext-pdo_mysql — database access (already in composer.json)
 - ext-json — JSON encoding/decoding
 - ext-hash — hash_hmac, hash_equals
 - ext-zlib — deflate_init/deflate_add for gzip streaming

On the **migration target** side:

 - PHP 8.1+ (we'll get down to 7.4 if needed)
 - ext-curl


## File synchronization

### Synchronization approach

The system is designed to perform an initial directory tree synchronization followed by incremental updates.

**Initial synchronization** is when the **migration target** requests a stream of files from the **migration source**
without having any prior state. The migration target sends an HTTP request to the migration source and asks to start
a new synchronization of one or more root directory paths. The migration source responds immediately with a multipart
stream and a cursor for resumption. All subsequent requests are stateless and use the cursor only.

The migration target then sends a HTTP request asking for the next batch of files. The migration source immediately starts
streaming the requested directory trees using pre-order traversal. The HTTP response is a multipart/mixed data stream listing
every file, symlink, and an empty directory in the requested root directory. Every chunk carries file metadata, content bytes
of the file, and a cursor pointing to the next chunk.

The **migration source** keeps track of two resource budgets: memory and execution time limit. Once it starts approaching
either of them, it ends the response and the migration target needs to send another HTTP request. This means, the initial
synchronization request will most likely be concluded before all the files are transferred.

Once the first HTTP request is completed, the migration target sends another HTTP request to the migration source asking for the next
batch of files. It provides the cursor from the previous response. The migration source then responds
with the next batch of files. This repeats until the initial synchronization is complete.

### Synchronization Cursor

The cursor consists of the file path, ctime, and byte offset. When provided, the migration source will resume the traversal
from the given point. If the cursor is not provided, the migration source will stream from the beginning of the first requested
root directory.

`ctime` is not strictly required for traversal, but the migration source can use it to tell if the streamed file has been modified
since the last synchronization. If it has, the migration source will communicate that via a dedicated multipart chunk and move on to
the next file.

### Migration index

As the migration target receives files from the migration source, it builds a local index of all the paths, ctimes, and filesizes
it has seen. The on-disk index is a sorted JSON-lines file, where each line is a JSON object containing `path`, `ctime`, `size`,
and `type`. This lets us safely store any filename (including tabs and newlines) without escaping hacks. Later on, we use this index
for incremental synchronization to get files changed since the last sync.
Here's how that works now:

1. The migration target requests an **index-only** stream from the migration source and stores it locally. The index batches are JSON arrays.
2. The migration target advances through both lists (local index and remote index file) using a two-pointer diff:
   - Deletes local files that no longer exist remotely.
   - Builds a download list for new/changed files.
3. The migration target uploads the download list to the migration source and streams just those files.

This keeps the server stateless, keeps all large lists streamed (no full buffering), and guarantees ordering using `strcmp`-equivalent
binary collation on both sides.

What we **don't** do:

* Upload the local index to the server for diffing. An earlier 
  version did this, but it increased the complexity, the 
  resource requirements on the other end, and some variants of
  it required keeping a local state on the remote site which is
  undesirable.

### Directory listing order

The file index is produced by traversing directories depth-first. Each directory's immediate entries are sorted in bytewise
lexicographic order (equivalent to `strcmp` with `LC_ALL=C`). When a directory entry is encountered, that directory entry is
emitted, and then we descend into it before moving to the next sibling entry. This makes the output deterministic while keeping
the cursor small and resumable.

What we **don't** do:

* Breadth-first traversal. It is tempting, as we can just get to a directory, scan it, emit all its children, and move on to the next directory.
  It could be significantly faster in case we'd ever see xxx,xxx subfolders in a directory. With the depth-first traversal, every time we pause
  and resume on the next request, we'll have to sort those xxx,xxx subfolders. We're talking about 0-3 seconds on every such request, which may
  not be a big deal for a site of this size. It's still worth describing here, thought. The problem with breadth-first traversal is reentrancy.
  We'd need to keep the directories we've already seen but haven't yet traversed across the entire tree level. We'd keep them in memory and,
  potentially, in the cursor. That could take up some space!
* Stream sorting either inside PHP or via a shell call to `find ./ | sort`. While it would use less memory, the PHP version would be noticeably
  slower and more complex. The shell call would also slow down the entire process because of its sheer overhead when listing 99% of the typical
  smaller directories. Furthermore, we could never be sure whether the shell call results can be trusted – find and sort could differ between
  runtimes to the point where some runtimes replace them with stubs. PHP functions are much more portable. Large sites should have large memory
  banks. If they don't, then we can revisit the stream-sorting approach.
* Order the traversal by `(ctime, filename)`. It wouldn't save us much work, as we'd still need to run a full scan of the filesystem
  on every incremental synchronization to detect deletions. On top of that, it is impractical. First, you need to always sort the
  entire directory tree by ctime before you can start streaming the data. That may take some time and HTTP requests in PHP land. Second,
  there is no clear stop for the traversal. Any file that keeps changing will keep moving to the end of the queue, and meanwhile the files
  we have already seen may also get their ctime updated.
* Use a `DirectoryListing` abstraction class. We tried one and removed it — plain `scandir()` is simpler
  and the abstraction added complexity without benefit.

### Volatile files

Sometimes a file will keep changing every minute and we'll start streaming it, but won't finish before it's modified again. In that case,
the **migration target** chooses how to handle it. A few choices are:

* Stop the migration, tell the user we can't migrate this file (or multiple files).
* Stop the migration, ask the user if that file is okay to ignore. Chances are it's a log, a cache, or some other volatile artifact
  that doesn't matter that much.
* Retry a few more times.
* Just ignore that file (and tell the user).

### Symlink handling

Symlinks are automatically recreated during import. The importer receives symlink chunks from the
export stream and calls `symlink()` to recreate them locally. This is safe because all paths are
constrained to the import directory's `filesystem-root/`.

Some symlinks may point to places on the remote filesystem that are
outside of the requested directory root. When that happens, they're
not recreated unless you use the `--follow-symlinks` option.

With the `--follow-symlinks`, the importer will create local
symlinks even if they point ourside of the directory root. They're
still constrained within the confines of the `filesystem-root/`

## Database synchronization

### SQL dump approach

MySQLDumpProducer generates SQL dumps in batches (default 250 rows per INSERT statement) with cursor-based
resumption. The dump is standard SQL — `DROP TABLE IF EXISTS`, `CREATE TABLE`, and multi-row `INSERT`
statements — directly importable with `mysql` CLI or any standard tool.

The cursor tracks the last row processed using either the primary key,
when available, or offset otherwise.

### Primary key strategies

The producer handles three scenarios:

* **Simple PK**: Uses the last PK value as cursor. Resumption is a simple `WHERE pk > value`.
* **Composite PK**: Uses all PK columns in the cursor. Resumption uses a tuple comparison.
* **No PK**: Falls back to OFFSET-based pagination. This is slower (MySQL must skip rows on each resume)
  but is the only option when there's no stable key to anchor the cursor.

### Oversized rows

Rows whose encoded size exceeds the statement size limit need special handling. With a primary key,
the producer skips the oversized row and advances the cursor past it — the row is lost but the dump
can continue. Without a primary key, the producer fails entirely. OFFSET-based pagination can't reliably
skip a single row without risking data loss, so failing loudly is the safer choice.

### Binary and string data encoding

Binary and string column data is encoded as base64 in the SQL dump (wrapped in `FROM_BASE64()`). Earlier iterations
tried raw hex encoding and escaped binary. Base64 was chosen as the
most conscise option.

### Statement size negotiation

The client detects its local MySQL's `max_allowed_packet`, sends it to the server via the
`--max-allowed-packet` option, and the server caps SQL statements at `min(client, server) * 0.8`.
This prevents the dump from producing statements the migration target MySQL can't execute.

What we **don't** do:

* Transmit data as JSON or a binary serialization format and reconstruct SQL locally. This would add
  complexity and make the REST endpoints harder to debug. The SQL-over-HTTP approach means the dump
  is directly importable with standard MySQL tools. Still, it would
  be a nice optional feature to add.

## Authentication

Every request is authenticated with HMAC signatures computed based
on the request content and a shared secret.

## Error handling

Streaming endpoints use try/catch with error chunks embedded inline in the multipart stream. When something
goes wrong mid-response, the client sees the error as another multipart chunk rather than the default PHP 
output such as "Fatal Error". Global error and shutdown handlers.

## Resource management

We need to be careful about the resource usage or we risk web hosts blocking us.

Most WordPress sites are on low-end servers and have limited resources at their disposal.
Some hosts monitor the usage and block sites that use too much CPU or memory. Since we're using
PHP as a site export backend, we'll naturally use more resources than we'd need if we did it over SSH.
We need to use network connections and block PHP workers, we need to run PHP programs that are slower
than their C counterparts, and so on.

This is why we're budgeting our resource usage in a few ways:

* Execution time limit on the remote host – it gracefully ends the request once we exceed the budget.
* Memory usage limit on the remote host – it gracefully ends the request once we exceed the budget.
* Request backoff to make space for other requests
* Per-endpoint size caps and adaptive sizing, since files, indexing, and SQL have different cost profiles.

The exporter almost always uses the full server time budget, so the tuner does not try to make responses
shorter. Instead it measures server-reported runtime plus the amount of work done (bytes streamed, index
entries emitted, SQL bytes dumped), keeps a throughput EMA per endpoint, and applies an AIMD loop: small
additive increases when throughput is stable, and multiplicative decreases when throughput drops. This
lets fast hosts grow steadily while slow hosts back off quickly.

We also detect likely buffering (TTFB ≈ server runtime) and enter a conservative mode that clamps maximum
sizes. Any non-2xx/3xx response or timeout triggers error backoff and an immediate size cut. Separately, a
duty-cycle sleep (with jitter) spaces requests so we don't monopolize PHP workers or synchronize multiple
migrations on the same host.

At the start of each run the importer performs a cheap preflight request. It records PHP and MySQL versions,
memory limits, filesystem accessibility, and database charset/collation in the audit log and state file so
we have context when debugging slow hosts or permission issues.

What we **don't** do:

* Use usleep on the exporting side. We could spread the CPU usage over time with something
  like `while(!$done) { small_unit_of_work(); usleep($some_time); }`, but that would hold a PHP worker busy for longer.
  Some hosts only run **two** PHP workers. Introducing a `usleep()` would keep one of them busy for longer,
  making the site availability strangled for longer. Instead, we try to use shorter requests that do less work,
  and use a client-side waiting strategy between those requests.
* Tune based on client download time or wall-clock time. The client might be slower than the server, or a fast
  connection could hide server pressure. We only tune based on server-reported runtime and the amount of work
  done (bytes streamed, index entries emitted, SQL bytes dumped).
* Aim for a fixed target runtime. The exporter almost always runs until its time budget expires, so the meaningful
  signal is throughput under that budget, not how close we got to an arbitrary time goal.

## Transport

Data is sent over HTTP using multipart/mixed content-type. It gives us a way to split large files into chunks and send
them over multiple requests, while transmitting per-chunk metadata (cursor, chunk size, etc.).

Why not ZIP or TAR? Because:

* We need to split large files into chunks, and there is no native way of expressing "this entry is a chunk
  of a larger file" in ZIP or TAR.
* We could treat the entire export as a single, huge data stream split over multiple requests and assume that
  a single zip/tar entry, no matter how large, is always a single file. However, we wouldn't have a good way of
  knowing we've lost some bytes from the middle of the stream – at least until we've transferred the entire file.
* We wouldn't have a good way of sending the cursor to the client. Where would it come in the stream?

Why not the git protocol? We're effectively exchanging diffs here. Git already does that. But git can't
recover from a broken exchange — you need to start from scratch. Also, the git protocol is complex.
Multipart is simple and native to HTTP clients.

## Next steps

* Possibly run more checks in `preflight-assert`. What would they be?
* Make sure we can ctrl+c the process without hanging if we don't have pcntl and posix extensions.
* More tests for large files and large databases.
* Support SQLite sites.
* Confirm the importer never sends a request larger than the allowed PHP
  limits, or, if it does and the response indicates that, it backs off
  and tries a smaller body size. Also, make sure the body's gzipped.
* Importer — emit dedicated errors when we run out of disk space or DB space on the importing end.
  We can be reactive (detect out-of-space errors when they happen) since we won't know the storage
  quota upfront in most shared hosting environments.
* Symlink handling — use a single bulk request to index all symlink targets instead of one request
  per symlink.
* Sites using multiple databases (either multiple MySQL instances or also Postgres, Redis, etc.)
* Rewrite URLs in the incoming files. We have the tools to do it, but version 1 is about migrating between hosting
  providers without changing the domain. We can parse and rewrite CSS, HTML, XML, JavaScript, JSON, and PHP
  because we have structured parsers and tokenizers for all these formats, but it's a major scope creep.
* Support for directories with more files than we can sort in memory at once. A million files with 64 byte names
  require around 100MB of memory to sort. If you have so many files, you better have that much memory available.
