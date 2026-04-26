<?php

/**
 * Tokenization-free scanner for the INSERT shape that MySQLDumpProducer emits.
 *
 * Recovers the same data SqlStatementRewriter needs from a normal lexer pass —
 * table name, byte-offset → column map, and FROM_BASE64() value entries —
 * using strpos/preg_match against the constrained producer shape:
 *
 *     INSERT INTO `table` (`c1`,`c2`, …) VALUES
 *       (v1, v2, v3, …),
 *       (…),
 *       …;
 *
 * Each value is one of:
 *     NULL
 *     '' (empty string literal)
 *     bare numeric literal (-?123, -?1.5, 1e-3, etc.)
 *     FROM_BASE64('<base64chars>')
 *     CONVERT(FROM_BASE64('<base64chars>') USING utf8mb4)
 *
 * Inside FROM_BASE64() the payload is [A-Za-z0-9+/=]*, so it cannot contain
 * the punctuation that would confuse top-level parsing (',', '(', ')', "'").
 * That's what lets us avoid the full SQL grammar.
 *
 * Anything that doesn't match this shape causes scan() to return null, and
 * the caller falls back to the lexer-based path. INSERT … SELECT, hex/binary
 * literals like x'…', escaped quotes, multi-table UPDATEs, etc. all end up
 * on the lexer path — they're rare in producer output and correctness wins
 * over coverage.
 */
class FastInsertScanner
{
    /**
     * Try to scan a producer-shape INSERT statement.
     *
     * @return array{
     *   table: string,
     *   column_map: list<array{int, int, string}>,
     *   base64_entries: list<array{expr_start: int, quote_start: int, quote_length: int, value: string, new_value: ?string}>
     * }|null
     *   Null when the SQL doesn't match the recognised shape.
     */
    public static function scan(string $sql): ?array
    {
        // Header: optional leading whitespace, INSERT (no priority/IGNORE
        // modifiers — producer never emits those), INTO, backticked table,
        // backticked column list, VALUES.
        //
        // Captures: 1=table, 2=column-list-body
        if (!preg_match(
            '/\A\s*INSERT\s+INTO\s+`((?:[^`]|``)+)`\s*\(([^)]+)\)\s*VALUES\b/i',
            $sql,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $table = str_replace('``', '`', $m[1][0]);
        $columns_body = $m[2][0];
        $values_end = $m[0][1] + strlen($m[0][0]);

        // Column list: backtick-quoted identifiers separated by commas. Reject
        // anything that doesn't look like producer output — qualified names,
        // unquoted identifiers, comments, etc.
        $columns = [];
        if (
            !preg_match_all(
                '/\s*`((?:[^`]|``)+)`\s*(,|$)/A',
                $columns_body,
                $col_matches,
                PREG_SET_ORDER
            )
        ) {
            return null;
        }
        $offset = 0;
        foreach ($col_matches as $cm) {
            // PREG_SET_ORDER without /A doesn't enforce contiguous matching,
            // but with /A each match must start at $offset. Verify by
            // recomputing offset and confirming we consumed the whole body.
            $columns[] = str_replace('``', '`', $cm[1]);
            $offset += strlen($cm[0]);
        }
        if ($offset !== strlen($columns_body)) {
            return null;
        }
        $column_count = count($columns);
        if ($column_count === 0) {
            return null;
        }

        $column_map = [];
        $base64_entries = [];

        $cursor = $values_end;
        $sql_len = strlen($sql);

        // Walk one tuple at a time. Each tuple is `(v, v, v, …)` followed by
        // either a comma (next tuple) or the statement terminator family
        // ({whitespace} ; | $).
        while (true) {
            // Skip whitespace.
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $sql_len) {
                break;
            }
            // Statement terminator: optional `;` then end-of-string. Anything
            // else (ON DUPLICATE KEY UPDATE, RETURNING, comments, …) drops
            // us out of the fast path.
            if ($sql[$cursor] === ';') {
                $cursor++;
                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }
                if ($cursor !== $sql_len) {
                    return null;
                }
                break;
            }
            if ($sql[$cursor] !== '(') {
                return null;
            }
            $cursor++; // step past '('

            for ($col_idx = 0; $col_idx < $column_count; $col_idx++) {
                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }
                $value_start = $cursor;
                $value_kind = self::scan_value($sql, $sql_len, $cursor);
                if ($value_kind === null) {
                    return null;
                }
                $value_end = $cursor;
                $column_map[] = [$value_start, $value_end, $columns[$col_idx]];

                if (is_array($value_kind)) {
                    // FROM_BASE64 payload: kind = [expr_start, quote_start, quote_length, decoded_value]
                    $base64_entries[] = [
                        'expr_start' => $value_kind[0],
                        'quote_start' => $value_kind[1],
                        'quote_length' => $value_kind[2],
                        'value' => $value_kind[3],
                        'new_value' => null,
                    ];
                }

                while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                    $cursor++;
                }
                if ($cursor >= $sql_len) {
                    return null;
                }
                if ($sql[$cursor] === ',') {
                    if ($col_idx === $column_count - 1) {
                        // Trailing comma before close — not producer shape.
                        return null;
                    }
                    $cursor++;
                    continue;
                }
                if ($sql[$cursor] === ')') {
                    if ($col_idx !== $column_count - 1) {
                        // Tuple closed before consuming all columns.
                        return null;
                    }
                    break;
                }
                return null;
            }

            if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
                return null;
            }
            $cursor++; // step past ')'

            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor < $sql_len && $sql[$cursor] === ',') {
                $cursor++;
                continue; // next tuple
            }
            // Either ';' or end-of-string (or whitespace then either) loops back.
        }

        return [
            'table' => $table,
            'column_map' => $column_map,
            'base64_entries' => $base64_entries,
        ];
    }

    /**
     * Scan one value in producer's tuple shape, advancing $cursor past it.
     *
     * @return null|true|array{int,int,int,string}
     *   null = unrecognized shape (caller bails)
     *   true = recognised, no FROM_BASE64 entry to record
     *   array = FROM_BASE64 payload, [expr_start, quote_start, quote_length, decoded]
     */
    private static function scan_value(string $sql, int $sql_len, int &$cursor)
    {
        if ($cursor >= $sql_len) {
            return null;
        }
        $c = $sql[$cursor];

        // NULL
        if (
            ($c === 'N' || $c === 'n')
            && $cursor + 4 <= $sql_len
            && substr_compare($sql, 'NULL', $cursor, 4, true) === 0
        ) {
            $cursor += 4;
            return true;
        }

        // Empty string literal
        if ($c === "'" && $cursor + 1 < $sql_len && $sql[$cursor + 1] === "'") {
            $cursor += 2;
            return true;
        }

        // FROM_BASE64('…')
        if (
            ($c === 'F' || $c === 'f')
            && $cursor + 13 <= $sql_len
            && substr_compare($sql, 'FROM_BASE64(', $cursor, 12, true) === 0
        ) {
            $expr_start = $cursor;
            $cursor += 12; // step past "FROM_BASE64("
            return self::consume_base64_call($sql, $sql_len, $cursor, $expr_start, /*has_convert=*/false);
        }

        // CONVERT(FROM_BASE64('…') USING utf8mb4)
        if (
            ($c === 'C' || $c === 'c')
            && $cursor + 8 <= $sql_len
            && substr_compare($sql, 'CONVERT(', $cursor, 8, true) === 0
        ) {
            $expr_start = $cursor;
            $cursor += 8;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if (
                $cursor + 12 > $sql_len
                || substr_compare($sql, 'FROM_BASE64(', $cursor, 12, true) !== 0
            ) {
                return null;
            }
            $cursor += 12;
            $entry = self::consume_base64_call($sql, $sql_len, $cursor, $expr_start, /*has_convert=*/true);
            if (!is_array($entry)) {
                return null;
            }
            // Expect: USING utf8mb4 )
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor + 5 > $sql_len || substr_compare($sql, 'USING', $cursor, 5, true) !== 0) {
                return null;
            }
            $cursor += 5;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor + 7 > $sql_len || substr_compare($sql, 'utf8mb4', $cursor, 7, true) !== 0) {
                return null;
            }
            $cursor += 7;
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
                return null;
            }
            $cursor++;
            return $entry;
        }

        // Numeric literal: optional sign, digits, optional fraction, optional exponent.
        $start = $cursor;
        if ($c === '+' || $c === '-') {
            $cursor++;
        }
        $digit_start = $cursor;
        while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
            $cursor++;
        }
        if ($cursor < $sql_len && $sql[$cursor] === '.') {
            $cursor++;
            while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
                $cursor++;
            }
        }
        if ($cursor < $sql_len && ($sql[$cursor] === 'e' || $sql[$cursor] === 'E')) {
            $cursor++;
            if ($cursor < $sql_len && ($sql[$cursor] === '+' || $sql[$cursor] === '-')) {
                $cursor++;
            }
            while ($cursor < $sql_len && $sql[$cursor] >= '0' && $sql[$cursor] <= '9') {
                $cursor++;
            }
        }
        if ($cursor === $digit_start) {
            $cursor = $start;
            return null;
        }
        return true;
    }

    /**
     * Inside a FROM_BASE64( … ) call, with $cursor already past the opening
     * paren. Reads the quoted base64 string and the closing paren. Returns
     * the [expr_start, quote_start, quote_length, decoded] tuple.
     *
     * @return array{int,int,int,string}|null
     */
    private static function consume_base64_call(string $sql, int $sql_len, int &$cursor, int $expr_start, bool $has_convert)
    {
        while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $sql_len || $sql[$cursor] !== "'") {
            return null;
        }
        $quote_start = $cursor;
        $cursor++;
        $payload_start = $cursor;
        // Producer payload is base64: [A-Za-z0-9+/=]. Find closing quote via
        // strpos. If anything outside that set appears before it, fall back
        // to the lexer.
        $close = strpos($sql, "'", $cursor);
        if ($close === false) {
            return null;
        }
        $payload = substr($sql, $payload_start, $close - $payload_start);
        // Validate the payload — strict base64 alphabet.
        if ($payload !== '' && !preg_match('/\A[A-Za-z0-9+\/=]*\z/', $payload)) {
            return null;
        }
        $cursor = $close + 1;
        $quote_length = $cursor - $quote_start;
        // The closing paren of FROM_BASE64( … )
        if (!$has_convert) {
            while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
                return null;
            }
            $cursor++;
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            $decoded = '';
        }
        return [$expr_start, $quote_start, $quote_length, $decoded];
    }

    private static function is_ws(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
    }
}
