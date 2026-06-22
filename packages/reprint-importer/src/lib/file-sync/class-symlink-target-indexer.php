<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\RemoteFileIndexGateway;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\FileSync\Port\SymlinkTargetObserver;
use Reprint\Importer\Observability\AuditLogger;
use RuntimeException;

final class SymlinkTargetIndexer
{
    private string $remote_index_file;
    private RemoteFileIndexGateway $remote_index;
    private FilesPullCheckpointStore $checkpoints;
    private ShutdownToken $shutdown;
    private AuditLogger $audit;
    private SymlinkTargetObserver $observer;
    private ?FilesPullCheckpoint $active_checkpoint = null;

    public function __construct(
        string $remote_index_file,
        RemoteFileIndexGateway $remote_index,
        FilesPullCheckpointStore $checkpoints,
        ShutdownToken $shutdown,
        AuditLogger $audit,
        SymlinkTargetObserver $observer
    ) {
        $this->remote_index_file = $remote_index_file;
        $this->remote_index = $remote_index;
        $this->checkpoints = $checkpoints;
        $this->shutdown = $shutdown;
        $this->audit = $audit;
        $this->observer = $observer;
    }

    /**
     * Recursively discover and index directories referenced by symlinks.
     *
     * @param array<int, string>   $roots
     */
    public function discover(FilesPullCheckpoint $checkpoint, array $roots): void
    {
        $this->active_checkpoint = $checkpoint;
        $visited = [];
        foreach ($roots as $root) {
            $visited[$root] = true;
        }

        $queue = $this->extract_symlink_dirs_from_index($visited);

        while (!empty($queue)) {
            $dir = array_shift($queue);
            if (isset($visited[$dir])) {
                continue;
            }

            if ($this->is_covered_by_visited_parent($dir, $visited)) {
                $this->audit(
                    "FOLLOW SYMLINK SKIP | {$dir} already covered by a visited parent",
                    true,
                );
                continue;
            }

            $visited[$dir] = true;

            $this->audit(
                "FOLLOW SYMLINK | indexing target directory: {$dir}",
                true,
            );
            $this->observer->on_following_directory($dir);

            $checkpoint->index_cursor = null;
            $this->save_state($checkpoint);

            $this->index_target_directory($checkpoint, $dir);
            if ($this->should_stop()) {
                return;
            }

            foreach ($this->extract_symlink_dirs_from_index($visited) as $target) {
                if (!isset($visited[$target])) {
                    $queue[] = $target;
                }
            }
        }
    }

    /**
     */
    private function index_target_directory(
        FilesPullCheckpoint $checkpoint,
        string $dir
    ): void
    {
        $attempts = 0;
        $last_cursor = null;

        while (true) {
            try {
                $complete = $this->download_remote_index($dir);
            } catch (RuntimeException $e) {
                if ($this->handle_rejected_directory($dir, $e)) {
                    return;
                }

                throw $e;
            }

            if ($complete) {
                return;
            }

            if ($this->should_stop()) {
                return;
            }

            $current_cursor = $checkpoint->index_cursor;
            if ($current_cursor === $last_cursor) {
                throw new RuntimeException(
                    "files-index (symlink follow) made no progress (cursor unchanged)",
                );
            }
            $last_cursor = $current_cursor;

            $attempts++;
            if ($attempts > 10_000) {
                throw new RuntimeException(
                    "files-index (symlink follow) exceeded maximum attempts",
                );
            }
        }
    }

    private function handle_rejected_directory(string $dir, RuntimeException $e): bool
    {
        $message = $e->getMessage();
        if (
            strpos($message, "HTTP error 4") === false &&
            strpos($message, "dir_outside_root") === false &&
            strpos($message, "outside of allowed roots") === false
        ) {
            return false;
        }

        $this->audit(
            "FOLLOW SYMLINK SKIP | server rejected {$dir}: " .
                substr($message, 0, 200),
            true,
        );
        $this->observer->on_rejected_directory($dir);

        return true;
    }

    /**
     * @param array<string, true> $visited
     */
    private function is_covered_by_visited_parent(string $dir, array $visited): bool
    {
        foreach ($visited as $root => $_) {
            if (str_starts_with($dir, $root . "/")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $visited
     * @return array<int, string>
     */
    private function extract_symlink_dirs_from_index(array $visited): array
    {
        $targets = [];
        if (!file_exists($this->remote_index_file)) {
            return $targets;
        }

        $handle = fopen($this->remote_index_file, "r");
        if (!$handle) {
            return $targets;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $target = $this->extract_symlink_target($line);
                if ($target === null) {
                    continue;
                }
                if (isset($visited[$target])) {
                    continue;
                }
                if ($this->is_covered_by_visited_parent($target, $visited)) {
                    continue;
                }

                $targets[] = $target;
            }
        } finally {
            fclose($handle);
        }

        return array_values(array_unique($targets));
    }

    private function extract_symlink_target(string $line): ?string
    {
        $entry = json_decode($line, true);
        if (!is_array($entry)) {
            return null;
        }
        if (($entry["type"] ?? "") !== "link") {
            return null;
        }
        if (!empty($entry["intermediate"])) {
            return null;
        }

        $target_encoded = $entry["target"] ?? null;
        if (!is_string($target_encoded) || $target_encoded === "") {
            return null;
        }

        $target = base64_decode($target_encoded);
        if ($target === false || $target === "") {
            return null;
        }

        return $target;
    }

    /**
     */
    private function save_state(FilesPullCheckpoint $checkpoint): void
    {
        $this->checkpoints->save($checkpoint);
    }

    private function should_stop(): bool
    {
        return $this->shutdown->is_shutdown_requested();
    }

    private function download_remote_index(string $dir): bool
    {
        if ($this->active_checkpoint === null) {
            throw new RuntimeException("Cannot index symlink target without an active checkpoint");
        }

        return $this->remote_index->download($this->active_checkpoint, $dir);
    }

    private function audit(string $message, bool $to_console): void
    {
        $this->audit->record($message, $to_console);
    }
}
