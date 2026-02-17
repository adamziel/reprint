<?php

use WordPress\DataLiberation\URL\URLInTextProcessor;

/**
 * Scans decoded string values for HTTP/HTTPS URLs and collects unique
 * domain origins (scheme://host[:port]).
 *
 * Uses URLInTextProcessor from wp-php-toolkit/data-liberation for URL
 * detection — no regex.
 */
class DomainCollector
{
    /** @var array<string, true> Unique domains (scheme://host:port) */
    private array $domains = [];

    /**
     * Scan a decoded string value for HTTP/HTTPS URLs and collect their domains.
     *
     * @param string $value The decoded database value to scan.
     */
    public function scan(string $value): void
    {
        if ($value === '') {
            return;
        }

        $p = new URLInTextProcessor($value);
        while ($p->next_url()) {
            $parsed = $p->get_parsed_url();
            if (!$parsed) {
                continue;
            }

            $scheme = $parsed->protocol; // "https:" with colon
            if ($scheme !== 'http:' && $scheme !== 'https:') {
                continue;
            }

            // Build origin: scheme://host[:port]
            $scheme_clean = rtrim($scheme, ':');
            $host = $parsed->hostname;
            $port = $parsed->port;

            $origin = $scheme_clean . '://' . $host;
            if ($port !== '') {
                $origin .= ':' . $port;
            }

            $this->domains[$origin] = true;
        }
    }

    /**
     * Get sorted list of unique domain origins.
     *
     * @return string[] Sorted list of domain origins.
     */
    public function get_domains(): array
    {
        $domains = array_keys($this->domains);
        sort($domains);
        return $domains;
    }

    /**
     * Merge with a previously saved domain list.
     *
     * @param string[] $domains List of domain origins to merge.
     */
    public function merge(array $domains): void
    {
        foreach ($domains as $domain) {
            $this->domains[$domain] = true;
        }
    }
}
