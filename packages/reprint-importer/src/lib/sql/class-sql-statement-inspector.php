<?php

namespace Reprint\Importer\Sql;

final class SqlStatementInspector
{
    /**
     * Extract the table name from an INSERT INTO statement.
     */
    public static function extract_insert_table(string $query): string
    {
        if (preg_match('/INSERT\s+INTO\s+`([^`]+)`/i', $query, $m)) {
            return $m[1];
        }
        return '?';
    }

    /**
     * Extract a row identifier (PK value or offset) from the INSERT row
     * containing the base64 expression at $offset.
     */
    public static function extract_row_identifier(string $query, int $offset): string
    {
        $row_start = self::find_row_start($query, $offset);
        if ($row_start < 0) {
            return 'offset=?';
        }

        $after = substr($query, $row_start, 40);
        if (preg_match('/^(-?\d+)/', $after, $m)) {
            return 'pk=' . $m[1];
        }
        if (preg_match("/^'([^']{0,30})'/", $after, $m)) {
            return "pk=" . $m[1];
        }
        if (preg_match('/^NULL/i', $after)) {
            return 'pk=NULL';
        }

        return 'offset=?';
    }

    /**
     * Extract the option_name (second column) from a wp_options INSERT row.
     */
    public static function extract_option_name(string $query, int $offset): ?string
    {
        $row_start = self::find_row_start($query, $offset);
        if ($row_start < 0) {
            return null;
        }

        $after = substr($query, $row_start, 200);
        $len = strlen($after);
        $depth = 0;
        $comma_pos = -1;
        for ($i = 0; $i < $len; $i++) {
            $char = $after[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $comma_pos = $i;
                break;
            }
        }

        if ($comma_pos < 0) {
            return null;
        }

        $rest = ltrim(substr($after, $comma_pos + 1));
        if (isset($rest[0]) && $rest[0] === "'") {
            if (preg_match("/^'([^']{0,80})'/", $rest, $m)) {
                return $m[1];
            }
        }
        if (strpos($rest, 'FROM_BASE64(') === 0) {
            if (preg_match("/^FROM_BASE64\\('([A-Za-z0-9+\\/=]+)'\\)/", $rest, $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false) {
                    return substr($decoded, 0, 80);
                }
            }
        }

        return null;
    }

    /**
     * Check whether a SQL statement's first keyword token matches a given token ID.
     * Skips leading whitespace and comments before the first SQL keyword.
     */
    public static function starts_with_token(string $sql, int $expected_token_id): bool
    {
        $lexer = new \WP_MySQL_Lexer($sql);
        while ($lexer->next_token()) {
            $token = $lexer->get_token();
            if (
                $token->id === \WP_MySQL_Lexer::WHITESPACE
                || $token->id === \WP_MySQL_Lexer::COMMENT
                || $token->id === \WP_MySQL_Lexer::MYSQL_COMMENT_START
                || $token->id === \WP_MySQL_Lexer::MYSQL_COMMENT_END
            ) {
                continue;
            }
            return $token->id === $expected_token_id;
        }
        return false;
    }

    private static function find_row_start(string $query, int $offset): int
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $query[$i];
            if ($char === ')') {
                $depth++;
            } elseif ($char === '(') {
                if ($depth === 0) {
                    return $i + 1;
                }
                $depth--;
            }
        }

        return -1;
    }
}
