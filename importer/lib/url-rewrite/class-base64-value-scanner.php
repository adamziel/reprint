<?php

/**
 * Cursor-based processor that iterates over FROM_BASE64('...') values in a SQL
 * statement, letting the caller read and replace each decoded value.
 *
 * Uses WP_MySQL_Lexer for proper tokenization instead of string scanning.
 * Detects CONVERT(FROM_BASE64('...') USING utf8mb4) wrappers automatically —
 * set_value() only replaces the base64 payload inside the quotes, so wrappers
 * are preserved without the caller needing to know about them.
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
     *   'quote_start'  => int    Offset of the quoted string token (including quotes)
     *   'quote_length' => int    Length of the quoted string token
     *   'value'        => string The base64-decoded value
     *   'new_value'    => ?string Non-null when set_value() has been called
     *
     * @var array<int, array{expr_start: int, quote_start: int, quote_length: int, value: string, new_value: ?string}>
     */
    private array $entries = [];

    private int $cursor = -1;

    public function __construct(string $sql)
    {
        $this->sql = $sql;
        $this->scan();
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
        return $this->entries[$this->cursor]['value'];
    }

    /**
     * Replace the decoded value at the current cursor position.
     * The new value will be base64-encoded when get_result() rebuilds the SQL.
     */
    public function set_value(string $new_value): void
    {
        $this->entries[$this->cursor]['new_value'] = $new_value;
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
        $result = $this->sql;

        // Process in reverse order to preserve byte offsets
        for ($i = count($this->entries) - 1; $i >= 0; $i--) {
            $entry = $this->entries[$i];
            if ($entry['new_value'] !== null) {
                $replacement = "'" . base64_encode($entry['new_value']) . "'";
                $result = substr($result, 0, $entry['quote_start'])
                    . $replacement
                    . substr($result, $entry['quote_start'] + $entry['quote_length']);
            }
        }

        return $result;
    }

    /**
     * Tokenize the SQL and find all FROM_BASE64('...') expressions.
     * Tracks whether each is wrapped in CONVERT(...) for correct expr_start offset.
     */
    private function scan(): void
    {
        $lexer = new WP_MySQL_Lexer($this->sql);

        // Track the last two tokens so we can detect CONVERT( before FROM_BASE64.
        // next_token() skips whitespace/comments, so CONVERT ( FROM_BASE64 appears
        // as three consecutive tokens.
        $prev = [null, null];

        while ($lexer->next_token()) {
            $token = $lexer->get_token();

            if (
                $token->id === WP_MySQL_Lexer::IDENTIFIER
                && strtoupper($token->get_value()) === 'FROM_BASE64'
            ) {
                $expr_start = $token->start;

                // If the previous tokens are CONVERT + (, the outer expression
                // starts at CONVERT, not at FROM_BASE64.
                if (
                    $prev[1] !== null
                    && $prev[1]->id === WP_MySQL_Lexer::OPEN_PAR_SYMBOL
                    && $prev[0] !== null
                    && $prev[0]->id === WP_MySQL_Lexer::CONVERT_SYMBOL
                ) {
                    $expr_start = $prev[0]->start;
                }

                // Advance past ( to find the quoted base64 string
                while ($lexer->next_token()) {
                    $inner = $lexer->get_token();
                    if (
                        $inner->id === WP_MySQL_Lexer::SINGLE_QUOTED_TEXT
                        || $inner->id === WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT
                    ) {
                        $decoded = base64_decode($inner->get_value(), true);
                        $this->entries[] = [
                            'expr_start' => $expr_start,
                            'quote_start' => $inner->start,
                            'quote_length' => $inner->length,
                            'value' => $decoded !== false ? $decoded : '',
                            'new_value' => null,
                        ];
                        break;
                    }
                    // Skip the opening parenthesis of FROM_BASE64(
                    if ($inner->id !== WP_MySQL_Lexer::OPEN_PAR_SYMBOL) {
                        break;
                    }
                }
            }

            // Shift the two-token window
            $prev[0] = $prev[1];
            $prev[1] = $token;
        }
    }
}
