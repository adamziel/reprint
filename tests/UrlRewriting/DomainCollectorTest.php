<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../importer/lib/DomainCollector.php';

class DomainCollectorTest extends TestCase
{
    public function testCollectsSimpleHttpsDomain(): void
    {
        $collector = new DomainCollector();
        $collector->scan('Visit https://example.com/page for details.');
        $domains = $collector->get_domains();
        $this->assertCount(1, $domains);
        $this->assertEquals('https://example.com', $domains[0]);
    }

    public function testCollectsHttpDomain(): void
    {
        $collector = new DomainCollector();
        $collector->scan('http://old-site.org/about');
        $domains = $collector->get_domains();
        $this->assertCount(1, $domains);
        $this->assertEquals('http://old-site.org', $domains[0]);
    }

    public function testCollectsMultipleDomains(): void
    {
        $collector = new DomainCollector();
        $collector->scan('Links: https://site-a.com/page and https://site-b.com/other');
        $domains = $collector->get_domains();
        $this->assertCount(2, $domains);
        $this->assertContains('https://site-a.com', $domains);
        $this->assertContains('https://site-b.com', $domains);
    }

    public function testDeduplicatesDomains(): void
    {
        $collector = new DomainCollector();
        $collector->scan('https://example.com/page1 and https://example.com/page2');
        $domains = $collector->get_domains();
        $this->assertCount(1, $domains);
        $this->assertEquals('https://example.com', $domains[0]);
    }

    public function testCollectsDomainsFromHtml(): void
    {
        $collector = new DomainCollector();
        $collector->scan('<a href="https://wp.org/plugins">Plugins</a> <img src="https://cdn.wp.org/img.jpg"/>');
        $domains = $collector->get_domains();
        $this->assertContains('https://wp.org', $domains);
        $this->assertContains('https://cdn.wp.org', $domains);
    }

    public function testCollectsDomainsAcrossMultipleScans(): void
    {
        $collector = new DomainCollector();
        $collector->scan('https://first.com/a');
        $collector->scan('https://second.com/b');
        $domains = $collector->get_domains();
        $this->assertCount(2, $domains);
        $this->assertContains('https://first.com', $domains);
        $this->assertContains('https://second.com', $domains);
    }

    public function testMergeWithPreviousList(): void
    {
        $collector = new DomainCollector();
        $collector->scan('https://new-discovery.com/page');
        $collector->merge(['https://previously-found.com', 'https://another.com']);
        $domains = $collector->get_domains();
        $this->assertCount(3, $domains);
        $this->assertContains('https://new-discovery.com', $domains);
        $this->assertContains('https://previously-found.com', $domains);
        $this->assertContains('https://another.com', $domains);
    }

    public function testReturnsSortedList(): void
    {
        $collector = new DomainCollector();
        $collector->scan('https://zebra.com/page https://apple.com/page https://mango.com/page');
        $domains = $collector->get_domains();
        $this->assertEquals(['https://apple.com', 'https://mango.com', 'https://zebra.com'], $domains);
    }

    public function testEmptyStringReturnsNoDomains(): void
    {
        $collector = new DomainCollector();
        $collector->scan('');
        $this->assertCount(0, $collector->get_domains());
    }

    public function testNoUrlsReturnsNoDomains(): void
    {
        $collector = new DomainCollector();
        $collector->scan('This is just plain text with no URLs.');
        $this->assertCount(0, $collector->get_domains());
    }

    public function testCollectsDomainsWithPorts(): void
    {
        $collector = new DomainCollector();
        $collector->scan('http://localhost:8080/api and https://dev.example.com:3000/page');
        $domains = $collector->get_domains();
        $this->assertContains('http://localhost:8080', $domains);
        $this->assertContains('https://dev.example.com:3000', $domains);
    }

    public function testDifferentProtocolsSameDomainAreSeparate(): void
    {
        $collector = new DomainCollector();
        $collector->scan('http://example.com/a and https://example.com/b');
        $domains = $collector->get_domains();
        $this->assertCount(2, $domains);
        $this->assertContains('http://example.com', $domains);
        $this->assertContains('https://example.com', $domains);
    }
}
