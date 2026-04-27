/// Native replacement for FastInsertScanner::scan().
///
/// Parses the producer-shape INSERT:
///
///   INSERT INTO `table` (`c1`,`c2`, …) VALUES
///     (v1, v2, …), (…), …;
///
/// Values are: NULL | '' | numeric | FROM_BASE64('…') | CONVERT(FROM_BASE64('…') USING utf8mb4)
///
/// Returns None when the statement doesn't match this shape.

pub struct Base64Entry {
    pub expr_start: usize,
    pub quote_start: usize,
    pub quote_length: usize,
    pub value: Vec<u8>, // decoded bytes
}

pub struct ColumnMapEntry {
    pub start: usize,
    pub end: usize,
    pub column: String,
}

pub struct FastInsertResult {
    pub table: String,
    pub column_map: Vec<ColumnMapEntry>,
    pub base64_entries: Vec<Base64Entry>,
}

pub fn fast_insert_scan(sql: &[u8]) -> Option<FastInsertResult> {
    let len = sql.len();
    let mut pos = 0;

    // Skip leading whitespace.
    skip_ws(sql, &mut pos);

    // INSERT
    if !eat_keyword(sql, &mut pos, b"INSERT") {
        return None;
    }
    skip_ws(sql, &mut pos);

    // INTO
    if !eat_keyword(sql, &mut pos, b"INTO") {
        return None;
    }
    skip_ws(sql, &mut pos);

    // `table`
    let table = eat_backtick_id(sql, &mut pos)?;
    skip_ws(sql, &mut pos);

    // ( column list )
    if pos >= len || sql[pos] != b'(' {
        return None;
    }
    pos += 1; // past '('

    let mut columns: Vec<String> = Vec::new();
    loop {
        skip_ws(sql, &mut pos);
        let col = eat_backtick_id(sql, &mut pos)?;
        columns.push(col);
        skip_ws(sql, &mut pos);
        if pos >= len { return None; }
        match sql[pos] {
            b',' => { pos += 1; continue; }
            b')' => { pos += 1; break; }
            _ => return None,
        }
    }
    let column_count = columns.len();
    if column_count == 0 { return None; }

    skip_ws(sql, &mut pos);

    // VALUES
    if !eat_keyword(sql, &mut pos, b"VALUES") {
        return None;
    }

    let mut column_map: Vec<ColumnMapEntry> = Vec::new();
    let mut base64_entries: Vec<Base64Entry> = Vec::new();

    // Parse tuples: (v, v, …), (v, v, …), … [;]?
    loop {
        skip_ws(sql, &mut pos);
        if pos >= len { break; }

        // Statement terminator
        if sql[pos] == b';' {
            pos += 1;
            skip_ws(sql, &mut pos);
            if pos != len { return None; }
            break;
        }
        if pos == len { break; }

        if sql[pos] != b'(' { return None; }
        pos += 1; // past '('

        for col_idx in 0..column_count {
            skip_ws(sql, &mut pos);
            let value_start = pos;

            let entry = scan_value(sql, len, &mut pos)?;

            let value_end = pos;
            column_map.push(ColumnMapEntry {
                start: value_start,
                end: value_end,
                column: columns[col_idx].clone(),
            });

            if let Some(b64) = entry {
                base64_entries.push(b64);
            }

            skip_ws(sql, &mut pos);
            if pos >= len { return None; }

            if col_idx < column_count - 1 {
                if sql[pos] != b',' { return None; }
                pos += 1;
            }
        }

        skip_ws(sql, &mut pos);
        if pos >= len || sql[pos] != b')' { return None; }
        pos += 1; // past ')'

        skip_ws(sql, &mut pos);
        if pos >= len { break; }
        if sql[pos] == b',' {
            pos += 1;
            continue;
        }
        // Allow trailing ';' or end-of-string.
        break;
    }

    Some(FastInsertResult { table, column_map, base64_entries })
}

/// Scan one value, advance pos past it.
/// Returns None on unrecognised shape.
/// Returns Some(None) for non-base64 values.
/// Returns Some(Some(Base64Entry)) for FROM_BASE64 values.
fn scan_value(sql: &[u8], len: usize, pos: &mut usize) -> Option<Option<Base64Entry>> {
    if *pos >= len { return None; }
    let c = sql[*pos];

    // NULL
    if (c == b'N' || c == b'n') && eat_keyword_at(sql, *pos, b"NULL") {
        *pos += 4;
        return Some(None);
    }

    // '' — empty string
    if c == b'\'' {
        if *pos + 1 < len && sql[*pos + 1] == b'\'' {
            *pos += 2;
            return Some(None);
        }
        return None; // non-empty string literal — not producer shape
    }

    // FROM_BASE64('…')
    if (c == b'F' || c == b'f') && eat_keyword_at(sql, *pos, b"FROM_BASE64(") {
        let expr_start = *pos;
        *pos += 12;
        let entry = consume_base64_call(sql, len, pos, expr_start, false)?;
        return Some(Some(entry));
    }

    // CONVERT(FROM_BASE64('…') USING utf8mb4)
    if (c == b'C' || c == b'c') && eat_keyword_at(sql, *pos, b"CONVERT(") {
        let expr_start = *pos;
        *pos += 8;
        skip_ws(sql, pos);
        if !eat_keyword_at(sql, *pos, b"FROM_BASE64(") { return None; }
        *pos += 12;
        let entry = consume_base64_call(sql, len, pos, expr_start, true)?;
        skip_ws(sql, pos);
        if !eat_keyword_at(sql, *pos, b"USING") { return None; }
        *pos += 5;
        skip_ws(sql, pos);
        if !eat_keyword_at(sql, *pos, b"utf8mb4") { return None; }
        *pos += 7;
        skip_ws(sql, pos);
        if *pos >= len || sql[*pos] != b')' { return None; }
        *pos += 1;
        return Some(Some(entry));
    }

    // Numeric literal: [+-]?[0-9]+[.[0-9]+]?[[eE][+-]?[0-9]+]?
    let start = *pos;
    if c == b'+' || c == b'-' { *pos += 1; }
    let digit_start = *pos;
    while *pos < len && sql[*pos].is_ascii_digit() { *pos += 1; }
    if *pos < len && sql[*pos] == b'.' {
        *pos += 1;
        while *pos < len && sql[*pos].is_ascii_digit() { *pos += 1; }
    }
    if *pos < len && (sql[*pos] == b'e' || sql[*pos] == b'E') {
        *pos += 1;
        if *pos < len && (sql[*pos] == b'+' || sql[*pos] == b'-') { *pos += 1; }
        while *pos < len && sql[*pos].is_ascii_digit() { *pos += 1; }
    }
    if *pos == digit_start {
        // No digits found — not a numeric literal and nothing else matched.
        *pos = start;
        return None;
    }

    Some(None)
}

/// Parse FROM_BASE64 payload after the opening `'`.
fn consume_base64_call(
    sql: &[u8],
    len: usize,
    pos: &mut usize,
    expr_start: usize,
    _has_convert: bool,
) -> Option<Base64Entry> {
    skip_ws(sql, pos);
    if *pos >= len || sql[*pos] != b'\'' { return None; }

    let quote_start = *pos;
    *pos += 1; // past opening '

    // Find closing '.
    let payload_start = *pos;
    while *pos < len && sql[*pos] != b'\'' { *pos += 1; }
    if *pos >= len { return None; }

    let payload = &sql[payload_start..*pos];

    // Validate base64 alphabet.
    if !payload.iter().all(|&b| b.is_ascii_alphanumeric() || b == b'+' || b == b'/' || b == b'=') {
        return None;
    }

    *pos += 1; // past closing '
    let quote_length = *pos - quote_start;

    // Closing ) for FROM_BASE64(
    skip_ws(sql, pos);
    if *pos >= len || sql[*pos] != b')' { return None; }
    *pos += 1;

    let decoded = base64_decode(payload);

    Some(Base64Entry { expr_start, quote_start, quote_length, value: decoded })
}

fn base64_decode(input: &[u8]) -> Vec<u8> {
    // Simple base64 decoder — avoids pulling in a crate just for this.
    // Handles standard alphabet and padding.
    static TABLE: [i8; 256] = {
        let mut t = [-1i8; 256];
        let mut i = 0u8;
        while i < 26 { t[(b'A' + i) as usize] = i as i8; i += 1; }
        i = 0;
        while i < 26 { t[(b'a' + i) as usize] = (26 + i) as i8; i += 1; }
        i = 0;
        while i < 10 { t[(b'0' + i) as usize] = (52 + i) as i8; i += 1; }
        t[b'+' as usize] = 62;
        t[b'/' as usize] = 63;
        t[b'=' as usize] = 0; // padding
        t
    };

    let mut out = Vec::with_capacity(input.len() * 3 / 4 + 1);
    let mut buf = 0u32;
    let mut bits = 0u32;

    for &b in input {
        if b == b'=' { continue; }
        let v = TABLE[b as usize];
        if v < 0 { continue; }
        buf = (buf << 6) | v as u32;
        bits += 6;
        if bits >= 8 {
            bits -= 8;
            out.push((buf >> bits) as u8);
            buf &= (1 << bits) - 1;
        }
    }
    out
}

// ─── helpers ───────────────────────────────────────────────────────────────

fn skip_ws(sql: &[u8], pos: &mut usize) {
    while *pos < sql.len() && matches!(sql[*pos], b' ' | b'\t' | b'\n' | b'\r') {
        *pos += 1;
    }
}

/// Case-insensitive keyword check+eat from `pos` to pos + keyword.len().
fn eat_keyword(sql: &[u8], pos: &mut usize, kw: &[u8]) -> bool {
    if eat_keyword_at(sql, *pos, kw) {
        *pos += kw.len();
        true
    } else {
        false
    }
}

fn eat_keyword_at(sql: &[u8], pos: usize, kw: &[u8]) -> bool {
    let end = pos + kw.len();
    if end > sql.len() { return false; }
    sql[pos..end].eq_ignore_ascii_case(kw)
}

fn eat_backtick_id(sql: &[u8], pos: &mut usize) -> Option<String> {
    if *pos >= sql.len() || sql[*pos] != b'`' { return None; }
    *pos += 1;
    let mut name = Vec::new();
    while *pos < sql.len() {
        let b = sql[*pos];
        if b == b'`' {
            *pos += 1;
            if *pos < sql.len() && sql[*pos] == b'`' {
                // Escaped backtick
                name.push(b'`');
                *pos += 1;
            } else {
                break;
            }
        } else {
            name.push(b);
            *pos += 1;
        }
    }
    String::from_utf8(name).ok()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_basic_insert() {
        let sql = b"INSERT INTO `wp_posts` (`ID`,`post_content`) VALUES (1,FROM_BASE64('aGVsbG8='));";
        let r = fast_insert_scan(sql).expect("should parse");
        assert_eq!(r.table, "wp_posts");
        assert_eq!(r.column_map.len(), 2);
        assert_eq!(r.base64_entries.len(), 1);
        assert_eq!(r.base64_entries[0].value, b"hello");
    }

    #[test]
    fn test_null_value() {
        let sql = b"INSERT INTO `t` (`a`,`b`) VALUES (NULL,'');";
        let r = fast_insert_scan(sql).expect("should parse");
        assert_eq!(r.column_map.len(), 2);
        assert_eq!(r.base64_entries.len(), 0);
    }
}
