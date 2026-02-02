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

## Todo

- [ ] Directory tree snapshots – store root-relative path. Don't store the entire absolute path, it inflates the snapshot size.
- [ ] Multipart handling – do we need to check for boundary presence in our chunk when Content-Length is also present?

## File synchronization

### Synchronization approach

The system is designed to perform an initial directory tree synchronization followed by incremental updates.

**Initial synchronization** is when the **migration target** requests a stream of files from the **migration source**
without having any prior state. The migration target sends a HTTP request to the migration source and asks to start
a new synchronization of one or more root directory paths. In response, the migration target provides a synchronization
ID. From now on, the migration target uses that identifier for all following requests related to this synchronization.

The migration target then sends a HTTP request asking for the next batch of files. The migration source immediately starts
streaming the requested directory trees using pre-order traversal. The HTTP response is a multipart/mixed data stream listing
every file, symlink, and an empty directory in the requested root directory. Every chunk carries file metadata, content bytes
of the file, and a cursor pointing to the next chunk.

The **migration source** keeps track of two resource budgets: memory and execution time limit. Once it starts approaching
either of them, it ends the response and the migration target needs to send another HTTP request. This means, the initial
synchronization request will most likely be concluded before all the files are transferred.

Once the first HTTP request is completed, the migration target sends another HTTP request to the migration source asking for the next
batch of files. It provides the synchronization ID and the cursor from the previous response. The migration source then responds
with the next batch of files. This repeats until the initial synchronization is complete.

### Synchronization Cursor

The cursor consists of the file path, ctime, and byte offset. When provided, the migration source will resume the traversal
from the given point. If the cursor is not provided, the migration source will stream from the beginning of the first requested
root directory.

`ctime` is not strictly required for traversal, but the migration source can use it to tell if the streamed file has been modified
since the last synchronization. If it has, the migration source will communicate that via a dedicated multipart chunk and move on to
the next file.

TODO:
* ?: It is the responsibility of the migration target to keep track of all modified files and re-request them later on.
* ?: The migration source keeps track of all such modified files and moves them to the end of the synchronization queue.
     If they're reached again, and they're modified again, ... (?) ...

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

    start = microtime(); do_thing(); took = microtime() - start; usleep( max( 0.5, (2 * took ) ) );

### Open questions

* How do we choose resource budgets for each host / runtime?
* Can we, somehow, budget CPU usage?
* How to negotiate symlinks pointing outside of the requested root directories?
* Should we include a sequence ID with each file chunk for consistency checks?
* Should we include crc32 checksums for each transmitted chunk? Seems excessive since TCP+TLS both already give us strong consistency guarantees?

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
