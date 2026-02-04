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
        $this->memory_limit = $memory_limit;
        $this->stream = fopen("php://temp/maxmemory:{$memory_limit}", 'w+b');
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
        }

        return $listing;
    }

    /**
     * Sorts the entries alphabetically.
     *
     * For directories that fit in memory, this loads all entries, sorts them
     * in memory, and writes them back. For very large directories, this may
     * use significant memory temporarily during the sort operation.
     *
     * After sorting, builds an offset index for efficient binary search.
     */
    public function sort(): void
    {
        if ($this->sorted || $this->count === 0) {
            $this->sorted = true;
            return;
        }

        // Read all entries into memory for sorting
        rewind($this->stream);
        $content = stream_get_contents($this->stream);
        $entries = explode("\0", rtrim($content, "\0"));

        // Sort using PHP's natural string sort
        sort($entries, SORT_STRING);

        // Truncate and rewrite the sorted entries, building offset index
        ftruncate($this->stream, 0);
        rewind($this->stream);
        $this->offsets = [];

        foreach ($entries as $entry) {
            $this->offsets[] = ftell($this->stream);
            fwrite($this->stream, $entry . "\0");
        }

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
}
