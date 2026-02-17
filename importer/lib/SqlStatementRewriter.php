<?php

/**
 * Combines Base64ValueScanner and SqlValueUrlRewriter to rewrite URLs
 * in an entire SQL statement.
 *
 * Only modifies INSERT and UPDATE statements containing FROM_BASE64() expressions.
 * DDL statements (CREATE TABLE, ALTER TABLE, etc.) pass through unchanged.
 * Serialized PHP values are detected and skipped to avoid breaking s:N: length prefixes.
 */
class SqlStatementRewriter
{
    private SqlValueUrlRewriter $url_rewriter;

    public function __construct(SqlValueUrlRewriter $url_rewriter)
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
            $type = ContentClassifier::classify($value);

            // Skip serialized PHP
            if ($type === ContentClassifier::TYPE_SERIALIZED_PHP) {
                continue;
            }

            // Rewrite URLs in the value
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
