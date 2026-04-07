<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for MultipartBodyStream and its round-trip with parse_multipart_body().
 *
 * The stream is the bridge between client and server. If it produces
 * malformed multipart, the receiver silently drops chunks and the push
 * appears to succeed but staging is incomplete.
 */
final class MultipartBodyStreamTest extends TestCase
{
    /**
     * Basic round-trip: write a SQL chunk, parse it back.
     */
    public function testSqlChunkRoundTrip(): void
    {
        $stream = new MultipartBodyStream();
        $sql = "INSERT INTO `wp_posts` VALUES (1, 'Hello World');";
        $cursor = json_encode(['table' => 'wp_posts', 'pk' => 1]);

        $stream->write_sql_chunk($sql, $cursor, true);
        $stream->finalize();

        $body = $stream->get_contents();
        $chunks = parse_multipart_body($body, $stream->get_boundary());

        $this->assertCount(1, $chunks);
        $this->assertSame('sql', $chunks[0]['headers']['x-chunk-type']);
        $this->assertSame($sql, $chunks[0]['body']);

        // Cursor should be base64-encoded in headers
        $decoded_cursor = base64_decode($chunks[0]['headers']['x-cursor'], true);
        $this->assertSame($cursor, $decoded_cursor);
    }

    /**
     * File chunk round-trip with binary data.
     */
    public function testFileChunkRoundTripWithBinaryData(): void
    {
        $stream = new MultipartBodyStream();

        // Generate binary data (simulates an image)
        $binary = random_bytes(1024);
        $chunk = [
            'path' => '/wp-content/uploads/photo.jpg',
            'data' => $binary,
            'size' => 1024,
            'ctime' => 1700000000,
            'offset' => 0,
            'is_first_chunk' => true,
            'is_last_chunk' => true,
            'file_changed' => false,
        ];
        $cursor = json_encode(['file' => '/wp-content/uploads/photo.jpg']);

        $stream->write_file_chunk($chunk, $cursor);
        $stream->finalize();

        $body = $stream->get_contents();
        $parsed = parse_multipart_body($body, $stream->get_boundary());

        $this->assertCount(1, $parsed);
        $this->assertSame('file', $parsed[0]['headers']['x-chunk-type']);
        $this->assertSame('1', $parsed[0]['headers']['x-first-chunk']);
        $this->assertSame('1', $parsed[0]['headers']['x-last-chunk']);

        // Binary data must survive the round-trip exactly
        $this->assertSame(
            $binary,
            $parsed[0]['body'],
            "Binary file data must survive multipart round-trip without corruption"
        );
    }

    /**
     * Directory chunk round-trip.
     */
    public function testDirectoryChunkRoundTrip(): void
    {
        $stream = new MultipartBodyStream();
        $cursor = json_encode(['dir' => '/wp-content/uploads/2024/']);

        $stream->write_directory_chunk('/wp-content/uploads/2024/', $cursor, 1700000000);
        $stream->finalize();

        $body = $stream->get_contents();
        $parsed = parse_multipart_body($body, $stream->get_boundary());

        $this->assertCount(1, $parsed);
        $this->assertSame('directory', $parsed[0]['headers']['x-chunk-type']);

        $path = base64_decode($parsed[0]['headers']['x-directory-path'], true);
        $this->assertSame('/wp-content/uploads/2024/', $path);
    }

    /**
     * Symlink chunk round-trip.
     */
    public function testSymlinkChunkRoundTrip(): void
    {
        $stream = new MultipartBodyStream();
        $cursor = json_encode(['link' => '/wp-content/plugins/akismet']);

        $stream->write_symlink_chunk(
            '/wp-content/plugins/akismet',
            '../shared-plugins/akismet',
            1700000000,
            $cursor
        );
        $stream->finalize();

        $body = $stream->get_contents();
        $parsed = parse_multipart_body($body, $stream->get_boundary());

        $this->assertCount(1, $parsed);
        $this->assertSame('symlink', $parsed[0]['headers']['x-chunk-type']);

        $path = base64_decode($parsed[0]['headers']['x-symlink-path'], true);
        $target = base64_decode($parsed[0]['headers']['x-symlink-target'], true);
        $this->assertSame('/wp-content/plugins/akismet', $path);
        $this->assertSame('../shared-plugins/akismet', $target);
    }

    /**
     * Completion chunk carries status.
     */
    public function testCompletionChunkRoundTrip(): void
    {
        $stream = new MultipartBodyStream();

        $stream->write_completion_chunk('complete', ['sql_bytes' => 12345]);
        $stream->finalize();

        $body = $stream->get_contents();
        $parsed = parse_multipart_body($body, $stream->get_boundary());

        $this->assertCount(1, $parsed);
        $this->assertSame('completion', $parsed[0]['headers']['x-chunk-type']);
        $this->assertSame('complete', $parsed[0]['headers']['x-status']);
    }

    /**
     * Multiple chunks of different types in one body.
     */
    public function testMultipleChunkTypes(): void
    {
        $stream = new MultipartBodyStream();

        $stream->write_directory_chunk('/wp-content/', '{}', null);
        $stream->write_file_chunk([
            'path' => '/wp-content/index.php',
            'data' => '<?php // Silence',
            'size' => 16,
            'ctime' => 1700000000,
            'offset' => 0,
            'is_first_chunk' => true,
            'is_last_chunk' => true,
            'file_changed' => false,
        ], '{}');
        $stream->write_sql_chunk("INSERT INTO `wp_posts` VALUES (1);", '{}', true);
        $stream->write_completion_chunk('partial', []);
        $stream->finalize();

        $body = $stream->get_contents();
        $parsed = parse_multipart_body($body, $stream->get_boundary());

        $this->assertCount(4, $parsed);
        $types = array_map(fn($c) => $c['headers']['x-chunk-type'], $parsed);
        $this->assertSame(['directory', 'file', 'sql', 'completion'], $types);
    }

    /**
     * Finalize is idempotent — calling it twice must not corrupt the body.
     */
    public function testFinalizeIsIdempotent(): void
    {
        $stream = new MultipartBodyStream();
        $stream->write_sql_chunk("SELECT 1;", '{}', true);
        $stream->finalize();
        $size1 = $stream->get_size();

        $stream->finalize(); // Second call
        $size2 = $stream->get_size();

        $this->assertSame($size1, $size2, "Double finalize must not add extra bytes");
    }

    /**
     * Writing after finalize must throw.
     */
    public function testCannotWriteAfterFinalize(): void
    {
        $stream = new MultipartBodyStream();
        $stream->finalize();

        $this->expectException(RuntimeException::class);
        $stream->write_sql_chunk("SELECT 1;", '{}', true);
    }

    /**
     * get_resource() returns a seekable stream usable by curl.
     */
    public function testGetResourceIsSeekable(): void
    {
        $stream = new MultipartBodyStream();
        $stream->write_sql_chunk("SELECT 1;", '{}', true);
        $stream->finalize();

        $resource = $stream->get_resource();
        $this->assertIsResource($resource);

        // Must be at the beginning (get_resource auto-rewinds)
        $this->assertSame(0, ftell($resource));

        // Must be readable
        $data = fread($resource, 8192);
        $this->assertNotEmpty($data);
    }

    /**
     * get_contents() for HMAC must return the same bytes as reading the resource.
     */
    public function testGetContentsMatchesResourceRead(): void
    {
        $stream = new MultipartBodyStream();
        $stream->write_sql_chunk("INSERT INTO `t` VALUES (1);", '{}', true);
        $stream->write_file_chunk([
            'path' => '/test.txt',
            'data' => 'file data here',
            'size' => 14,
            'ctime' => 0,
            'offset' => 0,
            'is_first_chunk' => true,
            'is_last_chunk' => true,
            'file_changed' => false,
        ], '{}');
        $stream->finalize();

        $contents = $stream->get_contents();
        $resource = $stream->get_resource();
        $resource_data = stream_get_contents($resource);

        $this->assertSame(
            $contents,
            $resource_data,
            "HMAC hash (from get_contents) and curl upload (from get_resource) must see identical bytes"
        );
    }

    /**
     * Content-Type header must be properly formatted for multipart/mixed.
     */
    public function testContentTypeFormat(): void
    {
        $stream = new MultipartBodyStream();

        $ct = $stream->get_content_type();
        $this->assertStringStartsWith('multipart/mixed; boundary="', $ct);

        // Extract boundary from content type and verify it matches
        preg_match('/boundary="([^"]+)"/', $ct, $m);
        $this->assertSame($stream->get_boundary(), $m[1]);
    }

    /**
     * CRITICAL: SQL chunk with Content-Length must parse exactly that many bytes.
     * If the parser reads more or less, statements get truncated or merged.
     */
    public function testSqlChunkContentLengthIsRespected(): void
    {
        $stream = new MultipartBodyStream();

        // SQL with embedded newlines and special characters
        $sql = "INSERT INTO `wp_posts` VALUES (1, 'Line1\nLine2\nLine3');";
        $stream->write_sql_chunk($sql, '{}', true);
        $stream->finalize();

        $body = $stream->get_contents();
        $parsed = parse_multipart_body($body, $stream->get_boundary());

        $this->assertCount(1, $parsed);
        $this->assertSame(
            $sql,
            $parsed[0]['body'],
            "SQL with newlines must survive multipart parsing exactly"
        );
    }
}
