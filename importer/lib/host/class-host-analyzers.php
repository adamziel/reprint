<?php
/**
 * Registry and detection logic for host analyzers.
 *
 * Provides detect() to score all registered analyzers against preflight
 * data and pick the best match, and for_host() to instantiate one by name.
 */
class HostAnalyzers
{
    /**
     * All known host analyzers, in order of specificity.
     * More specific hosts should come first so they win ties.
     *
     * @return array<string, class-string<HostAnalyzer>>
     */
    private static function registry(): array
    {
        return [
            'wpcloud' => WpcloudHostAnalyzer::class,
            'siteground' => SitegroundHostAnalyzer::class,
        ];
    }

    /**
     * Detect the source host from preflight data using likelihood scoring.
     *
     * Each registered host analyzer scores the preflight data independently.
     * The host with the highest score wins, provided it reaches the minimum
     * threshold of 0.5. Returns "other" if no host qualifies.
     *
     * @param array $preflight_data The preflight response data.
     * @return string The detected host name ("wpcloud", "siteground", "other").
     */
    public static function detect(array $preflight_data): string
    {
        $threshold = 0.5;
        $best_host = 'other';
        $best_score = 0.0;

        foreach (self::registry() as $name => $class) {
            $score = $class::score($preflight_data);
            if ($score >= $threshold && $score > $best_score) {
                $best_host = $name;
                $best_score = $score;
            }
        }

        return $best_host;
    }

    /**
     * Instantiate the right analyzer for a detected host name.
     *
     * @param string $webhost "wpcloud", "siteground", or "other".
     * @return HostAnalyzer
     */
    public static function for_host(string $webhost): HostAnalyzer
    {
        $registry = self::registry();
        if (isset($registry[$webhost])) {
            return new $registry[$webhost]();
        }
        // "other" and unrecognized hosts fall back to the generic analyzer.
        return new SitegroundHostAnalyzer();
    }
}
