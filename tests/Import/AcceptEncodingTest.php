<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Transport\HttpRequestBuilder;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify that HTTP requests never advertise brotli (br) encoding.
 *
 * Not every curl build ships with brotli support. When the client sends
 * "Accept-Encoding: …, br" but curl can't decode it, the transfer fails
 * with "Unrecognized content encoding type."  We must only advertise
 * encodings that every curl build supports: gzip and deflate.
 */
class AcceptEncodingTest extends TestCase
{
    /**
     * The Accept-Encoding header must not include "br" (brotli),
     * because curl may not have brotli support compiled in.
     */
    public function testAcceptEncodingExcludesBrotli(): void
    {
        $headers = HttpRequestBuilder::base_headers('application/json');

        $encoding_header = null;
        foreach ($headers as $header) {
            if (stripos($header, 'Accept-Encoding:') === 0) {
                $encoding_header = $header;
                break;
            }
        }

        $this->assertNotNull($encoding_header, 'Accept-Encoding header must be present');
        $this->assertStringNotContainsString(
            'br',
            $encoding_header,
            'Accept-Encoding must not include brotli (br) — curl may lack brotli support'
        );
        $this->assertStringContainsString('gzip', $encoding_header);
        $this->assertStringContainsString('deflate', $encoding_header);
    }
}
