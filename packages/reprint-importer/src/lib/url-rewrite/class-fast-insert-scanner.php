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
     *   base64_entries: list<array{expr_start: int, expr_length: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}>
     * }|null
     *   Null when the SQL doesn't match the recognised shape.
     */
    public static function scan(string $sql, bool $http_candidates_only = false): ?array
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

        if ($http_candidates_only) {
            return self::scan_http_candidate_payloads($sql, $sql_len, $table, $columns, $values_end);
        }

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

                if (is_array($value_kind)) {
                    // FROM_BASE64 payload: kind = [expr_start, expr_length, quote_start, quote_length, encoded_value].
                    $column_map[] = [$value_start, $value_end, $columns[$col_idx]];
                    $entry = [
                        'expr_start' => $value_kind[0],
                        'expr_length' => $value_kind[1],
                        'quote_start' => $value_kind[2],
                        'quote_length' => $value_kind[3],
                        'encoded_value' => $value_kind[4],
                        'value' => null,
                        'new_value' => null,
                    ];
                    $base64_entries[] = $entry;
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
     * @return null|true|array{int,int,int,int,string}
     *   null = unrecognized shape (caller bails)
     *   true = recognised, no FROM_BASE64 entry to record
     *   array = FROM_BASE64 payload, [expr_start, expr_length, quote_start, quote_length, encoded]
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
            return self::consume_base64_call($sql, $sql_len, $cursor, $expr_start);
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
            $entry = self::consume_base64_call($sql, $sql_len, $cursor, $expr_start);
            if ($entry === null) {
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
            if (is_array($entry)) {
                $entry[1] = $cursor - $expr_start;
            }
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
     * the [expr_start, expr_length, quote_start, quote_length, encoded] tuple.
     *
     * @return array{int,int,int,int,string}|null
     */
    private static function consume_base64_call(string $sql, int $sql_len, int &$cursor, int $expr_start)
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
        // The closing paren of FROM_BASE64( … ). CONVERT(...) callers still
        // need this consumed before they can verify the USING charset wrapper.
        while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
            return null;
        }
        $cursor++;
        return [$expr_start, $cursor - $expr_start, $quote_start, $quote_length, $payload];
    }

    private static function is_ws(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
    }

    /**
     * Sparse scanner used only by URL rewriting. The statement-level prefilter
     * has already proved that at least one encoded http/https prefix appears
     * somewhere in the SQL, so this path indexes those prefix hits and maps only
     * matching FROM_BASE64 payloads back to their tuple column.
     *
     * @param list<string> $columns
     * @return array{table: string, column_map: list<array{int, int, string}>, base64_entries: list<array{expr_start: int, expr_length: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string, column_name?: string}>}|null
     */
    private static function scan_http_candidate_payloads(string $sql, int $sql_len, string $table, array $columns, int $values_end): ?array
    {
        // The sparse mapper only understands the producer tuple list. Let the
        // lexer path handle trailers whose values do not correspond to the
        // INSERT column list.
        if (stripos($sql, ' ON DUPLICATE ') !== false || stripos($sql, ' RETURNING ') !== false) {
            return null;
        }

        $offsets = self::http_prefix_offsets($sql, $values_end);
        if ($offsets === []) {
            return [
                'table' => $table,
                'column_map' => [],
                'base64_entries' => [],
            ];
        }

        $entries = [];
        $seen_quotes = [];
        $column_count = count($columns);
        foreach ($offsets as $prefix_offset) {
            $entry = self::entry_for_http_prefix_offset($sql, $sql_len, $prefix_offset, $values_end, $columns, $column_count);
            if ($entry === false) {
                return null;
            }
            if ($entry === null) {
                continue;
            }
            if (isset($seen_quotes[$entry['quote_start']])) {
                continue;
            }
            $seen_quotes[$entry['quote_start']] = true;
            $entries[] = $entry;
        }

        usort(
            $entries,
            static fn(array $a, array $b): int => $a['expr_start'] <=> $b['expr_start']
        );

        return [
            'table' => $table,
            'column_map' => [],
            'base64_entries' => $entries,
        ];
    }

    /**
     * @param list<string> $columns
     * @return array{expr_start: int, expr_length: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string, column_name: string}|false|null
     */
    private static function entry_for_http_prefix_offset(string $sql, int $sql_len, int $prefix_offset, int $values_end, array $columns, int $column_count)
    {
        $close = strpos($sql, "'", $prefix_offset);
        if ($close === false) {
            return null;
        }

        $quote_start = self::previous_quote($sql, $prefix_offset, $values_end);
        if ($quote_start === null || $quote_start >= $prefix_offset || $prefix_offset >= $close) {
            return null;
        }

        $open_paren = self::previous_non_ws($sql, $quote_start - 1, $values_end);
        if ($open_paren === null || $sql[$open_paren] !== '(') {
            return null;
        }

        $name_end = self::previous_non_ws($sql, $open_paren - 1, $values_end);
        if ($name_end === null || $name_end - 10 < $values_end) {
            return null;
        }
        $name_start = $name_end - 10;
        if (substr_compare($sql, 'FROM_BASE64', $name_start, 11, true) !== 0) {
            return null;
        }

        $cursor = $close + 1;
        $quote_length = $cursor - $quote_start;
        while ($cursor < $sql_len && self::is_ws($sql[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $sql_len || $sql[$cursor] !== ')') {
            return false;
        }
        $cursor++;
        $expr_start = $name_start;
        $expr_end = $cursor;

        $convert_open = self::previous_non_ws($sql, $name_start - 1, $values_end);
        if ($convert_open !== null && $sql[$convert_open] === '(') {
            $convert_name_end = self::previous_non_ws($sql, $convert_open - 1, $values_end);
            if ($convert_name_end !== null && $convert_name_end - 6 >= $values_end) {
                $convert_name_start = $convert_name_end - 6;
                if (substr_compare($sql, 'CONVERT', $convert_name_start, 7, true) === 0) {
                    $convert_end = self::consume_convert_tail($sql, $sql_len, $cursor);
                    if ($convert_end === null) {
                        return false;
                    }
                    $expr_start = $convert_name_start;
                    $expr_end = $convert_end;
                }
            }
        }

        $column_index = self::column_index_for_expression($sql, $expr_start, $values_end);
        if ($column_index === null || $column_index >= $column_count) {
            return false;
        }

        $payload = substr($sql, $quote_start + 1, $close - $quote_start - 1);
        if ($payload !== '' && !preg_match('/\A[A-Za-z0-9+\/=]*\z/', $payload)) {
            return false;
        }

        return [
            'expr_start' => $expr_start,
            'expr_length' => $expr_end - $expr_start,
            'quote_start' => $quote_start,
            'quote_length' => $quote_length,
            'encoded_value' => $payload,
            'value' => null,
            'new_value' => null,
            'column_name' => $columns[$column_index],
        ];
    }

    private static function consume_convert_tail(string $sql, int $sql_len, int $cursor): ?int
    {
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
        return $cursor + 1;
    }

    private static function column_index_for_expression(string $sql, int $expr_start, int $values_end): ?int
    {
        $depth = 0;
        $tuple_start = null;
        for ($i = $expr_start - 1; $i >= $values_end; $i--) {
            $c = $sql[$i];
            if ($c === ')') {
                $depth++;
            } elseif ($c === '(') {
                if ($depth === 0) {
                    $tuple_start = $i;
                    break;
                }
                $depth--;
            }
        }
        if ($tuple_start === null) {
            return null;
        }

        $column_index = 0;
        $depth = 0;
        for ($i = $tuple_start + 1; $i < $expr_start; $i++) {
            $c = $sql[$i];
            if ($c === "'") {
                $end = strpos($sql, "'", $i + 1);
                if ($end === false || $end >= $expr_start) {
                    return null;
                }
                $i = $end;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $column_index++;
            }
        }

        return $column_index;
    }

    private static function previous_quote(string $sql, int $offset, int $floor): ?int
    {
        for ($i = $offset - 1; $i >= $floor; $i--) {
            if ($sql[$i] === "'") {
                return $i;
            }
        }
        return null;
    }

    private static function previous_non_ws(string $sql, int $offset, int $floor): ?int
    {
        for ($i = $offset; $i >= $floor; $i--) {
            if (!self::is_ws($sql[$i])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @return list<int>
     */
    private static function http_prefix_offsets(string $sql, int $offset = 0): array
    {
        $offsets = [];
        foreach (['aHR0', 'dHA6', 'dHBz', 'dHRw'] as $prefix) {
            $cursor = $offset;
            while (($cursor = strpos($sql, $prefix, $cursor)) !== false) {
                $offsets[] = $cursor;
                $cursor++;
            }
        }

        sort($offsets);
        return $offsets;
    }
}
