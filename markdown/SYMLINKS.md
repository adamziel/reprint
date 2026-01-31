# Symlink Handling

## Overview

The export/import system preserves symlinks by recording them in a manifest file rather than automatically recreating them. This approach provides security and flexibility.

## Export Behavior

When exporting, the FileSyncProducer:

1. **Detects all symlinks** in the directory structure
2. **Records symlink metadata** (path, target, ctime)
3. **Outputs symlink chunks** separately from file chunks
4. **Optionally follows symlinks** if `follow_symlinks` is enabled (default: true)

Example symlinks that are properly captured:
- `__wp__ -> ../../../wordpress/core/latest` (external dependency)
- `wp-load.php -> __wp__/wp-load.php` (chained symlink)
- `link -> /usr/local/bin/tool` (absolute path)

## Import Behavior

When importing, symlinks are **stored in a manifest file** for security:

### Location
```
<import-path>/
  ├── db.sql
  ├── document-root/
  │   └── (your files)
  └── symlinks.json         ← Symlink manifest
```

### Why Not Recreate Automatically?

**Security**: Automatically recreating symlinks could enable:
- **Directory traversal attacks** - malicious paths like `../../../etc/passwd`
- **Absolute path exploits** - symlinks pointing to sensitive system files
- **Unexpected external dependencies** - links outside the import directory

### Manifest Format

The `symlinks.json` file contains an array of symlink entries:

```json
[
  {
    "path": "/__wp__",
    "target": "../wordpress/core/latest",
    "ctime": 1234567890,
    "recorded_at": 1735700000
  },
  {
    "path": "/wp-load.php",
    "target": "__wp__/wp-load.php",
    "ctime": 1234567891,
    "recorded_at": 1735700000
  }
]
```

### Recreating Symlinks Manually

After import, review `symlinks.json` and recreate symlinks as needed:

```bash
cd /path/to/import/document-root

# Review symlinks
cat ../symlinks.json | jq

# Recreate safe symlinks
ln -s __wp__/wp-load.php wp-load.php

# Handle external dependencies
# (ensure ../wordpress/core/latest exists first)
ln -s ../wordpress/core/latest __wp__
```

## Configuration

### Export Options

```php
$sync = new FileSyncProducer('/path/to/dir', [
    'follow_symlinks' => true,  // Follow and copy symlink targets (default: true)
]);
```

When `follow_symlinks` is enabled:
- Symlinks are **recorded** in the manifest
- Symlink targets are **followed and exported** as regular files
- You get both the symlink metadata AND the content

When `follow_symlinks` is disabled:
- Symlinks are **only recorded** in the manifest
- Target content is **not exported**

### Cycle Protection

The system automatically detects and prevents infinite loops from circular symlinks:

```
dir/
  ├── a/
  │   └── link_to_b -> ../b
  └── b/
      └── link_to_a -> ../a
```

## Testing

Comprehensive tests cover:
- External symlinks (pointing outside the directory)
- Absolute path symlinks
- Chained symlinks (symlink -> symlink -> file)
- Circular symlink references
- Broken symlinks
- Security scenarios

Run tests:
```bash
vendor/bin/phpunit tests/FileSyncProducer/SymlinkSecurityTest.php
vendor/bin/phpunit tests/Import/ImportSymlinkSecurityTest.php
```

## Common Scenarios

### WordPress with Shared Core

**Server structure:**
```
/var/www/
  ├── wordpress/core/latest/
  └── site/
      ├── __wp__ -> ../../wordpress/core/latest
      └── wp-load.php -> __wp__/wp-load.php
```

**After export/import:**
1. Check `symlinks.json` for the `__wp__` symlink
2. Ensure the WordPress core exists at the target location
3. Recreate the symlink: `ln -s ../../wordpress/core/latest __wp__`
4. Recreate dependent symlinks: `ln -s __wp__/wp-load.php wp-load.php`

### Development Tools

**Server structure:**
```
/var/www/site/
  └── bin -> /usr/local/bin
```

**After export/import:**
1. Review if the absolute path makes sense in the new environment
2. Either recreate the symlink or update to a local path
3. Consider copying the tools instead of symlinking

## Best Practices

1. **Review the manifest** - Always check `symlinks.json` after import
2. **Verify targets exist** - Ensure external dependencies are available
3. **Consider copying** - For production, copying may be safer than symlinking
4. **Document dependencies** - Note external paths in your deployment docs
5. **Test thoroughly** - Verify symlink recreation in staging first
