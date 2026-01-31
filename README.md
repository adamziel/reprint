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


## Other explored approaches

* Git protocol. We're effectively exchanging diffs here. Git already does that. Why not use git? Because git can't recover from a broken exchange, you need to start from scratch. Also, git protocol is pretty complex. Multipart is simple and native to HTTP clients.
