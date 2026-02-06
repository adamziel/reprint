# WordPress Site Export - File Sync Architecture

## Usage

1. Place the ./*.php files from the repo root in the document root of the site you want to export.
2. Update the SECRET_KEY constant at the top. Define the DB_HOST, DB_USER, DB_PASSWORD, and DB_NAME constants if thye're not defined by the host environment.
3. Run `import.php <export.php URL>?SECRET_KEY=<key> <local directory to export to>` locally.
4. Go brew some coffee. If the electricity goes down that's okay, just re-run the script and it will resume where it left off.
5. Done. Your local directory now has a db.sql file and a directory tree snapshot.

You can rerun the same import.php command later on with extra parameters to refresh the local data:

```
# Regenerate the database dump from scratch
import.php <export.php URL>?SECRET_KEY=<key> <local directory to export to> --refresh-db 

# Get the delta in files between then and now (via deletes and upserts)
import.php <export.php URL>?SECRET_KEY=<key> <local directory to export to> --refresh-files
```

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

### Directory listing order

The file index is produced by traversing directories depth-first. Each directory’s immediate entries are sorted in bytewise
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
  smaller directories. Furthermore, we couold never be sure whether the shell call results can be trusted – find and sort could differ between
  runtimes to the point where some runtimes replace them with stubs. PHP functions are much more portable. Large sites should have large memory
  banks. If they don't, then we can revisit the stream-sorting approach.

### Volatile files

Sometimes a file will keep changing every minute and we'll start streaming it, but won't finish before it's modified again. In that case,
the **migration target** chooses how to handle it. A few choices are:

* Stop the migration, tell the user we can't migrate this file (or multiple files).
* Stop the migration, ask the user if that file is okay to ignore. Chances are it's a log, a cache, or some other volatile artifact
  that doesn't matter that much.
* Retry a few more times.
* Just ignore that file (and tell the user).

#### Other considered ways of traversing the filesystem

* A `(ctime, byte offset)` cursor. We'd still need to keep track of the filename for when multiple files have the same ctime.
* Ordering the traversal by `(ctime, filename)`. It wouldn't save us much work, as we'd still need to run a full scan of the filesystem
  on every incremental synchronization to detect deletions. On top of that, it is impractical. First, you need to always sort the
  entire directory tree by ctime before you can start streaming the data. That may take some time and HTTP requests in PHP land. Second,
  there is no clear stop for the traversal. Any file that keeps changing will keep moving to the end of the queue, and meanwhile the files
  we have already seen may also get their ctime updated.
  
### Resource management

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
duty-cycle sleep (with jitter) spaces requests so we don’t monopolize PHP workers or synchronize multiple
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

### Open questions

* How to negotiate symlinks pointing outside of the requested root directories?

### Todos

* Rewrite URLs in the incoming files
  * All SQL strings. How do we handle strings that are not UTF-8 but latin1? Well, WordPress doesn't seem to actually
    support latin1, right? It's, most likely, UTF-8 disguised as another encoding. Can we reject inputs that don't form
    valid UTF-8 sequences? Or at least avoid modifying them? And then rewrite the ones that do look valid?
  * Hardcoded in files. Most likely themes and plugins. We can parse and rewrite CSS, HTML, XML, JavaScript, JSON, and PHP
    because we have structured parsers and tokenizers for all these formats. Should we leave this out of scope for the
    initial version, though? It will be an annoying limitations for site that do need rewriting, but it's also a relatively
    major scope creep.
  * Figure out if we do a multi-pass rewrite or stream-rewrite (which could slow down the download).
* Turn it into a WordPress plugin 
  * HMAC signatures per request with a shared secret + random number + microtime
* Automated test suite to cover all the usual corner cases we are trying to account for
✅ Handle every single possible error case, e.g. fread() returning false prematurely etc.
✅ Take note of any files modified while they were streamed, re-request them later on.
   ✅ Tell the user when a file is too volatile to be synchronized
✅ Support paths with "\n" in them – they're valid paths
✅ Account for 3xx errors – just treat them as errors. Anything non-200 is an error.
✅ If directory sorting exceeds per-request budgets, use real temp files to persist sort runs across requests.
✅ In export.php, require `wp-load.php` if we cannot cheaply connect to MySQL using the database credentials from the wp-config.php file.
✅ Pre-flight request to
  ✅ Confirm the host is able to export the site
  ✅ Get runtime details so the importing side may decide if it's capable of importing the site.
✅ Auto-constraining resource usage
✅ Handle 4xx and 5xx errors, support backoff strategies.
    How do we choose resource budgets for each host / runtime?
    start = microtime(); do_thing(); took = microtime() - start; usleep( max( 0.5, (2 * took ) ) );
    you can also if bite_size = default; ……. while ….. if ( took > threshold ) { bite_size = bite_size / 2 } else if ( took < other threshold ) { bite_size++ }
    for things like number of rows and or files or bytes or whatever transferred at a time
    [7:52 PM]so if performance gets poor it backs off hard. if performance is good it bumps up slow (edited) 
    [7:53 PM]you can also threshold… like if took > a then sleep 2x; if took > b then sleep 4x; if took > c then sleep 8x
    [7:53 PM]you should be able to make some combination of things that backs off as necessary (edited) 
    [7:54 PM]not simple. but if you get someone deactivated and banned .25 though a migration you’re gonna have a bad time
✅ When downloading a large file and killing the process, make sure it will be resumed on the next run, regardless of
   what it was doing when we've killed it (e.g. appending a partial state to the local file). So, if we wrote some bytes
   to the file but did not update the cursor yet, make sure the next run will know we're only expected to have so many
   bytes and will truncate the excess bytes beyond that expected size.
✅ Display nice progress information in the terminal (since that will also allow us to display it on the web)
✅ Support directories with more files than can be stored in memory at once.
✅ Multipart handling – do we need to check for boundary presence in our chunk when Content-Length is also present?
✅ Double check we're generating a useful, append-only audit log for every export call
❌ Directory tree snapshots – store root-relative path. Don't store the entire absolute path, it inflates the snapshot size.
  ^ this is okay, repetitive paths gzip exceptionally well.

**Nice to haves**

* Importer – emit dedicated errors when we run out of the disk space or DB space on the importing end.
  * Account for the disk space limits for files and for MySQL data on the migration target.
  * we can be reactive – detect out of disk space errors when it happens. we won't know the storage quota
     upfront anyway in most shared hosting environments.


**Out of scope for this initial version**

* Sites using multiple databases (either multiple MySQL instances or also Postgres, Redis, etc.)
* Support for directories with more files than we can sort in memory at once. A million files with 64 byte names require around 100MB of memory to sort.
  If you have so many files, you better have that much memory available. If it turns out people tend to have more files without the available memory,
  we can trade CPU cycles and use streaming sorting. We'd also need to use a temporary file to store the sorted list.
* When we're starting the import and we have access to local MySQL, detect our local `current_statement_size` and `max_allowed_packet` and send that
  over to the remote host to get an appropriately-chunked dump. Alternatively, if we ever need to store the dump now and execute it later, we could
  bring over the MySQL parser from sqlite-database-plugin – or just transmit the data over the wire as JSON (or some binary serialization format) and
  turn it into SQL statements locally. Let's not start there, though, as that would add complexity and make the REST endpoints harder to debug.

### Transport

Data is sent over HTTP using multipart/mixed content-type. It gives us a way to split large files into chunks and send
them over multiple requests, while transmitting per-chunk metadata (cursor, chunk size, etc.).

Why not ZIP or TAR? Because:

* We need to split large files into chunks, and there is no native way of expressing "this entry is a chunk
  of a larger file" in ZIP or TAR.
* We could treat the entire export as a single, huge data stream split over multiple requests and assume that
  a single zip/tar entry, no matter how large, is always a single file. However, we wouldn't have a good way of
  knowing we've lost some bytes from the middle of the stream – at least until we've transferred the entire file.
* We wouldn't have a good way of sending the cursor to the client. Where would it come in the stream?

## Other explored ideas

* Git protocol. We're effectively exchanging diffs here. Git already does that. Why not use git? Because git can't recover from a broken exchange, you need to start from scratch. Also, git protocol is pretty complex. Multipart is simple and native to HTTP clients.
