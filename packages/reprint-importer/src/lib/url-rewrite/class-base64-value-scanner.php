<?php

/**
 * Cursor-based processor that iterates over FROM_BASE64('...') values in a SQL
 * statement, letting the caller read and replace each decoded value.
 *
 * Uses WP_MySQL_Lexer for proper tokenization instead of string scanning.
 * Detects CONVERT(FROM_BASE64('...') USING utf8mb4) wrappers automatically.
 * set_value() only replaces the base64 payload inside the quotes, so wrappers
 * are preserved without the caller needing to know about them. SQLite imports
 * may instead replace a FROM_BASE64() expression, or that exact utf8mb4
 * wrapper, with a MySQL-compatible hex literal, avoiding a user-defined
 * FROM_BASE64() callback for every value.
 *
 * Values are decoded lazily. The scanner keeps the original encoded payload
 * until get_value() is called, so callers can skip obviously irrelevant values
 * without paying base64_decode() for every FROM_BASE64() expression in a large
 * INSERT batch.
 *
 * Usage:
 *     $scanner = new Base64ValueScanner($sql);
 *     while ($scanner->next_value()) {
 *         $decoded = $scanner->get_value();
 *         $rewritten = do_something($decoded);
 *         if ($rewritten !== $decoded) {
 *             $scanner->set_value($rewritten);
 *         }
 *     }
 *     $new_sql = $scanner->get_result_with_base64_payload_replacements();
 */
class Base64ValueScanner
{
    private string $sql;

    /**
     * Each entry tracks one FROM_BASE64() value found in the SQL:
     *   'expr_start'    => int    Offset of the outermost expression (CONVERT or FROM_BASE64)
     *   'expr_length'   => int    Length of the outermost expression
     *   'quote_start'   => int    Offset of the quoted string token (including quotes)
     *   'quote_length'  => int    Length of the quoted string token
     *   'encoded_value' => string The base64 payload
     *   'value'         => ?string The base64-decoded value, cached on demand
     *   'new_value'     => ?string Non-null when set_value() has been called
     *
     * @var array<int, array{expr_start: int, expr_length: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}>
     */
    private array $entries = [];

    private int $cursor = -1;
    private bool $dirty = false;

    /**
     * @param string $sql The SQL statement.
     * @param WP_MySQL_Token[]|null $tokens Optional pre-lexed token list. When
     *   provided, the scanner walks these tokens instead of running its own
     *   WP_MySQL_Lexer pass. Callers that already lex the statement for
     *   another reason (column-map extraction, etc.) can hand the same token
     *   stream in to avoid a redundant tokenization. When null, behavior is
     *   unchanged: the scanner lexes internally.
     */
    public function __construct(string $sql, ?array $tokens = null)
    {
        $this->sql = $sql;
        if ($tokens === null) {
            $lexer = new WP_MySQL_Lexer($this->sql);
            $tokens = $lexer->remaining_tokens();
            if (
                !empty($tokens)
                && end($tokens)->id === WP_MySQL_Lexer::EOF
            ) {
                array_pop($tokens);
            }
            $this->scan_tokens($tokens);
        } else {
            $this->scan_tokens($tokens);
        }
    }

    /**
     * Build a scanner from a pre-built entry list. Used by the
     * tokenization-free FastInsertScanner path — when the caller has
     * already located every FROM_BASE64() expression and captured its payload,
     * the scanner skips the lexer and still decodes values lazily.
     *
     * @param list<array{expr_start: int, expr_length: int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}> $entries
     */
    public static function from_entries(string $sql, array $entries): self
    {
        $instance = new self($sql, []); // empty tokens = no scanning
        $instance->entries = $entries;
        return $instance;
    }

    /**
     * Advance to the next FROM_BASE64() value.
     */
    public function next_value(): bool
    {
        $this->cursor++;
        return $this->cursor < count($this->entries);
    }

    /**
     * Get the decoded value at the current cursor position.
     */
    public function get_value(): string
    {
        if ($this->entries[$this->cursor]['value'] === null) {
            $decoded = self::decode_payload($this->entries[$this->cursor]['encoded_value']);
            $this->entries[$this->cursor]['value'] = $decoded ?? '';
        }

        return $this->entries[$this->cursor]['value'];
    }

    /**
     * Return whether the current encoded payload could decode to a value
     * containing an http:// or https:// scheme.
     *
     * This is a conservative base64 prefilter over the encoded text, not proof
     * that the decoded value contains a URL. false means the payload cannot
     * decode to a lowercase http/https scheme under the checked alignments.
     * true only means it could, so the caller still needs to decode and inspect
     * the value. It mirrors the statement-level prefilter in SqlStatementRewriter,
     * but applies it per payload so one URL-bearing column does not force every
     * neighboring FROM_BASE64() value in the same INSERT batch through
     * base64_decode().
     */
    public function encoded_payload_could_contain_http_scheme(): bool
    {
        $value = $this->entries[$this->cursor]['value'];
        if ($value !== null) {
            return strpos($value, 'http') !== false;
        }

        return self::encoded_payload_could_decode_to_http_scheme($this->entries[$this->cursor]['encoded_value']);
    }

    /**
     * Replace the decoded value at the current cursor position.
     * The new value will be base64-encoded when
     * get_result_with_base64_payload_replacements() rebuilds the SQL.
     */
    public function set_value(string $new_value): void
    {
        $this->entries[$this->cursor]['new_value'] = $new_value;
        $this->dirty = true;
    }

    /**
     * Get the byte offset of the outermost expression for the current value.
     * This is the start of CONVERT(...) if present, otherwise FROM_BASE64(...).
     *
     * SqlStatementRewriter uses this to determine which column a value belongs
     * to by scanning backward through the SQL from this position.
     */
    public function get_match_offset(): int
    {
        return $this->entries[$this->cursor]['expr_start'];
    }

    /**
     * Return SQL with all set_value() replacements applied as new base64
     * payloads inside the original FROM_BASE64() calls.
     *
     * Values that were not modified via set_value() are left unchanged.
     */
    public function get_result_with_base64_payload_replacements(): string
    {
        if (!$this->dirty) {
            return $this->sql;
        }

        $parts = [];
        $cursor = 0;
        foreach ($this->entries as $entry) {
            if ($entry['new_value'] !== null) {
                $replacement = "'" . base64_encode($entry['new_value']) . "'";
                $parts[] = substr($this->sql, $cursor, $entry['quote_start'] - $cursor);
                $parts[] = $replacement;
                $cursor = $entry['quote_start'] + $entry['quote_length'];
            }
        }
        $parts[] = substr($this->sql, $cursor);

        return implode('', $parts);
    }

    /**
     * Return SQL with every decodable FROM_BASE64() expression replaced by a
     * SQLite-compatible literal.
     *
     * The importer already decoded each payload for URL rewriting decisions.
     * For SQLite targets, preserving FROM_BASE64() would make SQLite call back
     * into PHP for every text value during statement execution. Emitting
     * 0x-prefixed hex keeps the MySQL-on-SQLite translator in charge of value
     * boundaries, preserves apostrophes and NUL bytes without quote escaping,
     * and avoids any SQL-text regexp pass. The method name intentionally names
     * the target behavior rather than the current 0x literal spelling.
     */
    public function get_result_with_sqlite_compatible_literals(): string
    {
        if (empty($this->entries)) {
            return $this->sql;
        }

        $parts = [];
        $cursor = 0;
        foreach ($this->entries as $entry) {
            $value = $entry['new_value'] ?? $entry['value'];
            $replacement = null;
            if ($value === null) {
                $value = self::decode_payload($entry['encoded_value']);
            }
            if ($value !== null) {
                // Use 0x... for non-empty values so apostrophes, backslashes,
                // and NUL bytes never enter SQL string-literal escaping.
                // MySQL-on-SQLite rejects a bare 0x literal, so the empty
                // string stays as a normal quoted SQL literal.
                $replacement = $value === '' ? "''" : "0x" . bin2hex($value);
            }

            // If strict base64 decoding fails, preserve the original expression.
            // That keeps malformed or non-literal FROM_BASE64() cases on the
            // existing SQLite UDF path instead of silently changing semantics.
            $parts[] = substr($this->sql, $cursor, $entry['expr_start'] - $cursor);
            $parts[] = $replacement ?? substr($this->sql, $entry['expr_start'], $entry['expr_length']);
            $cursor = $entry['expr_start'] + $entry['expr_length'];
        }
        $parts[] = substr($this->sql, $cursor);

        return implode('', $parts);
    }

    /**
     * Walk a pre-lexed token list to find FROM_BASE64('…') expressions.
     *
     * Equivalent to scan() but reuses tokens already produced by another
     * pass (typically SqlStatementRewriter, which lexes for the column
     * map). Avoids re-running WP_MySQL_Lexer on the same statement.
     *
     * The buffered token array from WP_MySQL_Lexer::remaining_tokens()
     * has already been stripped of whitespace and comments, so the
     * CONVERT + ( + FROM_BASE64 pattern still appears as three
     * consecutive tokens.
     *
     * @param WP_MySQL_Token[] $tokens
     */
    private function scan_tokens(array $tokens): void
    {
        $token_count = count($tokens);

        for ($i = 0; $i < $token_count; $i++) {
            $token = $tokens[$i];

            if (
                $token->id === WP_MySQL_Lexer::IDENTIFIER
                && strtoupper($token->get_value()) === 'FROM_BASE64'
            ) {
                $expr_start = $token->start;

                // Detect the producer's wrapper by looking behind the
                // FROM_BASE64 token. The lexer has already removed whitespace
                // and comments, so CONVERT ( FROM_BASE64 is represented as
                // three adjacent significant tokens.
                if (
                    $i >= 2
                    && $tokens[$i - 1]->id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL
                    && $tokens[$i - 2]->id === WP_MySQL_Lexer::CONVERT_SYMBOL
                ) {
                    $expr_start = $tokens[$i - 2]->start;
                }

                // Walk forward through the exact FROM_BASE64('...') shape:
                // opening parenthesis, quoted payload, closing parenthesis.
                // Any computed argument or malformed call is ignored here and
                // left for normal SQL execution.
                $open_index = $i + 1;
                $payload_index = $i + 2;
                $close_index = $i + 3;
                if (
                    $close_index >= $token_count
                    || $tokens[$open_index]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL
                    || (
                        $tokens[$payload_index]->id !== WP_MySQL_Lexer::SINGLE_QUOTED_TEXT
                        && $tokens[$payload_index]->id !== WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT
                    )
                    || $tokens[$close_index]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL
                ) {
                    continue;
                }

                $expr_end = $tokens[$close_index]->start + $tokens[$close_index]->length;
                if ($expr_start !== $token->start) {
                    // Collapse only CONVERT(FROM_BASE64(...) USING utf8mb4),
                    // the wrapper emitted by our dump producer. Other CONVERT
                    // forms may perform meaningful casts, so SQLite inlining
                    // replaces only the inner FROM_BASE64() call.
                    $convert_end = $this->find_utf8mb4_convert_using_end($tokens, $close_index);
                    if ($convert_end !== null) {
                        $expr_end = $convert_end;
                    } else {
                        $expr_start = $token->start;
                    }
                }

                $inner = $tokens[$payload_index];
                $this->entries[] = [
                    'expr_start' => $expr_start,
                    'expr_length' => $expr_end - $expr_start,
                    'quote_start' => $inner->start,
                    'quote_length' => $inner->length,
                    'encoded_value' => $inner->get_value(),
                    'value' => null,
                    'new_value' => null,
                ];
            }
        }
    }

    /**
     * If the FROM_BASE64() call is wrapped in the producer's exact
     * CONVERT(... USING utf8mb4) shape, return the byte offset immediately
     * after the wrapper. Other CONVERT() forms are intentionally left intact;
     * SQLite inlining can still replace the inner FROM_BASE64() without
     * guessing whether a different cast is redundant.
     *
     * @param WP_MySQL_Token[] $tokens
     */
    private function find_utf8mb4_convert_using_end(array $tokens, int $from_base64_close_index): ?int
    {
        $using_index = $from_base64_close_index + 1;
        $charset_index = $from_base64_close_index + 2;
        $close_index = $from_base64_close_index + 3;
        if (
            $close_index >= count($tokens)
            || $tokens[$using_index]->id !== WP_MySQL_Lexer::USING_SYMBOL
            || strcasecmp($tokens[$charset_index]->get_value(), 'utf8mb4') !== 0
            || $tokens[$close_index]->id !== WP_MySQL_Lexer::CLOSE_PAR_SYMBOL
        ) {
            return null;
        }

        return $tokens[$close_index]->start + $tokens[$close_index]->length;
    }

    private static function encoded_payload_could_decode_to_http_scheme(string $payload): bool
    {
        return strpos($payload, 'aHR0') !== false
            || strpos($payload, 'dHA6') !== false
            || strpos($payload, 'dHBz') !== false
            || strpos($payload, 'dHRw') !== false;
    }

    private static function decode_payload(string $payload): ?string
    {
        $decoded = base64_decode($payload, true);
        return $decoded !== false ? $decoded : null;
    }
}
