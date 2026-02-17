<?php

require_once __DIR__ . '/wp-stubs.php';

use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\DataLiberation\URL\WPURL;
use function WordPress\DataLiberation\URL\wp_rewrite_urls;
use function WordPress\DataLiberation\URL\is_child_url_of;

/**
 * Rewrites URLs in a single decoded database value using the appropriate
 * structured processor from wp-php-toolkit/data-liberation.
 *
 * Content type determines the rewriting strategy:
 * - Serialized PHP: returned unchanged (rewriting would break s:N: length prefixes)
 * - JSON: recursively walks string values, rewrites URLs with URLInTextProcessor
 * - Text/HTML/Block markup: uses wp_rewrite_urls() which handles HTML attributes,
 *   block comment JSON, text nodes, and CSS url() in style attributes
 */
class SqlValueUrlRewriter
{
    /** @var array<string, string> URL mapping: source_url => target_url */
    private array $url_mapping;

    /** @var array Parsed mapping: [{from_url: URL, to_url: URL}] */
    private array $parsed_mapping;

    /**
     * @param array<string, string> $url_mapping Source URL => target URL mapping.
     */
    public function __construct(array $url_mapping)
    {
        $this->url_mapping = $url_mapping;
        $this->parsed_mapping = [];
        foreach ($url_mapping as $from => $to) {
            $this->parsed_mapping[] = [
                'from_url' => WPURL::parse($from),
                'to_url' => WPURL::parse($to),
            ];
        }
    }

    /**
     * Rewrite URLs in a single decoded value.
     *
     * @param string $value The decoded database value.
     * @return string The rewritten value, or the original if no changes were made.
     */
    public function rewrite(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $type = ContentClassifier::classify($value);

        switch ($type) {
            case ContentClassifier::TYPE_SERIALIZED_PHP:
                // Skip serialized PHP — rewriting would break s:N: length prefixes
                return $value;

            case ContentClassifier::TYPE_JSON:
                return $this->rewrite_json($value);

            case ContentClassifier::TYPE_TEXT:
            default:
                return $this->rewrite_text($value);
        }
    }

    /**
     * Rewrite URLs in a JSON value by recursively walking all string values.
     */
    private function rewrite_json(string $json): string
    {
        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }

        $changed = false;
        $data = $this->walk_json($data, $changed);

        if (!$changed) {
            return $json;
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively walk a JSON-decoded structure and rewrite URLs in string values.
     *
     * @param mixed $data    The JSON-decoded data.
     * @param bool  $changed Set to true if any value was changed.
     * @return mixed The walked data with URLs rewritten.
     */
    private function walk_json($data, bool &$changed)
    {
        if (is_string($data)) {
            $rewritten = $this->rewrite_urls_in_text($data);
            if ($rewritten !== $data) {
                $changed = true;
                return $rewritten;
            }
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->walk_json($value, $changed);
            }
        }

        return $data;
    }

    /**
     * Rewrite URLs in text/HTML/block markup using wp_rewrite_urls().
     * This handles HTML attributes, block comment JSON, text nodes, and CSS url().
     */
    private function rewrite_text(string $value): string
    {
        return wp_rewrite_urls([
            'block_markup' => $value,
            'url-mapping' => $this->url_mapping,
        ]);
    }

    /**
     * Rewrite URLs in a plain text string using URLInTextProcessor.
     * Always produces absolute URLs (not relative) since database values
     * should contain complete URLs.
     */
    private function rewrite_urls_in_text(string $text): string
    {
        $p = new URLInTextProcessor($text);
        while ($p->next_url()) {
            $parsed_url = $p->get_parsed_url();
            if (!$parsed_url) {
                continue;
            }
            foreach ($this->parsed_mapping as $mapping) {
                if (is_child_url_of($parsed_url, $mapping['from_url'])) {
                    $result = WPURL::replace_base_url($parsed_url, [
                        'old_base_url' => $mapping['from_url'],
                        'new_base_url' => $mapping['to_url'],
                    ]);
                    if ($result !== false) {
                        $p->set_raw_url($result->new_raw_url);
                    }
                    break;
                }
            }
        }
        return $p->get_updated_text();
    }
}
