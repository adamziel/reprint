<?php

namespace Reprint\Importer\UrlRewrite;

use InvalidArgumentException;

final class NewSiteUrlResolver
{
    /**
     * If --new-site-url is set, derive the source origin from the export URL
     * and append implicit --rewrite-url mappings for both HTTP and HTTPS
     * variants of the old URL. The new URL is used verbatim.
     */
    public static function resolve_options(array $options, string $export_url): array
    {
        if (empty($options["new_site_url"])) {
            return $options;
        }

        $parsed_url = parse_url($export_url);
        if (!$parsed_url || !isset($parsed_url['scheme'], $parsed_url['host'])) {
            throw new InvalidArgumentException(
                "--new-site-url requires a valid export URL to derive the source site origin.",
            );
        }

        $host_with_port = $parsed_url['host'];
        if (!empty($parsed_url['port'])) {
            $host_with_port .= ':' . $parsed_url['port'];
        }

        if (!isset($options["rewrite_url"])) {
            $options["rewrite_url"] = [];
        }

        $new_url = $options["new_site_url"];
        $options["rewrite_url"][] = ['https://' . $host_with_port, $new_url];
        $options["rewrite_url"][] = ['http://' . $host_with_port, $new_url];

        return $options;
    }
}
