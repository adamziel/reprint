<?php
/**
 * Memory-efficient directory listing that handles directories with many files.
 *
 * Uses php://temp which stores data in memory up to a threshold, then
 * automatically spills to a temporary file that's cleaned up on close.
 * This allows handling directories with millions of files without exhausting memory.
 *
 * Usage:
 *   $listing = DirectoryListing::scan('/path/to/dir');
 *   $listing->sort();
 *   while (($entry = $listing->next()) !== null) {
 *       echo $entry . "\n";
 *   }
 *   // Or use binary search for cursor-based resumption:
 *   $listing->seekAfter('last_processed_entry');
 */
class DirectoryListing
{
    /**
     * The php://temp stream resource.
     * @var resource|null
     */
    private $stream = null;

    /**
     * Number of entries in the listing.
     * @var int
     */
    private int $count = 0;

    /**
     * Total bytes of entry data stored in the stream (including NUL separators).
     * @var int
     */
    private int $total_bytes = 0;

    /**
     * Whether the entries have been sorted.
     * @var bool
     */
    private bool $sorted = false;

    /**
     * Memory threshold before spilling to disk (bytes).
     * Default 2MB allows ~50-100K short filenames in memory.
     * @var int
     */
    private int $memory_limit;

    /**
     * Index of entry offsets for binary search (built during sort).
     * Maps entry index -> byte offset in stream.
     * @var array
     */
    private array $offsets = [];

    /**
     * Current position for iteration (entry index, not byte offset).
     * @var int
     */
    private int $position = 0;

    /**
     * Private constructor - use static factory methods.
     */
    private function __construct(int $memory_limit = 2 * 1024 * 1024)
    {
        $this->memory_limit = $this->clamp_memory_limit($memory_limit);
        $this->stream = fopen(
            "php://temp/maxmemory:{$this->memory_limit}",
            'w+b',
        );
        if ($this->stream === false) {
            throw new RuntimeException("Failed to open php://temp stream");
        }
    }

    /**
     * Creates a DirectoryListing by scanning a directory.
     *
     * Uses opendir/readdir to avoid loading all entries into memory at once
     * during the scan phase.
     *
     * @param string $directory Path to the directory to scan.
     * @param int $memory_limit Memory threshold before spilling to disk.
     * @return DirectoryListing|null Null if directory cannot be opened.
     */
    public static function scan(string $directory, int $memory_limit = 2 * 1024 * 1024): ?self
    {
        $dh = @opendir($directory);
        if ($dh === false) {
            return null;
        }

        $listing = new self($memory_limit);

        // Read entries one at a time using readdir (memory efficient)
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // Store each entry as a newline-terminated string
            // We use \0 as separator since filenames can't contain null bytes
            fwrite($listing->stream, $entry . "\0");
            $listing->count++;
            $listing->total_bytes += strlen($entry) + 1;
        }

        closedir($dh);
        return $listing;
    }

    /**
     * Creates a DirectoryListing from an array of entries.
     *
     * Useful for testing or when entries are already in memory.
     *
     * @param array $entries Array of entry names.
     * @param int $memory_limit Memory threshold before spilling to disk.
     * @return DirectoryListing
     */
    public static function fromArray(array $entries, int $memory_limit = 2 * 1024 * 1024): self
    {
        $listing = new self($memory_limit);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            fwrite($listing->stream, $entry . "\0");
            $listing->count++;
            $listing->total_bytes += strlen($entry) + 1;
        }

        return $listing;
    }

    /**
     * Sorts the entries alphabetically.
     *
     * After sorting, builds an offset index for efficient binary search.
     */
    public function sort(): void
    {
        if ($this->sorted || $this->count === 0) {
            $this->sorted = true;
            return;
        }

        $target_bytes = $this->get_target_bytes();
        if ($this->estimate_in_memory_sort_bytes() <= $target_bytes) {
            $this->sort_in_memory();
        } else {
            $this->stream = $this->external_sort($target_bytes);
        }

        $this->rebuild_offsets();

        $this->sorted = true;
        $this->position = 0;
    }

    /**
     * Returns the number of entries in the listing.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Returns whether the listing is empty.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /**
     * Resets the iterator to the beginning.
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Returns the next entry, or null if no more entries.
     *
     * @return string|null
     */
    public function next(): ?string
    {
        if ($this->position >= $this->count) {
            return null;
        }

        if ($this->sorted && !empty($this->offsets)) {
            // Use offset index for direct access
            fseek($this->stream, $this->offsets[$this->position]);
        } elseif ($this->position === 0) {
            // First read - seek to start
            rewind($this->stream);
        }
        // Otherwise we're already positioned after the last read

        $entry = stream_get_line($this->stream, 65536, "\0");
        if ($entry === false) {
            return null;
        }

        $this->position++;
        return $entry;
    }

    /**
     * Returns the entry at a specific index without advancing the iterator.
     *
     * Requires sorted listing (which builds the offset index).
     *
     * @param int $index
     * @return string|null
     */
    public function get(int $index): ?string
    {
        if ($index < 0 || $index >= $this->count) {
            return null;
        }

        if (!$this->sorted || empty($this->offsets)) {
            throw new RuntimeException("DirectoryListing must be sorted before random access");
        }

        fseek($this->stream, $this->offsets[$index]);
        $entry = stream_get_line($this->stream, 65536, "\0");
        return $entry !== false ? $entry : null;
    }

    /**
     * Sets the iterator position to just after the given entry name.
     *
     * Uses binary search to find the position efficiently.
     * After this call, next() will return the first entry that comes after $name.
     *
     * @param string $name The entry name to seek after.
     * @return int The new position (index of next entry to be returned).
     */
    public function seekAfter(string $name): int
    {
        if (!$this->sorted) {
            throw new RuntimeException("DirectoryListing must be sorted before seekAfter");
        }

        if ($this->count === 0) {
            $this->position = 0;
            return 0;
        }

        // Binary search for the first entry greater than $name
        $low = 0;
        $high = $this->count;

        while ($low < $high) {
            $mid = (int)(($low + $high) / 2);
            $entry = $this->get($mid);

            if ($entry === null || strcmp($entry, $name) <= 0) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        $this->position = $low;
        return $low;
    }

    /**
     * Returns the current iterator position.
     *
     * @return int
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Sets the iterator position directly.
     *
     * @param int $position
     */
    public function setPosition(int $position): void
    {
        $this->position = max(0, min($position, $this->count));
    }

    /**
     * Returns all entries as an array.
     *
     * Warning: This loads all entries into memory. Use with caution for large directories.
     *
     * @return array
     */
    public function toArray(): array
    {
        $saved_position = $this->position;
        $this->rewind();

        $entries = [];
        while (($entry = $this->next()) !== null) {
            $entries[] = $entry;
        }

        $this->position = $saved_position;
        return $entries;
    }

    /**
     * Closes the stream and releases resources.
     */
    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
        $this->offsets = [];
    }

    /**
     * Destructor ensures stream is closed.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Read a single entry from a stream using NUL as a delimiter.
     */
    private function read_entry($stream): ?string
    {
        $entry = stream_get_line($stream, 65536, "\0");
        return $entry === false ? null : $entry;
    }

    /**
     * External sort using bounded memory and php://temp streams.
     */
    private function external_sort(int $target_bytes)
    {
        $runs = [];
        $buffer = [];
        $buffer_bytes = 0;

        rewind($this->stream);
        while (true) {
            $entry = $this->read_entry($this->stream);
            if ($entry === null) {
                break;
            }

            $buffer[] = $entry;
            $buffer_bytes += strlen($entry) + 1;

            if ($buffer_bytes >= $target_bytes) {
                $runs[] = $this->write_sorted_run($buffer);
                $buffer = [];
                $buffer_bytes = 0;
            }
        }

        if (!empty($buffer)) {
            $runs[] = $this->write_sorted_run($buffer);
        }

        // Release the original stream - we will replace it with sorted output
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }

        if (count($runs) === 1) {
            return $runs[0];
        }

        return $this->merge_sorted_runs($runs);
    }

    /**
     * Write a sorted run to a php://temp stream.
     */
    private function write_sorted_run(array $entries)
    {
        sort($entries, SORT_STRING);
        $run = fopen("php://temp/maxmemory:{$this->memory_limit}", "w+b");
        if ($run === false) {
            throw new RuntimeException("Failed to open php://temp stream for run");
        }
        foreach ($entries as $entry) {
            fwrite($run, $entry . "\0");
        }
        rewind($run);
        return $run;
    }

    /**
     * Merge sorted runs into a single sorted stream.
     *
     * @param array $runs List of sorted run streams.
     * @return resource Sorted php://temp stream.
     */
    private function merge_sorted_runs(array $runs)
    {
        $out = fopen("php://temp/maxmemory:{$this->memory_limit}", "w+b");
        if ($out === false) {
            throw new RuntimeException("Failed to open php://temp stream for merge");
        }

        $heap = new DirectoryListingMinHeap();
        $heap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);

        foreach ($runs as $i => $run) {
            rewind($run);
            $entry = $this->read_entry($run);
            if ($entry !== null) {
                $heap->insert(
                    ["entry" => $entry, "run" => $i],
                    [$entry, $i],
                );
            }
        }

        while (!$heap->isEmpty()) {
            $item = $heap->extract();
            $entry = $item["entry"];
            $run_index = $item["run"];

            fwrite($out, $entry . "\0");

            $next = $this->read_entry($runs[$run_index]);
            if ($next !== null) {
                $heap->insert(
                    ["entry" => $next, "run" => $run_index],
                    [$next, $run_index],
                );
            }
        }

        foreach ($runs as $run) {
            fclose($run);
        }

        rewind($out);
        return $out;
    }

    /**
     * Rebuild offset index for random access.
     */
    private function rebuild_offsets(): void
    {
        $this->offsets = [];
        rewind($this->stream);
        while (true) {
            $pos = ftell($this->stream);
            $entry = $this->read_entry($this->stream);
            if ($entry === null) {
                break;
            }
            $this->offsets[] = $pos;
        }
    }

    /**
     * In-memory sort for small listings (faster than external sort).
     */
    private function sort_in_memory(): void
    {
        $entries = [];
        rewind($this->stream);
        while (true) {
            $entry = $this->read_entry($this->stream);
            if ($entry === null) {
                break;
            }
            $entries[] = $entry;
        }

        sort($entries, SORT_STRING);

        ftruncate($this->stream, 0);
        rewind($this->stream);
        foreach ($entries as $entry) {
            fwrite($this->stream, $entry . "\0");
        }
    }

    /**
     * Compute the target in-memory buffer size for external sort runs.
     */
    private function get_target_bytes(): int
    {
        $target = (int) ($this->memory_limit * 0.8);
        $target = max(64 * 1024, $target);
        return min($this->memory_limit, $target);
    }

    /**
     * Rough estimate of memory needed to load and sort all entries in PHP arrays.
     */
    private function estimate_in_memory_sort_bytes(): int
    {
        $per_entry_overhead = 64; // heuristic for zval+bucket+string header
        $estimated = $this->total_bytes + ($this->count * $per_entry_overhead);
        return (int) ($estimated * 1.5);
    }

    /**
     * Clamp requested memory limit to [4KB, min(50MB, 20% of available memory)].
     */
    private function clamp_memory_limit(int $requested): int
    {
        $min = 4 * 1024;
        $available = $this->get_available_memory_bytes();
        $max = (int) ($available * 0.2);
        $max = min($max, 50 * 1024 * 1024);
        $max = max($max, $min);

        $clamped = min($requested, $max);
        return max($clamped, $min);
    }

    /**
     * Estimate available memory for this process.
     */
    private function get_available_memory_bytes(): int
    {
        $limit = ini_get("memory_limit");
        if ($limit === false || $limit === "") {
            return 128 * 1024 * 1024;
        }

        if ($limit === "-1") {
            return 256 * 1024 * 1024;
        }

        $limit_bytes = $this->parse_memory_limit($limit);
        if ($limit_bytes <= 0) {
            return 128 * 1024 * 1024;
        }

        $used = memory_get_usage(true);
        $available = $limit_bytes - $used;
        return $available > 0 ? $available : $limit_bytes;
    }

    /**
     * Parse a PHP memory_limit string into bytes.
     */
    private function parse_memory_limit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === "") {
            return 0;
        }

        $unit = strtoupper(substr($limit, -1));
        $value = (int) $limit;

        switch ($unit) {
            case "G":
                return $value * 1024 * 1024 * 1024;
            case "M":
                return $value * 1024 * 1024;
            case "K":
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }
}

/**
 * Min-heap priority queue for lexicographic string ordering.
 */
class DirectoryListingMinHeap extends \SplPriorityQueue
{
    public function compare($priority1, $priority2): int
    {
        $cmp = strcmp($priority1[0], $priority2[0]);
        if ($cmp === 0) {
            if ($priority1[1] === $priority2[1]) {
                return 0;
            }
            return ($priority1[1] < $priority2[1]) ? 1 : -1;
        }
        return ($cmp < 0) ? 1 : -1;
    }
}
