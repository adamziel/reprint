<?php

/**
 * Streaming multipart/mixed body builder for push uploads.
 *
 * Writes producer output (SQL chunks, file chunks, directories, symlinks)
 * into a seekable php://temp stream formatted as multipart/mixed. The stream
 * is memory-backed for small payloads and automatically spills to disk above
 * ~2 MB (PHP's default php://temp threshold).
 *
 * The push client loop:
 * 1. Create stream, create producer from receiver's last cursor
 * 2. Feed producer output into stream until post_max_size reached
 * 3. Compute HMAC hash by reading stream, rewind
 * 4. curl uploads from the stream resource
 * 5. Receiver returns cursor from its last processed chunk
 */
class MultipartBodyStream
{
    /** @var string */
    private $boundary;

    /** @var resource */
    private $stream;

    /** @var bool */
    private $finalized = false;

    public function __construct()
    {
        $this->boundary = 'boundary-' . bin2hex(random_bytes(16));
        $this->stream = fopen('php://temp', 'r+');
        if ($this->stream === false) {
            throw new RuntimeException('Failed to open php://temp stream');
        }
    }

    public function get_boundary(): string
    {
        return $this->boundary;
    }

    public function get_content_type(): string
    {
        return 'multipart/mixed; boundary="' . $this->boundary . '"';
    }

    /**
     * Write a file data chunk as a multipart part.
     *
     * @param array  $chunk  File chunk with keys: path, data, size, ctime, offset,
     *                       is_first_chunk, is_last_chunk, and optional file_changed fields.
     * @param string $cursor Base64-encoded cursor from the producer.
     */
    public function write_file_chunk(array $chunk, string $cursor): void
    {
        $this->assert_not_finalized();

        $data = $chunk['data'];
        $headers =
            "--{$this->boundary}\r\n" .
            "Content-Type: application/octet-stream\r\n" .
            "Content-Length: " . strlen($data) . "\r\n" .
            "X-Chunk-Type: file\r\n" .
            "X-Cursor: " . base64_encode($cursor) . "\r\n" .
            "X-File-Path: " . base64_encode($chunk['path']) . "\r\n" .
            "X-File-Size: " . $chunk['size'] . "\r\n" .
            "X-File-Ctime: " . $chunk['ctime'] . "\r\n" .
            "X-Chunk-Offset: " . $chunk['offset'] . "\r\n" .
            "X-Chunk-Size: " . strlen($data) . "\r\n" .
            "X-First-Chunk: " . ($chunk['is_first_chunk'] ? '1' : '0') . "\r\n" .
            "X-Last-Chunk: " . ($chunk['is_last_chunk'] ? '1' : '0') . "\r\n";

        if (!empty($chunk['file_changed'])) {
            $headers .= "X-File-Changed: 1\r\n";
            if ($chunk['change_ctime'] !== null) {
                $headers .= "X-File-Change-Ctime: " . $chunk['change_ctime'] . "\r\n";
            }
            if ($chunk['change_size'] !== null) {
                $headers .= "X-File-Change-Size: " . $chunk['change_size'] . "\r\n";
            }
        }

        fwrite($this->stream, $headers . "\r\n");
        fwrite($this->stream, $data);
        fwrite($this->stream, "\r\n");
    }

    /**
     * Write a directory entry as a multipart part.
     */
    public function write_directory_chunk(string $path, string $cursor, ?int $ctime = null): void
    {
        $this->assert_not_finalized();

        $part =
            "--{$this->boundary}\r\n" .
            "Content-Type: application/octet-stream\r\n" .
            "Content-Length: 0\r\n" .
            "X-Chunk-Type: directory\r\n" .
            "X-Cursor: " . base64_encode($cursor) . "\r\n" .
            "X-Directory-Path: " . base64_encode($path) . "\r\n";

        if ($ctime !== null) {
            $part .= "X-Directory-Ctime: " . $ctime . "\r\n";
        }

        fwrite($this->stream, $part . "\r\n\r\n");
    }

    /**
     * Write a symlink entry as a multipart part.
     */
    public function write_symlink_chunk(string $path, string $target, int $ctime, string $cursor): void
    {
        $this->assert_not_finalized();

        fwrite(
            $this->stream,
            "--{$this->boundary}\r\n" .
            "Content-Type: application/octet-stream\r\n" .
            "Content-Length: 0\r\n" .
            "X-Chunk-Type: symlink\r\n" .
            "X-Cursor: " . base64_encode($cursor) . "\r\n" .
            "X-Symlink-Path: " . base64_encode($path) . "\r\n" .
            "X-Symlink-Target: " . base64_encode($target) . "\r\n" .
            "X-Symlink-Ctime: " . $ctime . "\r\n" .
            "\r\n\r\n"
        );
    }

    /**
     * Write a SQL chunk as a multipart part.
     *
     * @param string $sql            The SQL fragment text.
     * @param string $cursor         Producer's reentrancy cursor (JSON string).
     * @param bool   $query_complete Whether this chunk ends on a complete statement boundary.
     */
    public function write_sql_chunk(string $sql, string $cursor, bool $query_complete): void
    {
        $this->assert_not_finalized();

        fwrite(
            $this->stream,
            "--{$this->boundary}\r\n" .
            "Content-Type: application/sql\r\n" .
            "Content-Length: " . strlen($sql) . "\r\n" .
            "X-Chunk-Type: sql\r\n" .
            "X-Query-Complete: " . ($query_complete ? '1' : '0') . "\r\n" .
            "X-Cursor: " . base64_encode($cursor) . "\r\n" .
            "\r\n"
        );
        fwrite($this->stream, $sql);
        fwrite($this->stream, "\r\n");
    }

    /**
     * Write a completion marker as the final content part.
     */
    public function write_completion_chunk(string $status, array $stats = []): void
    {
        $this->assert_not_finalized();

        $headers =
            "--{$this->boundary}\r\n" .
            "Content-Type: application/octet-stream\r\n" .
            "Content-Length: 0\r\n" .
            "X-Chunk-Type: completion\r\n" .
            "X-Status: {$status}\r\n";

        foreach ($stats as $key => $value) {
            $header_key = 'X-' . str_replace('_', '-', ucwords($key, '_'));
            $headers .= "{$header_key}: {$value}\r\n";
        }

        fwrite($this->stream, $headers . "\r\n\r\n");
    }

    /**
     * Write the closing boundary. No more parts can be added after this.
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        fwrite($this->stream, "--{$this->boundary}--\r\n");
        $this->finalized = true;
    }

    /**
     * Return the total size of the multipart body written so far.
     */
    public function get_size(): int
    {
        $pos = ftell($this->stream);
        fseek($this->stream, 0, SEEK_END);
        $size = ftell($this->stream);
        fseek($this->stream, $pos, SEEK_SET);
        return $size;
    }

    /**
     * Return the underlying seekable stream resource for curl CURLOPT_INFILE.
     * Automatically rewinds to the beginning.
     */
    public function get_resource()
    {
        rewind($this->stream);
        return $this->stream;
    }

    /**
     * Rewind the stream to the beginning (e.g. after HMAC hash, before curl read).
     */
    public function rewind(): void
    {
        rewind($this->stream);
    }

    /**
     * Read the entire stream contents as a string (for HMAC hashing).
     * Does NOT change the stream position — reads from current position to end,
     * then seeks back.
     */
    public function get_contents(): string
    {
        $pos = ftell($this->stream);
        rewind($this->stream);
        $contents = stream_get_contents($this->stream);
        fseek($this->stream, $pos, SEEK_SET);
        return $contents;
    }

    private function assert_not_finalized(): void
    {
        if ($this->finalized) {
            throw new RuntimeException('Cannot write to a finalized MultipartBodyStream');
        }
    }
}
