<?php

/**
 * Cursor-based processor that iterates over FROM_BASE64('...') values in a SQL
 * statement, letting the caller read and replace each decoded value.
 *
 * Uses WP_MySQL_Lexer for proper tokenization instead of string scanning.
 * Detects CONVERT(FROM_BASE64('...') USING utf8mb4) wrappers automatically.
 * set_value() only replaces the base64 payload inside the quotes, so wrappers
 * are preserved without the caller needing to know about them. The scanner also
 * records the full expression range when the wrapper shape is complete, which
 * lets SQLite callers replace the function call with a literal instead of
 * invoking a per-row user-defined function.
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
 *     $new_sql = $scanner->get_result();
 */
class Base64ValueScanner
{
    private string $sql;

    /**
     * Each entry tracks one FROM_BASE64() value found in the SQL:
     *   'expr_start'   => int    Offset of the outermost expression (CONVERT or FROM_BASE64)
     *   'expr_length'  => ?int   Length of the complete outer expression, when recognised
     *   'quote_start'  => int    Offset of the quoted string token (including quotes)
     *   'quote_length' => int    Length of the quoted string token
     *   'encoded_value'=> string The base64 payload
     *   'value'        => ?string The base64-decoded value, cached on demand
     *   'new_value'    => ?string Non-null when set_value() has been called
     *
     * @var array<int, array{expr_start: int, expr_length: ?int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}>
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
            $this->scan();
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
     * @param list<array{expr_start: int, expr_length: ?int, quote_start: int, quote_length: int, encoded_value: string, value: ?string, new_value: ?string}> $entries
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
            $decoded = base64_decode($this->entries[$this->cursor]['encoded_value'], true);
            $this->entries[$this->cursor]['value'] = $decoded !== false ? $decoded : '';
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
     * The new value will be base64-encoded when get_result() rebuilds the SQL.
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
     * Return the SQL with all set_value() replacements applied.
     * Values that were not modified via set_value() are left unchanged.
     */
    public function get_result(): string
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
     * Return SQL with every complete FROM_BASE64(...) expression rewritten as a
     * MySQL hex literal that the SQLite driver can translate.
     *
     * The replacement is 0x..., not a quoted SQL string. Hex literals keep
     * arbitrary decoded bytes out of the SQL text, and WP_PDO_MySQL_On_SQLite
     * already translates MySQL hex literals into the SQLite representation it
     * expects. Empty strings use '' because bare 0x is not a valid MySQL token.
     */
    public function get_result_with_sqlite_compatible_literals(): string
    {
        if (empty($this->entries)) {
            return $this->sql;
        }

        $parts = [];
        $cursor = 0;
        $used_sqlite_literals = false;
        foreach ($this->entries as $entry) {
            if ($entry['expr_length'] !== null) {
                $value = $entry['new_value'] ?? $entry['value'];
                if ($value === null) {
                    $decoded = base64_decode($entry['encoded_value'], true);
                    $value = $decoded !== false ? $decoded : '';
                }

                $hex = bin2hex($value);
                $replacement = $hex === '' ? "''" : '0x' . $hex;
                $parts[] = substr($this->sql, $cursor, $entry['expr_start'] - $cursor);
                $parts[] = $replacement;
                $cursor = $entry['expr_start'] + $entry['expr_length'];
                $used_sqlite_literals = true;
            } elseif ($entry['new_value'] !== null) {
                $replacement = "'" . base64_encode($entry['new_value']) . "'";
                $parts[] = substr($this->sql, $cursor, $entry['quote_start'] - $cursor);
                $parts[] = $replacement;
                $cursor = $entry['quote_start'] + $entry['quote_length'];
            }
        }
        $parts[] = substr($this->sql, $cursor);

        return $used_sqlite_literals || $this->dirty ? implode('', $parts) : $this->sql;
    }

    /**
     * Tokenize the SQL and find all FROM_BASE64('...') expressions.
     */
    private function scan(): void
    {
        $lexer = new WP_MySQL_Lexer($this->sql);
        $tokens = $lexer->remaining_tokens();
        if (
            !empty($tokens)
            && end($tokens)->id === WP_MySQL_Lexer::EOF
        ) {
            array_pop($tokens);
        }

        $this->scan_tokens($tokens);
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
        $prev = [null, null];

        for ($i = 0; $i < $token_count; $i++) {
            $token = $tokens[$i];

            if (
                $token->id === WP_MySQL_Lexer::IDENTIFIER
                && strtoupper($token->get_value()) === 'FROM_BASE64'
            ) {
                $expr_start = $token->start;
                $has_convert_wrapper = false;

                if (
                    $prev[1] !== null
                    && $prev[1]->id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL
                    && $prev[0] !== null
                    && $prev[0]->id === WP_MySQL_Lexer::CONVERT_SYMBOL
                ) {
                    $expr_start = $prev[0]->start;
                    $has_convert_wrapper = true;
                }

                // Walk forward to find the quoted base64 payload, mirroring
                // scan(): step past the opening parenthesis, accept either
                // SINGLE_ or DOUBLE_QUOTED_TEXT, abort on anything else.
                for ($j = $i + 1; $j < $token_count; $j++) {
                    $inner = $tokens[$j];
                    if (
                        $inner->id === WP_MySQL_Lexer::SINGLE_QUOTED_TEXT
                        || $inner->id === WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT
                    ) {
                        $expr_length = self::get_expression_length(
                            $tokens,
                            $i,
                            $j,
                            $expr_start,
                            $has_convert_wrapper
                        );
                        $this->entries[] = [
                            'expr_start' => $expr_start,
                            'expr_length' => $expr_length,
                            'quote_start' => $inner->start,
                            'quote_length' => $inner->length,
                            'encoded_value' => $inner->get_value(),
                            'value' => null,
                            'new_value' => null,
                        ];
                        break;
                    }
                    if ($inner->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                        break;
                    }
                }
            }

            $prev[0] = $prev[1];
            $prev[1] = $token;
        }
    }

    /**
     * Return the byte length of the complete expression when the token stream
     * contains a balanced FROM_BASE64(...) or CONVERT(FROM_BASE64(...) USING ...)
     * expression. Null means the scanner can still rewrite the quoted payload,
     * but SQLite literal inlining should leave the expression intact.
     *
     * @param WP_MySQL_Token[] $tokens
     */
    private static function get_expression_length(array $tokens, int $from_base64_index, int $quote_index, int $expr_start, bool $has_convert_wrapper): ?int
    {
        $open_index = $from_base64_index + 1;
        if (
            !isset($tokens[$open_index])
            || $tokens[$open_index]->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL
        ) {
            return null;
        }

        $depth = $has_convert_wrapper ? 2 : 1;
        $token_count = count($tokens);
        for ($i = $quote_index + 1; $i < $token_count; $i++) {
            if ($tokens[$i]->id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                $depth++;
            } elseif ($tokens[$i]->id === WP_MySQL_Lexer::CLOSE_PAR_SYMBOL) {
                $depth--;
                if ($depth === 0) {
                    return ($tokens[$i]->start + $tokens[$i]->length) - $expr_start;
                }
            }
        }

        return null;
    }

    private static function encoded_payload_could_decode_to_http_scheme(string $payload): bool
    {
        return strpos($payload, 'aHR0') !== false
            || strpos($payload, 'dHA6') !== false
            || strpos($payload, 'dHBz') !== false
            || strpos($payload, 'dHRw') !== false;
    }
}
