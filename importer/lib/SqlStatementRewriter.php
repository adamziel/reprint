<?php

/**
 * Combines Base64ValueScanner and StructuredDataUrlRewriter to rewrite URLs
 * in an entire SQL statement.
 *
 * Only modifies INSERT and UPDATE statements containing FROM_BASE64() expressions.
 * DDL statements (CREATE TABLE, ALTER TABLE, etc.) pass through unchanged.
 * All value types (text, JSON, serialized PHP) are passed to StructuredDataUrlRewriter
 * which classifies each value and applies the appropriate rewriting strategy.
 */
class SqlStatementRewriter
{
    private StructuredDataUrlRewriter $url_rewriter;

    public function __construct(StructuredDataUrlRewriter $url_rewriter)
    {
        $this->url_rewriter = $url_rewriter;
    }

    /**
     * Rewrite URLs in a SQL statement.
     *
     * @param string $sql The SQL statement.
     * @return string The modified SQL statement.
     */
    public function rewrite(string $sql): string
    {
        // Quick check: if no base64 values, nothing to rewrite
        if (strpos($sql, "FROM_BASE64(") === false) {
            return $sql;
        }

        // Scan for base64 values
        $matches = Base64ValueScanner::scan($sql);
        if (empty($matches)) {
            return $sql;
        }

        // Process in reverse offset order to preserve positions
        $matches = array_reverse($matches);

        foreach ($matches as $match) {
            $value = $match['value'];

            // Rewrite URLs in the value — StructuredDataUrlRewriter classifies the
            // content type and applies the right strategy for each.
            $rewritten = $this->url_rewriter->rewrite($value);

            // Only replace if the value actually changed
            if ($rewritten !== $value) {
                $sql = Base64ValueScanner::replace(
                    $sql,
                    $match['offset'],
                    $match['length'],
                    $rewritten,
                    $match['is_json']
                );
            }
        }

        return $sql;
    }
}
