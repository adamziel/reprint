<?php
/**
 * Host analyzer for SiteGround (and generic shared hosting).
 *
 * SiteGround sites use a standard WordPress directory layout. The main
 * value is preserving PHP INI settings (memory limits, upload sizes) since
 * the target runtime may have different defaults.
 *
 * This analyzer also serves as the fallback for unrecognized hosts.
 */
class SitegroundHostAnalyzer extends HostAnalyzer
{
    public function analyze(array $preflight_data): RuntimeManifest
    {
        $manifest = new RuntimeManifest('siteground');
        $manifest->php_ini = $this->extract_php_ini($preflight_data);
        $manifest->constants = $this->extract_constants($preflight_data);
        $manifest->server_vars = $this->extract_server_vars($preflight_data);
        return $manifest;
    }
}
