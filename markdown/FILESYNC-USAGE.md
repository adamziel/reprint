# FileSyncProducer Usage Guide

## Overview

`FileSyncProducer` is a re-entrant file synchronization class that streams file contents in chunks with support for:
- **Multi-phase processing**: scanning, sorting, and streaming
- **Resumable operations**: pause/resume with cursor-based state
- **Progress tracking**: monitor scanning and streaming progress
- **Incremental syncs**: only process files changed since last sync
- **Deletion detection**: track files deleted between syncs
- **Pluggable storage**: choose between file-based or SQLite snapshot storage

## Basic Usage

```php
require_once 'class-file-sync-producer.php';

$sync = new FileSyncProducer('/path/to/directory', [
    'min_ctime' => 0,                    // Only files changed after this timestamp
    'max_files' => 1000,                 // Limit number of files to process
    'chunk_size' => 5 * 1024 * 1024,     // 5MB chunks
]);

// Stream file chunks
while ($sync->next_chunk()) {
    $chunk = $sync->get_current_chunk();

    // Handle chunk
    if ($chunk['is_first_chunk']) {
        open_remote_file($chunk['path']);
    }

    send_to_remote($chunk['data']);

    if ($chunk['is_last_chunk']) {
        close_remote_file();
    }
}
```

## Resumable Operations

```php
// First run
$sync = new FileSyncProducer('/path/to/dir');

while ($sync->next_chunk()) {
    // ... process chunks ...

    if (time() - $start_time > 30) {
        // Save cursor
        $cursor = $sync->get_reentrancy_cursor();
        file_put_contents('cursor.json', $cursor);
        break;
    }
}

// Later - resume from cursor
$cursor = file_get_contents('cursor.json');
$sync = new FileSyncProducer('/path/to/dir', [
    'cursor' => $cursor,  // Resume from saved position
]);

while ($sync->next_chunk()) {
    // Continues where we left off
}
```

## Incremental Sync

```php
// First sync
$sync = new FileSyncProducer('/path/to/dir', [
    'min_ctime' => 0,  // All files
]);
process_sync($sync);
$last_sync_time = time();

// Second sync - only changed files
$sync = new FileSyncProducer('/path/to/dir', [
    'min_ctime' => $last_sync_time,  // Only files changed after first sync
]);
process_sync($sync);
```

## Snapshot Storage Options

### File-based Storage (Default)

Good for small to medium sites (< 100K files):

```php
$storage = new FileSnapshotStorage('/path/to/.snapshot.tsv');

$sync = new FileSyncProducer('/path/to/dir', [
    'snapshot_storage' => $storage,
]);

while ($sync->next_chunk()) {
    // Process chunks
}

// Get deletions
$deletions = $sync->get_deletions();
foreach ($deletions as $deleted) {
    echo "Deleted: {$deleted['path']}\n";
}
```

### SQLite Storage

Efficient for large sites (> 100K files):

```php
$storage = new SqliteSnapshotStorage('/path/to/snapshot.db');

$sync = new FileSyncProducer('/path/to/dir', [
    'snapshot_storage' => $storage,
]);
```

### No Storage

Disable deletion tracking:

```php
$sync = new FileSyncProducer('/path/to/dir');
// No snapshot_storage option - deletions won't be tracked
```

## Progress Tracking

```php
while ($sync->next_chunk()) {
    $progress = $sync->get_progress();

    switch ($progress['phase']) {
        case 'scanning':
            echo "Scanning... found {$progress['files_found']} files\n";
            break;

        case 'sorting':
            echo "Sorting... {$progress['percent_complete']}%\n";
            break;

        case 'streaming':
            $file = $progress['current_file'];
            echo "Streaming {$file['path']}: ";
            echo "{$file['percent']}% complete\n";
            echo "Files: {$progress['files_completed']}/{$progress['files_total']}\n";
            break;
    }
}
```

## Chunk Structure

```php
$chunk = $sync->get_current_chunk();

// Chunk structure:
[
    'path' => '/full/path/to/file.txt',
    'size' => 10240,                     // Total file size
    'ctime' => 1735700000,               // File change time
    'data' => '...',                     // Chunk data (binary)
    'offset' => 0,                       // Byte offset in file
    'chunk_size' => 5242880,             // Size of this chunk
    'is_first_chunk' => true,            // First chunk of file
    'is_last_chunk' => false,            // Last chunk of file
]
```

## Custom Storage Implementation

Implement the `SnapshotStorage` interface:

```php
class RedisSnapshotStorage implements SnapshotStorage
{
    private $redis;

    public function __construct($redis) {
        $this->redis = $redis;
    }

    public function update_from_scan(string $sorted_file): array {
        // Load scan results, compare with previous snapshot
        // Return array of deletions
    }

    public function get_file(string $path): ?array {
        // Get file info from snapshot
    }

    public function get_all_files(): \Generator {
        // Yield all files from snapshot
    }
}

// Use it:
$storage = new RedisSnapshotStorage($redis_client);
$sync = new FileSyncProducer('/path/to/dir', [
    'snapshot_storage' => $storage,
]);
```

## Complete Example

```php
// Configuration
$directory = '/var/www/uploads';
$last_sync_time = get_last_sync_time();
$cursor_file = '/tmp/sync-cursor.json';
$snapshot_file = '/var/lib/sync-snapshot.tsv';

// Setup storage
$storage = new FileSnapshotStorage($snapshot_file);

// Create producer
$options = [
    'min_ctime' => $last_sync_time,
    'max_files' => 1000,
    'chunk_size' => 10 * 1024 * 1024,  // 10MB chunks
    'snapshot_storage' => $storage,
];

// Resume from cursor if exists
if (file_exists($cursor_file)) {
    $options['cursor'] = file_get_contents($cursor_file);
}

$sync = new FileSyncProducer($directory, $options);

// Process with time budget
$start_time = time();
$time_budget = 60; // 60 seconds

while ($sync->next_chunk()) {
    $chunk = $sync->get_current_chunk();

    // Upload chunk to remote
    upload_to_remote($chunk);

    // Check time budget
    if (time() - $start_time > $time_budget) {
        // Save cursor and exit
        $cursor = $sync->get_reentrancy_cursor();
        file_put_contents($cursor_file, $cursor);
        echo "Paused - resume later\n";
        exit(0);
    }
}

// Complete - handle deletions
$deletions = $sync->get_deletions();
foreach ($deletions as $deleted) {
    delete_from_remote($deleted['path']);
}

// Clean up cursor
@unlink($cursor_file);
save_last_sync_time(time());
echo "Sync complete!\n";
```
