You're my advisor now. Look into export.php class-directory-listing.php class-mysql-dump-producer.php file-sync.php and help me with this problem:

I'm building a tool to migrate WordPress sites over http. I have the code to stream files and sql in chunks, it has a very low memory usage. Every HTTP requests transmits a chunk of the site and you need multiple HTTP requests to migrate everything. I'll sometimes be migrating from low-end hosts that only run two PHP workers. I need to find a way of budgeting my resources to keep the site functional, prevent very high resource usage spikes, and keep the host happy. I've already gotten myself banned from Bluehost once. I don't want my customers to have this experience.

I'm using a few types of operations now:
* readdir
* stat on files
* fread for various ranges of files
* SQL SHOW TABLES, SHOW CREATE TABLE, and SELECT queries

I try to be mindful of those resources and don't run twice anything I don't have to run twice. Also, I gzip all my responses.

I also have some knobs I can turn:

* Max memory usage
* Max execution time (the request handler will gently stop after this many seconds and provide a cursor for resuming later on)
* How frequently I re-request the site.

It's always one request at a time. It's pretty serial. We could get faster on better hosts with parallelization, but that's not the problem we're solving right now. Right now we need to always work on low-end hosts.

What I may have missed in my design? Could I introduce some other knobs or ways to reduce the memory usage? What should be my request backoff strategy? 

----------------------------------------


• I skimmed export.php, class-directory-listing.php, class-mysql-dump-producer.php, and file-sync.php. You already have a good streaming shape. The biggest “missed” risks are
  a few hidden CPU/memory spikes and some expensive I/O patterns that will hurt on low‑end hosts.

  High‑risk spikes / hidden costs

  - DirectoryListing::sort() loads all entries into memory via stream_get_contents() before sorting. On large dirs (uploads), this can spike memory and CPU even though scan()
    uses php://temp. This is in class-directory-listing.php.
  - For very large BLOB/TEXT columns, format_row_for_insert() formats entire values to compute size, then decides they’re oversized. That means you still allocate huge quoted/
    encoded strings before chunking. This can blow memory on big rows. This is in class-mysql-dump-producer.php.
  - File traversal does multiple stat-like calls per entry (is_link, is_dir, is_file, filectime, filesize), plus a second filectime/filesize on EOF. That’s a lot of syscalls.
    See file-sync.php.
  - SQL batching in endpoint_sql_chunk() accumulates fragments_per_batch into an array, then implode() before emitting, which is a memory spike proportional to batch size. See
    export.php.
  - endpoint_file_fetch() reads the entire paths list into an array, and paths are sorted in memory. Big lists can spike memory. See export.php and file-sync.php.
  - Tables without PK use LIMIT … OFFSET …, which degrades badly as offset grows. That can cause long DB time on large tables. See class-mysql-dump-producer.php.

  Knobs you can add (low‑risk, big impact)

  - compression_level and compress boolean. Gzip at level 6 on already‑compressed files wastes CPU. Consider gzip only for SQL/index responses or allow compress=0. export.php
    (GzipOutputStream).
  - max_sql_chunk_bytes or max_sql_batch_bytes to cap SQL memory usage, not just fragments_per_batch. Emit smaller parts or drop Content-Length for SQL parts and stream
    directly.
  - max_dir_entries_per_request or max_dir_scan_time so a single request doesn’t spend all its time sorting/iterating a huge directory before emitting anything.
  - max_files_per_request or max_bytes_per_request for file mode, independent of max_execution_time.
  - db_unbuffered to set PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=false (lower memory).
  - db_query_time_limit (MySQL MAX_EXECUTION_TIME) on SELECT for hosts that support it.
  - disable_change_check to skip the extra clearstatcache/filectime/filesize at EOF on low‑end hosts.
  - stat_mode=single to use one lstat()/stat() per entry and derive type, size, ctime from that.

  Design tweaks that reduce spikes

  ✅ Directory sort: external sort or chunked merge sort, or store a temporary on‑disk sorted listing and reuse across requests (cursor includes a temp filename + offset). The
     current sort() in class-directory-listing.php is the biggest memory spike.
  ✅ File stat: replace is_link/is_dir/is_file + filectime/filesize with a single lstat() and inspect mode, size, ctime. This can reduce syscall count by 2–4x in file-sync.php.
  ✅ Oversized SQL rows: estimate size without fully encoding. For big values, use raw length + encoding formula to decide chunking, then only encode chunks. This avoids
     allocating gigantic quoted strings. That change would be in class-mysql-dump-producer.php.
  ✅ SQL batching: emit each fragment as its own multipart part (no batching), or compute and emit without Content-Length to avoid holding the whole batch in memory. That’s in
     export.php.
  ✅ Large path lists: avoid reading all paths into memory in endpoint_file_fetch(). Instead, keep a file pointer offset in the cursor and read lines incrementally (requires a
     stable path list file).

  Backoff strategy I’d use

  - Use a duty‑cycle model + adaptive chunk sizing + exponential backoff on errors.
  - Duty‑cycle: aim for 30–50% CPU duty on low‑end hosts with 2 PHP workers. If a request took t seconds, sleep t * (1/target_duty - 1). Example: target 0.4, t=5s → sleep
    7.5s.
  - Adaptive sizing: use X-Time-Elapsed and X-Memory-Used (already emitted in export.php) to adjust:
      - If time > 0.8 * max_exec or memory > 0.7 * limit, reduce chunk_size and SQL batch size by 20–40%.
      - If time < 0.4 * max_exec and memory < 0.5 * limit, increase by 10–20% (cap at safe max).
  - Error backoff: on 429/503/5xx/timeout, exponential backoff with full jitter:
      - delay = rand(0, min(max_backoff, base * 2^n)), base 1–2s, max 30–60s.
      - Reset on success, but decay gradually instead of snapping back to zero.
  - Add a server hint: respond with X-Suggested-Delay based on your own observed runtime and memory. The client can obey it to keep hosts happy.

  Other guardrails worth adding

  - Cross‑request lock (file lock or transient) to prevent two migrations from running concurrently on the same host.
  - Clamp max_execution_time to something slightly below ini_get('max_execution_time') to avoid PHP killing the worker unexpectedly.
  - For no‑PK tables, try a unique index fallback (information_schema) instead of OFFSET, or reduce batch size aggressively for those tables.

  If you want, I can sketch concrete changes in file-sync.php and class-mysql-dump-producer.php to reduce stat calls and avoid oversized encoding spikes.
