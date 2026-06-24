<?php

namespace Reprint\Exporter\Command;

use InvalidArgumentException;
use Throwable;
use Reprint\Exporter\ResourceBudget;
use function Reprint\Exporter\begin_multipart_stream;
use function Reprint\Exporter\encode_index_batch;
use function Reprint\Exporter\encode_index_stack;
use function Reprint\Exporter\E2E\call_hook;
use function Reprint\Exporter\E2E\load_test_hooks_if_needed;
use function Reprint\Exporter\find_parents_symlinks;
use function Reprint\Exporter\json_encode_or_throw;
use function Reprint\Exporter\path_is_default_skipped;
use function Reprint\Exporter\position_after_entry;
use function Reprint\Exporter\prepare_streaming_response;
use function Reprint\Exporter\require_int_range;
use function Reprint\Exporter\resolve_directories;
use function Reprint\Exporter\resolve_symlink_target;
use function Reprint\Exporter\should_skip_index_root;

final class FileIndexCommand extends BudgetedExportCommand
{
    public function execute(array $config, ResourceBudget $budget): array
    {
        // This endpoint may run repeatedly in the same PHP process (e.g. PHP built-in
        // server, long-lived workers). Clear stale stat/realpath cache from previous
        // requests so path type transitions (symlink/file/dir) are seen correctly.
        clearstatcache(true);
    
        $directories = resolve_directories($config);
        $batch_size = $config["batch_size"] ?? 5000;
        $batch_size = require_int_range(
            "batch_size",
            (int) $batch_size,
            100,
            100000,
        );
    
        $list_dir = $config["list_dir"] ?? null;
        $list_dir_real = null;
        $stack = [];
        $ordered = [];
        $follow_symlinks = !empty($config["follow_symlinks"]);
        $cursor_provided = isset($config["cursor"]);
        // Default-skip generated caches, VCS metadata, OS junk, and editor
        // scratch files unless the client explicitly opts in. See
        // path_is_default_skipped() for the full deny-list and rationale.
        $include_caches = !empty($config["include_caches"]);
    
        // Find the starting point – either by parsing the cursor, or by
        // sourcing it from the filesystem.
    
        // -- Restore or initialize the directory traversal stack --
        // On resumption, the cursor encodes the stack of directories and the
        // last-processed entry in each. On first request, build the stack from
        // the list_dir and any extra allowed roots.
        if ($cursor_provided) {
            $cursor_data = json_decode($config["cursor"], true);
            if (!is_array($cursor_data)) {
                throw new InvalidArgumentException("Invalid index cursor format");
            }
            if (!isset($cursor_data["stack"]) || !is_array($cursor_data["stack"])) {
                throw new InvalidArgumentException("Index cursor missing stack");
            }
            foreach ($cursor_data["stack"] as $frame) {
                if (!is_array($frame)) {
                    throw new InvalidArgumentException("Invalid index cursor frame");
                }
                $dir_encoded = $frame["dir"] ?? null;
                if (!is_string($dir_encoded) || $dir_encoded === "") {
                    throw new InvalidArgumentException("Index cursor frame missing dir");
                }
                $dir = base64_decode($dir_encoded, true);
                if ($dir === false || $dir === "") {
                    throw new InvalidArgumentException("Index cursor frame has invalid dir encoding");
                }
                $after_encoded = $frame["after"] ?? null;
                if ($after_encoded !== null && !is_string($after_encoded)) {
                    throw new InvalidArgumentException("Index cursor frame invalid after");
                }
                $after = null;
                if ($after_encoded !== null) {
                    $after = base64_decode($after_encoded, true);
                    if ($after === false) {
                        throw new InvalidArgumentException("Index cursor frame has invalid after encoding");
                    }
                }
                $stack[] = [
                    "dir" => $dir,
                    "after" => $after,
                ];
            }
        } else {
            if (!$list_dir) {
                throw new InvalidArgumentException("list_dir is required for file_index");
            }
    
            clearstatcache(true, $list_dir);
            $list_dir_real = realpath($list_dir);
            if ($list_dir_real === false || !is_dir($list_dir_real)) {
                throw new InvalidArgumentException(
                    "list_dir does not exist or is not accessible: {$list_dir}",
                );
            }
    
            $allowed = false;
            foreach ($directories as $root) {
                if (
                    $list_dir_real === $root ||
                    str_starts_with($list_dir_real, $root . "/")
                ) {
                    $allowed = true;
                    break;
                }
            }
            // When follow_symlinks is enabled, allow any directory that the
            // authenticated client requests.  The client is already authenticated
            // via HMAC, so there is no untrusted-input risk.
            if (!$allowed && !$follow_symlinks) {
                throw new InvalidArgumentException(
                    "list_dir is outside of allowed roots: {$list_dir_real}",
                );
            }
    
            $ordered = [$list_dir_real];
            $extra_roots = [];
            foreach ($directories as $root) {
                if ($root === $list_dir_real) {
                    continue;
                }
                $extra_roots[] = $root;
            }
            if (!empty($extra_roots)) {
                sort($extra_roots, SORT_STRING);
                foreach ($extra_roots as $root) {
                    // Skip exact duplicates of already-ordered roots.
                    // Do NOT skip parent roots — on hosts like wp.com Atomic
                    // the document root (/srv/htdocs) is a parent of the
                    // primary root (/srv/htdocs/__wp__) but contains a separate
                    // wp-content with the site's actual plugins and themes.
                    // The during-traversal dedup in the main loop already
                    // prevents re-entering child roots (i.e. when traversing
                    // /srv/htdocs we won't descend back into __wp__/).
                    if (in_array($root, $ordered, true)) {
                        continue;
                    }
                    $ordered[] = $root;
                }
            }
    
            for ($i = count($ordered) - 1; $i >= 0; $i--) {
                $stack[] = [
                    "dir" => $ordered[$i],
                    "after" => null,
                ];
            }
        }
    
        if ($list_dir_real === null) {
            if (!empty($stack)) {
                $list_dir_real = $stack[count($stack) - 1]["dir"];
            } else {
                $list_dir_real = $directories[0] ?? "/";
            }
        }
    
        prepare_streaming_response();
    
        ['gz' => $gz, 'boundary' => $boundary] = begin_multipart_stream();
    
        $filesystem_root = $directories[0] ?? "/";
        $batches_emitted = 0;
        $total_entries = 0;
        $batch_items = [];
        $status = "partial";
        $aborted = false;
        $abort_payload = null;
    
        // -- Pre-scan: discover intermediate symlinks --
        // When following symlinks, discover intermediate symlinks along each
        // directory path being traversed.  For example, if list_dir is
        // /srv/wordpress/plugins/akismet/latest and /srv/wordpress is itself
        // a symlink to /wordpress, emit that intermediate symlink so the
        // client can recreate the full chain locally.
        if (!$cursor_provided && $follow_symlinks) {
            foreach ($ordered as $dir) {
                $path_symlinks = find_parents_symlinks($dir);
                foreach ($path_symlinks as $entry) {
                    $batch_items[] = $entry;
                }
            }
        }
    
        // -- Depth-first directory traversal --
        // Walk the directory tree using the stack. Each directory's entries are
        // read with scandir (sorted ascending), yielding files, symlinks, and
        // subdirectories. Subdirectories push new frames onto the stack. Entries
        // are batched into JSON index_batch chunks and streamed to the client.
    
        $current_dir = $list_dir_real;
    
        try {
            $metadata = [
                "filesystem_root" => base64_encode($filesystem_root),
                "list_dir" => base64_encode($list_dir_real),
            ];
            $metadata_json = json_encode_or_throw($metadata);
    
            $gz->write(
                "--{$boundary}\r\n" .
                "Content-Type: application/json\r\n" .
                "Content-Length: " . strlen($metadata_json) . "\r\n" .
                "X-Chunk-Type: metadata\r\n" .
                "X-Filesystem-Root: " . base64_encode($filesystem_root) . "\r\n" .
                "X-Index-Dir: " . base64_encode($list_dir_real) . "\r\n" .
                "\r\n" .
                $metadata_json . "\r\n",
            );
            $gz->sync();
            $stop = false;
    
            while (true) {
                if (empty($stack)) {
                    $status = "complete";
                    break;
                }
    
                $frame_index = count($stack) - 1;
                $frame = $stack[$frame_index];
                $current_dir = $frame["dir"];
                $current_after = $frame["after"] ?? null;
    
                clearstatcache(true, $current_dir);
                $current_real = realpath($current_dir);
                if ($current_real === false || !is_dir($current_real)) {
                    $abort_payload = [
                        "error_type" => "dir_open",
                        "path" => base64_encode($current_dir),
                        "message" => "Directory does not exist or is not accessible",
                    ];
                    array_pop($stack);
                    $json = json_encode_or_throw($abort_payload);
                    $cursor_json = json_encode_or_throw(
                        ["stack" => encode_index_stack($stack)],
                        JSON_UNESCAPED_SLASHES,
                    );
                    $cursor_b64 = base64_encode($cursor_json);
                    $gz->write(
                        "--{$boundary}\r\n" .
                        "Content-Type: application/json\r\n" .
                        "Content-Length: " . strlen($json) . "\r\n" .
                        "X-Chunk-Type: error\r\n" .
                        "X-Cursor: " . $cursor_b64 . "\r\n" .
                        "\r\n" .
                        $json . "\r\n",
                    );
                    $gz->sync();
                    $abort_payload = null;
                    continue;
                }
    
                $allowed = $follow_symlinks;
                if (!$allowed) {
                    foreach ($directories as $root) {
                        if (
                            $current_real === $root ||
                            str_starts_with($current_real, $root . "/")
                        ) {
                            $allowed = true;
                            break;
                        }
                    }
                }
                if (!$allowed) {
                    $abort_payload = [
                        "error_type" => "dir_outside_root",
                        "path" => base64_encode($current_real),
                        "message" => "Directory is outside allowed roots",
                    ];
                    array_pop($stack);
                    $json = json_encode_or_throw($abort_payload);
                    $cursor_json = json_encode_or_throw(
                        ["stack" => encode_index_stack($stack)],
                        JSON_UNESCAPED_SLASHES,
                    );
                    $cursor_b64 = base64_encode($cursor_json);
                    $gz->write(
                        "--{$boundary}\r\n" .
                        "Content-Type: application/json\r\n" .
                        "Content-Length: " . strlen($json) . "\r\n" .
                        "X-Chunk-Type: error\r\n" .
                        "X-Cursor: " . $cursor_b64 . "\r\n" .
                        "\r\n" .
                        $json . "\r\n",
                    );
                    $gz->sync();
                    $abort_payload = null;
                    continue;
                }
    
                // Use realpath() consistently for all paths. On hosts like wp.com,
                // /srv is a symlink to / and /srv/wordpress is a symlink to
                // /wordpress, so realpath() canonicalizes everything into one
                // namespace: /srv/htdocs → /htdocs, /srv/wordpress/... → /wordpress/...
                // This keeps root dirs and symlink-followed dirs consistent.
                $stack[$frame_index]["dir"] = $current_real;
                $current_dir = $current_real;
    
    
    
                clearstatcache(true, $current_real);
                $entries = @scandir($current_real, SCANDIR_SORT_ASCENDING);
                if ($entries === false) {
                    $abort_payload = [
                        "error_type" => "dir_open",
                        "path" => base64_encode($current_real),
                        "message" => "Failed to open directory",
                    ];
                    $json = json_encode_or_throw($abort_payload);
                    $cursor_json = json_encode_or_throw(
                        ["stack" => encode_index_stack($stack)],
                        JSON_UNESCAPED_SLASHES,
                    );
                    $cursor_b64 = base64_encode($cursor_json);
                    $gz->write(
                        "--{$boundary}\r\n" .
                        "Content-Type: application/json\r\n" .
                        "Content-Length: " . strlen($json) . "\r\n" .
                        "X-Chunk-Type: error\r\n" .
                        "X-Cursor: " . $cursor_b64 . "\r\n" .
                        "\r\n" .
                        $json . "\r\n",
                    );
                    $gz->sync();
                    $abort_payload = null;
                    array_pop($stack);
                    continue;
                }
    
                // E2E test hook: during directory scanning
                if (getenv('SITE_EXPORT_TEST_MODE')) {
                    load_test_hooks_if_needed($config);
                    $hook_args = [$current_real, &$entries];
                    call_hook('test_hook_during_dir_scan', $hook_args);
                }
    
                $filtered = [];
                foreach ($entries as $entry) {
                    if ($entry === "." || $entry === "..") {
                        continue;
                    }
                    $filtered[] = $entry;
                }
    
                $position = 0;
                if ($current_after !== null && $current_after !== "") {
                    $position = position_after_entry($filtered, $current_after);
                }
    
                while (true) {
                    if ($position >= count($filtered)) {
                        array_pop($stack);
                        break;
                    }
                    $entry = $filtered[$position];
                    $position++;
    
                    $stack[$frame_index]["after"] = $entry;
                    $path = $current_dir . "/" . $entry;
                    // Default deny-list. Applied before stat() to save a syscall
                    // per skipped entry, and before the traversal push so we
                    // don't recurse into skipped directories. The "after" cursor
                    // is updated above this check, so resume correctly skips
                    // past the filtered entry on the next request.
                    if (!$include_caches && path_is_default_skipped($path)) {
                        continue;
                    }
                    clearstatcache(true, $path);
                    $stat = @lstat($path);
                    if ($stat === false) {
                        if (
                            !$budget->has_remaining()
                        ) {
                            $status = "partial";
                            $stop = true;
                            break;
                        }
                        continue;
                    }
    
                    $mode = $stat["mode"] & REPRINT_EXPORTER_STAT_TYPE_MASK;
                    $type = "file";
                    $link_target = null;
                    if ($mode === REPRINT_EXPORTER_STAT_TYPE_LINK) {
                        $type = "link";
                        $resolved = resolve_symlink_target($path);
                        $link_target = $resolved['target'];
                        if ($follow_symlinks && !empty($resolved['intermediates'])) {
                            $batch_items = array_merge($batch_items, $resolved['intermediates']);
                        }
                    } elseif ($mode === REPRINT_EXPORTER_STAT_TYPE_DIR) {
                        $type = "dir";
                    } elseif ($mode !== REPRINT_EXPORTER_STAT_TYPE_FILE) {
                        $type = "other";
                    }
    
                    $ctime = (int) ($stat["ctime"] ?? 0);
                    $size = $type === "file" ? (int) ($stat["size"] ?? 0) : 0;
    
                    $item = [
                        "path" => $path,
                        "ctime" => $ctime,
                        "size" => $size,
                        "type" => $type,
                    ];
                    if ($link_target !== null) {
                        $item["target"] = $link_target;
                    }
                    $batch_items[] = $item;
    
                    if (count($batch_items) >= $batch_size) {
                        // E2E test hook: before index batch is emitted
                        if (getenv('SITE_EXPORT_TEST_MODE')) {
                            load_test_hooks_if_needed($config);
                            $hook_args = [&$batch_items, $stack];
                            call_hook('test_hook_before_index_batch', $hook_args);
                        }
    
                        $cursor_json = json_encode_or_throw(
                            ["stack" => encode_index_stack($stack)],
                            JSON_UNESCAPED_SLASHES,
                        );
                        $cursor_b64 = base64_encode($cursor_json);
                        $json = json_encode_or_throw(
                            encode_index_batch($batch_items),
                            JSON_UNESCAPED_SLASHES,
                        );
    
                        $gz->write(
                            "--{$boundary}\r\n" .
                            "Content-Type: application/json\r\n" .
                            "Content-Length: " . strlen($json) . "\r\n" .
                            "X-Chunk-Type: index_batch\r\n" .
                            "X-Cursor: " . $cursor_b64 . "\r\n" .
                            "X-Batch-Size: " . count($batch_items) . "\r\n" .
                            "\r\n",
                        );
                        $gz->write($json);
                        $gz->write("\r\n");
                        $gz->sync();
    
                        $batches_emitted++;
                        $total_entries += count($batch_items);
                        $batch_items = [];
                    }
    
                    if ($type === "dir") {
                        // Skip traversing directories whose realpath is already
                        // covered by the configured roots (duplicate root), or is a
                        // parent of one of them (would expose outside-tree files and
                        // re-enter a scheduled root). O(k) where k = number of roots.
                        $dir_real = realpath($path);
                        if ($dir_real !== false && should_skip_index_root($dir_real, $directories)) {
                            // Don't push — emit the entry but skip traversal
                            continue;
                        }
                        $stack[] = [
                            "dir" => $path,
                            "after" => null,
                        ];
                        break;
                    }
    
                    if (
                        !$budget->has_remaining()
                    ) {
                        $status = "partial";
                        $stop = true;
                        break;
                    }
                }
    
                if ($stop) {
                    break;
                }
    
                if (
                    !$budget->has_remaining()
                ) {
                    $status = "partial";
                    break;
                }
            }
        } catch (Throwable $e) {
            $aborted = true;
            $abort_payload = [
                "error_type" => "exception",
                "path" => base64_encode($current_dir),
                "message" => $e->getMessage(),
            ];
        }
    
        // -- Flush remaining items and write completion chunk --
        if (!empty($batch_items)) {
            $cursor_json = json_encode_or_throw(
                ["stack" => encode_index_stack($stack)],
                JSON_UNESCAPED_SLASHES,
            );
            $cursor_b64 = base64_encode($cursor_json);
            $json = json_encode_or_throw(
                encode_index_batch($batch_items),
                JSON_UNESCAPED_SLASHES,
            );
    
            $gz->write(
                "--{$boundary}\r\n" .
                "Content-Type: application/json\r\n" .
                "Content-Length: " . strlen($json) . "\r\n" .
                "X-Chunk-Type: index_batch\r\n" .
                "X-Cursor: " . $cursor_b64 . "\r\n" .
                "X-Batch-Size: " . count($batch_items) . "\r\n" .
                "\r\n",
            );
            $gz->write($json);
            $gz->write("\r\n");
            $gz->sync();
    
            $batches_emitted++;
            $total_entries += count($batch_items);
        }
    
        try {
            if ($abort_payload !== null) {
                $json = json_encode_or_throw($abort_payload);
                $cursor_json = json_encode_or_throw(
                    ["stack" => encode_index_stack($stack)],
                    JSON_UNESCAPED_SLASHES,
                );
                $cursor_b64 = base64_encode($cursor_json);
                $gz->write(
                    "--{$boundary}\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Content-Length: " . strlen($json) . "\r\n" .
                    "X-Chunk-Type: error\r\n" .
                    "X-Cursor: " . $cursor_b64 . "\r\n" .
                    "\r\n" .
                    $json . "\r\n",
                );
                $gz->sync();
                $status = "partial";
            }
    
            $cursor_json = json_encode_or_throw(
                ["stack" => encode_index_stack($stack)],
                JSON_UNESCAPED_SLASHES,
            );
            $cursor_b64 = base64_encode($cursor_json);
    
            $gz->write(
                "--{$boundary}\r\n" .
                "Content-Type: application/octet-stream\r\n" .
                "Content-Length: 0\r\n" .
                "X-Chunk-Type: completion\r\n" .
                "X-Status: " . ($aborted ? "partial" : $status) . "\r\n" .
                "X-Cursor: " . $cursor_b64 . "\r\n" .
                "X-Index-Dir: " . base64_encode($list_dir_real) . "\r\n" .
                "X-Batches-Emitted: {$batches_emitted}\r\n" .
                "X-Total-Entries: {$total_entries}\r\n" .
                "X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n" .
                "X-Memory-Limit: " . $budget->max_memory . "\r\n" .
                "X-Time-Elapsed: " . (microtime(true) - $budget->start_time) . "\r\n" .
                "\r\n" .
                "\r\n" .
                "--{$boundary}--\r\n",
            );
            $gz->finish();
        } catch (\Throwable $e) {
            error_log("Export: failed to write completion chunk: " . $e->getMessage());
        }
    
        return [
            "status" => $aborted ? "partial" : $status,
            "stats" => [
                "batches_emitted" => $batches_emitted,
                "total_entries" => $total_entries,
                "memory_used" => memory_get_peak_usage(true),
                "time_elapsed" => microtime(true) - $budget->start_time,
            ],
        ];
    }
}
