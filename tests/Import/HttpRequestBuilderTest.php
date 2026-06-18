<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Transport\HttpRequestBuilder;

require_once __DIR__ . '/../../importer/import.php';

class HttpRequestBuilderTest extends TestCase
{
    public function testBuildsUrlWithEndpointCursorAndParams(): void
    {
        $url = HttpRequestBuilder::url(
            'https://example.com/export.php?secret=abc',
            'file_fetch',
            'cursor-1',
            ['directory' => ['/srv/htdocs', '/wordpress']]
        );

        $parts = parse_url($url);
        $this->assertSame('https', $parts['scheme']);
        $this->assertSame('example.com', $parts['host']);
        $this->assertSame('/export.php', $parts['path']);

        parse_str($parts['query'], $query);
        $this->assertSame('abc', $query['secret']);
        $this->assertSame('file_fetch', $query['endpoint']);
        $this->assertSame('cursor-1', $query['cursor']);
        $this->assertSame(['/srv/htdocs', '/wordpress'], $query['directory']);
        $this->assertMatchesRegularExpression('/^\d+-\d+$/', $query['_cache_bust']);
    }

    public function testBuildsBaseHeadersWithCustomUserAgent(): void
    {
        $headers = HttpRequestBuilder::base_headers('application/json', 'Custom UA');

        $this->assertContains('User-Agent: Custom UA', $headers);
        $this->assertContains('Accept: application/json', $headers);
    }
}
