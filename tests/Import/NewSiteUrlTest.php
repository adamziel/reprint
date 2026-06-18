<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\UrlRewrite\NewSiteUrlResolver;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Test the --new-site-url flag, which derives --rewrite-url mappings
 * for both HTTP and HTTPS variants of the export URL's origin.
 */
class NewSiteUrlTest extends TestCase
{
    private function resolve(array $options, string $export_url): array
    {
        return NewSiteUrlResolver::resolve_options($options, $export_url);
    }

    public function testDerivesHttpAndHttpsFromHttpsUrl(): void
    {
        $options = ['new_site_url' => 'https://new-site.example.com'];
        $options = $this->resolve($options, 'https://old-site.example.com/wp-json/export');

        $this->assertArrayHasKey('rewrite_url', $options);
        $this->assertCount(2, $options['rewrite_url']);
        $this->assertSame(
            ['https://old-site.example.com', 'https://new-site.example.com'],
            $options['rewrite_url'][0]
        );
        $this->assertSame(
            ['http://old-site.example.com', 'https://new-site.example.com'],
            $options['rewrite_url'][1]
        );
    }

    public function testDerivesHttpAndHttpsFromHttpUrl(): void
    {
        $options = ['new_site_url' => 'https://new-site.example.com'];
        $options = $this->resolve($options, 'http://old-site.local/export?key=abc');

        $this->assertCount(2, $options['rewrite_url']);
        $this->assertSame(
            ['https://old-site.local', 'https://new-site.example.com'],
            $options['rewrite_url'][0]
        );
        $this->assertSame(
            ['http://old-site.local', 'https://new-site.example.com'],
            $options['rewrite_url'][1]
        );
    }

    public function testPreservesPort(): void
    {
        $options = ['new_site_url' => 'https://new-site.example.com'];
        $options = $this->resolve($options, 'https://old-site.example.com:8443/export');

        $this->assertCount(2, $options['rewrite_url']);
        $this->assertSame(
            ['https://old-site.example.com:8443', 'https://new-site.example.com'],
            $options['rewrite_url'][0]
        );
        $this->assertSame(
            ['http://old-site.example.com:8443', 'https://new-site.example.com'],
            $options['rewrite_url'][1]
        );
    }

    public function testAppendsToExistingRewriteUrlEntries(): void
    {
        $options = [
            'new_site_url' => 'https://new-site.example.com',
            'rewrite_url' => [
                ['https://cdn.old-site.com', 'https://cdn.new-site.com'],
            ],
        ];
        $options = $this->resolve($options, 'https://old-site.example.com/export');

        $this->assertCount(3, $options['rewrite_url']);
        $this->assertSame(
            ['https://cdn.old-site.com', 'https://cdn.new-site.com'],
            $options['rewrite_url'][0]
        );
        $this->assertSame(
            ['https://old-site.example.com', 'https://new-site.example.com'],
            $options['rewrite_url'][1]
        );
        $this->assertSame(
            ['http://old-site.example.com', 'https://new-site.example.com'],
            $options['rewrite_url'][2]
        );
    }

    public function testNoOpWhenNewSiteUrlNotSet(): void
    {
        $options = [];
        $options = $this->resolve($options, 'https://old-site.example.com/export');

        $this->assertArrayNotHasKey('rewrite_url', $options);
    }

    public function testThrowsOnUnparseableExportUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--new-site-url requires a valid export URL');

        $options = ['new_site_url' => 'https://new-site.example.com'];
        $this->resolve($options, '://not-a-url');
    }

    public function testNewUrlUsedVerbatim(): void
    {
        // The new URL should be used exactly as-is, even with a trailing path
        $options = ['new_site_url' => 'http://localhost:8080/subdir'];
        $options = $this->resolve($options, 'https://old-site.example.com/export');

        $this->assertCount(2, $options['rewrite_url']);
        // Both variants map to the exact same verbatim new URL
        $this->assertSame('http://localhost:8080/subdir', $options['rewrite_url'][0][1]);
        $this->assertSame('http://localhost:8080/subdir', $options['rewrite_url'][1][1]);
    }
}
