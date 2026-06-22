<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;

final class SymlinkTargetIndexer
{
    private string $remote_index_file;

    /** @var callable */
    private $download_remote_index;

    /** @var callable */
    private $save_state;

    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $audit;

    /** @var callable */
    private $show_lifecycle_line;

    /** @var callable */
    private $emit_progress;

    public function __construct(
        string $remote_index_file,
        callable $download_remote_index,
        callable $save_state,
        callable $should_stop,
        callable $audit,
        callable $show_lifecycle_line,
        callable $emit_progress
    ) {
        $this->remote_index_file = $remote_index_file;
        $this->download_remote_index = $download_remote_index;
        $this->save_state = $save_state;
        $this->should_stop = $should_stop;
        $this->audit = $audit;
        $this->show_lifecycle_line = $show_lifecycle_line;
        $this->emit_progress = $emit_progress;
    }

    /**
     * Recursively discover and index directories referenced by symlinks.
     *
     * @param array<int, string>   $roots
     */
    public function discover(FilesPullCheckpoint $checkpoint, array $roots): void
    {
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
            $this->show_lifecycle_line("Following symlink target: {$dir}\n");
            $this->emit_progress([
                "type" => "symlink_follow",
                "directory" => $dir,
                "message" => "Following symlink target: {$dir}",
            ]);

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
        $this->show_lifecycle_line("  Skipped (server rejected): {$dir}\n");
        $this->emit_progress([
            "type" => "symlink_follow_rejected",
            "directory" => $dir,
            "message" => "Skipped (server rejected): {$dir}",
        ]);

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
        ($this->save_state)($checkpoint);
    }

    private function should_stop(): bool
    {
        return (bool) ($this->should_stop)();
    }

    private function download_remote_index(string $dir): bool
    {
        return (bool) ($this->download_remote_index)($dir);
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit)($message, $to_console);
    }

    private function show_lifecycle_line(string $message): void
    {
        ($this->show_lifecycle_line)($message);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function emit_progress(array $progress): void
    {
        ($this->emit_progress)($progress);
    }
}
