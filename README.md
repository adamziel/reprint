# WordPress Site Export - File Sync Architecture

## Todo

- [ ] Directory tree snapshots – store root-relative path. Don't store the entire absolute path, it inflates the snapshot size.
- [ ] Multipart handling – do we need to check for boundary presence in our chunk when Content-Length is also present?
