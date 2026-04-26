<?php

/**
 * Naively splits an SQL string into a sequence of queries. It
 * streams the data so you can process very large chunks of SQL
 * without running out of memory.
 *
 * This class is **naive** because it doesn't understand what a
 * valid query is. It uses a small state machine that knows just
 * enough to skip past quoted strings, backtick-quoted identifiers,
 * and SQL comments while looking for top-level semicolons. It
 * does not parse the grammar, so a syntax error inside a string
 * literal won't be detected.
 *
 * Lacking that information, we assume that no SQL query is larger
 * than 2MB and, failing to extract a query from a 2MB buffer,
 * we fail. This heuristic is often sufficient, but may fail in
 * pathological cases.
 *
 * Usage:
 *
 *     $stream = new WP_MySQL_Naive_Query_Stream();
 *     $stream->append_sql( 'SELECT id FROM users; SELECT * FROM posts;' );
 *     while ( $stream->next_query() ) {
 *         $sql_string = $stream->get_query();
 *         // Process the query.
 *     }
 *     $stream->append_sql( 'CREATE TABLE users (id INT, name VARCHAR(255));' );
 *     while ( $stream->next_query() ) {
 *         $sql_string = $stream->get_query();
 *         // Process the query.
 *     }
 *     $stream->mark_input_complete();
 *     $stream->next_query(); // returns false
 *
 * Vendored from WordPress/sqlite-database-integration PR #264.
 */
class WP_MySQL_Naive_Query_Stream {

	private $sql_buffer = '';
	private $input_complete = false;
	private $state = true;
	private $last_query = false;

	/**
	 * Total number of bytes consumed (trimmed from the buffer) so far.
	 * This is the byte offset within the total appended input where the
	 * next unconsumed byte begins. Callers can add the file seek offset
	 * to get the absolute file position after the last extracted query.
	 */
	private $bytes_consumed = 0;

	/**
	 * Cursor inside $sql_buffer where the next query starts. Held across
	 * find_boundary() calls so a partial scan that ran out of buffer
	 * doesn't have to re-scan the bytes already inspected when more
	 * input arrives.
	 */
	private $scan_cursor = 0;

	const STATE_QUERY = 'valid';
	const STATE_SYNTAX_ERROR = 'syntax_error';
	const STATE_PAUSED_ON_INCOMPLETE_INPUT = 'paused_on_incomplete_input';
	const STATE_FINISHED = 'finished';

	/**
	 * The maximum size of the buffer to store the SQL input. We don't
	 * have enough information from the lexer to distinguish between
	 * an incomplete input and a syntax error so we use a heuristic –
	 * if we've accumulated more than this amount of SQL input, we assume
	 * it's a syntax error. That's why this class is called a "naive" query
	 * stream.
	 */
	const MAX_SQL_BUFFER_SIZE = 1024 * 1024 * 2;

	public function __construct() {}

	public function append_sql( string $sql ) {
		if($this->input_complete) {
			return false;
		}
		$this->sql_buffer .= $sql;
		$this->state = self::STATE_QUERY;
		return true;
	}

	public function is_paused_on_incomplete_input(): bool {
		return $this->state === self::STATE_PAUSED_ON_INCOMPLETE_INPUT;
	}

	public function mark_input_complete() {
		$this->input_complete = true;
	}

	public function next_query() {
		$this->last_query = false;
		if($this->state === self::STATE_PAUSED_ON_INCOMPLETE_INPUT) {
			return false;
		}

		$result = $this->do_next_query();
		if(!$result && strlen($this->sql_buffer) > self::MAX_SQL_BUFFER_SIZE) {
			$this->state = self::STATE_SYNTAX_ERROR;
			return false;
		}
		return $result;
	}

	private function do_next_query() {
		$buf = $this->sql_buffer;
		$buf_len = strlen( $buf );

		// Boundary finder advances $scan_cursor as it inspects bytes; if
		// it runs out of buffer mid-string or mid-comment, the cursor
		// stays put so the next append_sql() call resumes scanning from
		// the same spot instead of re-scanning everything.
		$boundary = $this->find_boundary( $buf, $buf_len );
		if ( $boundary === false ) {
			// No top-level `;` yet. If input is complete, the remaining
			// bytes form the final (semicolon-less) statement.
			if ( $this->input_complete ) {
				if ( $buf_len === 0 ) {
					$this->state = self::STATE_FINISHED;
					return false;
				}
				return $this->emit_query( $buf, $buf_len );
			}
			$this->state = self::STATE_PAUSED_ON_INCOMPLETE_INPUT;
			return false;
		}

		// $boundary is the offset of the terminating `;`. The query
		// includes the semicolon (matching the lexer-based original).
		return $this->emit_query( $buf, $boundary + 1 );
	}

	/**
	 * Emit a query from the front of the buffer. $consumed is the
	 * number of bytes the query takes (including any trailing `;`).
	 *
	 * Skips queries that contain only whitespace and comments — the
	 * caller never sees a "comment-only" query, matching the original
	 * has_meaningful_tokens behaviour.
	 *
	 * @return bool True when a meaningful query was extracted.
	 */
	private function emit_query( string $buf, int $consumed ) {
		$query = substr( $buf, 0, $consumed );
		$this->sql_buffer = substr( $buf, $consumed );
		$this->bytes_consumed += $consumed;
		$this->scan_cursor = 0;

		if ( ! $this->has_meaningful_content( $query ) ) {
			// Comment-only / whitespace-only stretch. Drop it on the
			// floor and try the next chunk. The caller treats this as
			// "no query yet"; matches the lexer-path behaviour.
			if ( $this->input_complete && $this->sql_buffer === '' ) {
				$this->state = self::STATE_FINISHED;
				return false;
			}
			// Recurse to pick up the next query in the same call.
			return $this->do_next_query();
		}

		$this->last_query = $query;
		$this->state = self::STATE_QUERY;
		return true;
	}

	/**
	 * Cheap check: does this stretch contain any non-whitespace,
	 * non-comment byte? If not, the original lexer-based code returned
	 * "no meaningful tokens". We mirror that here without re-lexing.
	 *
	 * Handles `--` line comments, `#` line comments, `/* … *\/` block
	 * comments. Inside string literals, anything is "meaningful" (a
	 * statement that's just a string is still a statement).
	 */
	private function has_meaningful_content( string $sql ): bool {
		$len = strlen( $sql );
		$i = 0;
		while ( $i < $len ) {
			$c = $sql[$i];
			if ( $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === ';' ) {
				$i++;
				continue;
			}
			if ( $c === '-' && $i + 1 < $len && $sql[$i + 1] === '-' ) {
				// `--` line comment requires the next byte to be
				// whitespace, end-of-line, or end-of-input.
				if ( $i + 2 >= $len ) { return false; }
				$next = $sql[$i + 2];
				if ( $next === ' ' || $next === "\t" || $next === "\n" || $next === "\r" ) {
					$nl = strpos( $sql, "\n", $i + 2 );
					if ( $nl === false ) { return false; }
					$i = $nl + 1;
					continue;
				}
			}
			if ( $c === '#' ) {
				$nl = strpos( $sql, "\n", $i + 1 );
				if ( $nl === false ) { return false; }
				$i = $nl + 1;
				continue;
			}
			if ( $c === '/' && $i + 1 < $len && $sql[$i + 1] === '*' ) {
				$end = strpos( $sql, '*/', $i + 2 );
				if ( $end === false ) { return false; }
				$i = $end + 2;
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Walk the buffer looking for the byte offset of the top-level
	 * semicolon that ends the next statement. Skips past:
	 *   - single-quoted strings ('…', with '' and \\' escapes)
	 *   - double-quoted strings ("…", with "" and \\" escapes)
	 *   - backtick-quoted identifiers (`…`, with `` escape)
	 *   - line comments (-- … \n, # … \n)
	 *   - block comments (/* … *\/)
	 *
	 * Uses strcspn() to skip stretches of "boring" bytes in C-speed,
	 * which is the difference between O(bytes) PHP work per byte and
	 * O(bytes) C work per byte. The original implementation invoked
	 * WP_MySQL_Lexer once per next_query() call and re-tokenized the
	 * entire buffer just to find the next semicolon — quadratic in
	 * the number of statements per buffer.
	 *
	 * Returns the offset of the terminating `;`, or false when the
	 * scanner ran out of buffer mid-construct (caller waits for more
	 * input). $this->scan_cursor is advanced as bytes are inspected,
	 * so a future append_sql() resumes scanning from where we paused.
	 *
	 * @return int|false
	 */
	private function find_boundary( string $buf, int $buf_len ) {
		$i = $this->scan_cursor;
		// Bytes that need state-machine attention. Anything else is
		// payload we can fast-skip with strcspn.
		static $stop_chars = "'\"`;-#/";

		while ( $i < $buf_len ) {
			$skip = strcspn( $buf, $stop_chars, $i );
			$i += $skip;
			if ( $i >= $buf_len ) {
				break;
			}
			$c = $buf[$i];

			if ( $c === ';' ) {
				$this->scan_cursor = $i + 1;
				return $i;
			}

			if ( $c === "'" || $c === '"' ) {
				$end = $this->skip_string( $buf, $buf_len, $i, $c );
				if ( $end === false ) {
					$this->scan_cursor = $i; // resume here when more input arrives
					return false;
				}
				$i = $end;
				continue;
			}

			if ( $c === '`' ) {
				$end = $this->skip_backtick( $buf, $buf_len, $i );
				if ( $end === false ) {
					$this->scan_cursor = $i;
					return false;
				}
				$i = $end;
				continue;
			}

			if ( $c === '-' ) {
				if ( $i + 1 < $buf_len && $buf[$i + 1] === '-' ) {
					if ( $i + 2 >= $buf_len ) {
						$this->scan_cursor = $i;
						return false;
					}
					$next = $buf[$i + 2];
					if ( $next === ' ' || $next === "\t" || $next === "\n" || $next === "\r" ) {
						$nl = strpos( $buf, "\n", $i + 2 );
						if ( $nl === false ) {
							$this->scan_cursor = $i;
							return false;
						}
						$i = $nl + 1;
						continue;
					}
				}
				$i++; // bare '-' is just an operator byte
				continue;
			}

			if ( $c === '#' ) {
				$nl = strpos( $buf, "\n", $i + 1 );
				if ( $nl === false ) {
					$this->scan_cursor = $i;
					return false;
				}
				$i = $nl + 1;
				continue;
			}

			if ( $c === '/' ) {
				if ( $i + 1 < $buf_len && $buf[$i + 1] === '*' ) {
					$end = strpos( $buf, '*/', $i + 2 );
					if ( $end === false ) {
						$this->scan_cursor = $i;
						return false;
					}
					$i = $end + 2;
					continue;
				}
				$i++;
				continue;
			}

			// Unreachable — strcspn only stops on $stop_chars.
			$i++;
		}

		$this->scan_cursor = $i;
		return false;
	}

	/**
	 * Skip past a single- or double-quoted MySQL string literal that
	 * opens at $start with the quote $quote. Returns the offset of the
	 * byte immediately after the closing quote, or false if the buffer
	 * ran out before the string was closed.
	 *
	 * Inside the literal, MySQL accepts two escape conventions:
	 *   - doubled quote ('' inside '…', "" inside "…")
	 *   - backslash escape (\\' inside '…', \\" inside "…")
	 * Both are skipped over without ending the literal.
	 */
	private function skip_string( string $buf, int $buf_len, int $start, string $quote ) {
		$i = $start + 1;
		while ( $i < $buf_len ) {
			// Fast-skip over the bulk of the string body. Inside a
			// quoted literal only `\` and the matching $quote can end
			// the run; a strcspn over those two bytes lets PHP's libc
			// devour 4 KB blocks of base64 payload in microseconds.
			$skip = strcspn( $buf, "\\" . $quote, $i );
			$i += $skip;
			if ( $i >= $buf_len ) {
				return false;
			}
			$c = $buf[$i];
			if ( $c === '\\' ) {
				if ( $i + 1 >= $buf_len ) {
					return false;
				}
				$i += 2; // skip the backslash + escaped byte
				continue;
			}
			// Found the matching $quote. Doubled-quote escape: keep going.
			if ( $i + 1 < $buf_len && $buf[$i + 1] === $quote ) {
				$i += 2;
				continue;
			}
			return $i + 1;
		}
		return false;
	}

	/**
	 * Skip past a backtick-quoted identifier. Doubled backticks (``)
	 * are treated as a literal backtick inside the identifier.
	 */
	private function skip_backtick( string $buf, int $buf_len, int $start ) {
		$i = $start + 1;
		while ( $i < $buf_len ) {
			$pos = strpos( $buf, '`', $i );
			if ( $pos === false ) {
				return false;
			}
			if ( $pos + 1 < $buf_len && $buf[$pos + 1] === '`' ) {
				$i = $pos + 2;
				continue;
			}
			return $pos + 1;
		}
		return false;
	}

	public function get_query() {
		return $this->last_query;
	}

	public function get_state() {
		return $this->state;
	}

	/**
	 * Return the total number of input bytes consumed so far. This counts
	 * only bytes that were part of extracted queries — bytes still sitting
	 * in the internal buffer (partial/incomplete queries) are NOT included.
	 *
	 * Callers can add the initial file seek offset to this value to get the
	 * absolute file position right after the last extracted query, which is
	 * the correct resume point.
	 */
	public function get_bytes_consumed(): int {
		return $this->bytes_consumed;
	}

}
