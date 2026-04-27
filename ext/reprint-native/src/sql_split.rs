/// Fast SQL query splitter.
///
/// Implements the same contract as WP_MySQL_Naive_Query_Stream but in a
/// single pass through the buffer using a byte-level state machine.
/// Tracks string literals, backtick identifiers, and comments to find
/// statement-terminating semicolons at the top level.
///
/// Returns (queries, bytes_consumed) where bytes_consumed is the number
/// of bytes that were consumed from the front of the buffer (i.e. the
/// total length of all returned query strings including trailing semicolons).

#[derive(Clone, Copy, PartialEq, Eq)]
enum State {
    Normal,
    SingleQuote,
    DoubleQuote,
    Backtick,
    LineComment, // -- or #
    BlockComment,
}

pub struct SplitResult {
    /// Each complete statement including its trailing semicolon.
    pub queries: Vec<Vec<u8>>,
    /// Byte count consumed from the start of the buffer.
    pub consumed: usize,
}

pub fn sql_split(buf: &[u8], input_complete: bool) -> SplitResult {
    let mut queries: Vec<Vec<u8>> = Vec::new();
    let mut consumed = 0usize;
    let len = buf.len();
    let mut i = 0usize;
    let mut state = State::Normal;

    // Statement start within the current window.
    let mut stmt_start = 0usize;

    while i < len {
        let b = buf[i];

        match state {
            State::Normal => {
                match b {
                    b'\'' => { state = State::SingleQuote; i += 1; }
                    b'"' => { state = State::DoubleQuote; i += 1; }
                    b'`' => { state = State::Backtick; i += 1; }
                    b'-' if i + 1 < len && buf[i + 1] == b'-' => {
                        state = State::LineComment; i += 2;
                    }
                    b'#' => { state = State::LineComment; i += 1; }
                    b'/' if i + 1 < len && buf[i + 1] == b'*' => {
                        state = State::BlockComment; i += 2;
                    }
                    b';' => {
                        // Found a complete statement.
                        let end = i + 1;
                        let stmt = buf[stmt_start..end].to_vec();

                        // Only record if there's something meaningful.
                        // We skip whitespace/comment-only "statements" the same
                        // way the PHP stream does, but keep it simple: any
                        // statement that isn't all whitespace is kept.
                        if !is_all_whitespace(&stmt) {
                            queries.push(stmt);
                        }
                        consumed += end - stmt_start;
                        i = end;
                        stmt_start = i;
                    }
                    _ => { i += 1; }
                }
            }
            State::SingleQuote => {
                match b {
                    b'\\' => { i += 2; } // skip escaped char
                    b'\'' => {
                        if i + 1 < len && buf[i + 1] == b'\'' {
                            i += 2; // doubled quote
                        } else {
                            state = State::Normal; i += 1;
                        }
                    }
                    _ => { i += 1; }
                }
            }
            State::DoubleQuote => {
                match b {
                    b'\\' => { i += 2; }
                    b'"' => {
                        if i + 1 < len && buf[i + 1] == b'"' {
                            i += 2;
                        } else {
                            state = State::Normal; i += 1;
                        }
                    }
                    _ => { i += 1; }
                }
            }
            State::Backtick => {
                match b {
                    b'`' => {
                        if i + 1 < len && buf[i + 1] == b'`' {
                            i += 2;
                        } else {
                            state = State::Normal; i += 1;
                        }
                    }
                    _ => { i += 1; }
                }
            }
            State::LineComment => {
                if b == b'\n' {
                    state = State::Normal;
                }
                i += 1;
            }
            State::BlockComment => {
                if b == b'*' && i + 1 < len && buf[i + 1] == b'/' {
                    state = State::Normal; i += 2;
                } else {
                    i += 1;
                }
            }
        }
    }

    // If input is complete and there's a trailing statement without semicolon,
    // emit it (mirrors PHP's behavior for the last statement).
    if input_complete && stmt_start < len {
        let remaining = &buf[stmt_start..len];
        if !is_all_whitespace(remaining) {
            queries.push(remaining.to_vec());
            consumed += len - stmt_start;
        }
    }

    SplitResult { queries, consumed }
}

fn is_all_whitespace(b: &[u8]) -> bool {
    b.iter().all(|&c| matches!(c, b' ' | b'\t' | b'\n' | b'\r'))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_basic_split() {
        let sql = b"SELECT 1; SELECT 2;";
        let r = sql_split(sql, false);
        assert_eq!(r.queries.len(), 2);
        assert_eq!(r.queries[0], b"SELECT 1;");
        assert_eq!(r.queries[1], b" SELECT 2;");
        assert_eq!(r.consumed, sql.len());
    }

    #[test]
    fn test_semicolon_in_string() {
        let sql = b"INSERT INTO t VALUES('a;b');SELECT 1;";
        let r = sql_split(sql, false);
        assert_eq!(r.queries.len(), 2);
    }

    #[test]
    fn test_incomplete() {
        let sql = b"SELECT 1; SELECT";
        let r = sql_split(sql, false);
        assert_eq!(r.queries.len(), 1);
        assert_eq!(r.consumed, 9); // "SELECT 1;"
    }

    #[test]
    fn test_comment_skipping() {
        let sql = b"-- comment\nSELECT 1; /* block */ SELECT 2;";
        let r = sql_split(sql, false);
        assert_eq!(r.queries.len(), 2);
    }
}
