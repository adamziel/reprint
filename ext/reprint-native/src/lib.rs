#![cfg_attr(windows, feature(abi_vectorcall))]

mod fast_insert;
mod sql_split;
mod url_utils;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;
use url::Url;

// ─── SQL splitter ────────────────────────────────────────────────────────────

/// Split a SQL buffer into complete statements.
///
/// Returns ['queries' => string[], 'consumed' => int].
/// 'consumed' is the byte count consumed from the front of the buffer.
#[php_function]
pub fn reprint_sql_split(buffer: &str, input_complete: bool) -> PhpResult<ZBox<ZendHashTable>> {
    let result = sql_split::sql_split(buffer.as_bytes(), input_complete);

    let mut out = ZendHashTable::new();

    let mut queries_arr = ZendHashTable::new();
    for (i, q) in result.queries.iter().enumerate() {
        queries_arr.insert_at_index(i as u64, String::from_utf8_lossy(q).into_owned())?;
    }
    out.insert("queries", queries_arr)?;
    out.insert("consumed", result.consumed as i64)?;
    Ok(out)
}

// ─── Fast INSERT scanner ─────────────────────────────────────────────────────

/// Parse a producer-shape INSERT statement.
/// Returns null if the shape is not recognised.
///
/// Returned array mirrors FastInsertScanner::scan():
///   ['table' => str, 'column_map' => [...], 'base64_entries' => [...]]
#[php_function]
pub fn reprint_fast_insert_scan(sql: &str) -> PhpResult<Option<ZBox<ZendHashTable>>> {
    let Some(r) = fast_insert::fast_insert_scan(sql.as_bytes()) else {
        return Ok(None);
    };

    let mut out = ZendHashTable::new();
    out.insert("table", r.table)?;

    // column_map: [[start, end, col_name], ...]
    let mut col_map_arr = ZendHashTable::new();
    for (i, entry) in r.column_map.iter().enumerate() {
        let mut row = ZendHashTable::new();
        row.insert_at_index(0, entry.start as i64)?;
        row.insert_at_index(1, entry.end as i64)?;
        row.insert_at_index(2, entry.column.clone())?;
        col_map_arr.insert_at_index(i as u64, row)?;
    }
    out.insert("column_map", col_map_arr)?;

    // base64_entries: [['expr_start'=>int, 'quote_start'=>int, 'quote_length'=>int, 'value'=>str, 'new_value'=>null], ...]
    let mut b64_arr = ZendHashTable::new();
    for (i, entry) in r.base64_entries.iter().enumerate() {
        let mut row = ZendHashTable::new();
        row.insert("expr_start", entry.expr_start as i64)?;
        row.insert("quote_start", entry.quote_start as i64)?;
        row.insert("quote_length", entry.quote_length as i64)?;
        row.insert("value", String::from_utf8_lossy(&entry.value).into_owned())?;
        row.insert("new_value", ())?;
        b64_arr.insert_at_index(i as u64, row)?;
    }
    out.insert("base64_entries", b64_arr)?;

    Ok(Some(out))
}

// ─── Domain check ────────────────────────────────────────────────────────────

/// Fast multi-domain substring check.
/// Returns true when text contains "href=", "src=", or any of the given domains.
#[php_function]
pub fn reprint_contains_any_domain(text: &str, domains: Vec<String>) -> bool {
    let domain_refs: Vec<&str> = domains.iter().map(|s| s.as_str()).collect();
    url_utils::contains_any_domain(text.as_bytes(), &domain_refs)
}

// ─── Plain-text URL rewriter ─────────────────────────────────────────────────

/// Rewrite URLs in plain text using Rust's WHATWG URL parser.
/// from_url_strings and to_url_strings must be parallel arrays of equal length.
#[php_function]
pub fn reprint_url_rewrite_plain_text(
    text: &str,
    from_url_strings: Vec<String>,
    to_url_strings: Vec<String>,
) -> PhpResult<String> {
    if from_url_strings.len() != to_url_strings.len() {
        return Err("from_urls and to_urls must have equal length".into());
    }

    let mut from_urls: Vec<Url> = Vec::with_capacity(from_url_strings.len());
    let mut to_urls: Vec<Url> = Vec::with_capacity(to_url_strings.len());

    for (f, t) in from_url_strings.iter().zip(to_url_strings.iter()) {
        let furl = Url::parse(f).map_err(|e| format!("Invalid from_url '{f}': {e}"))?;
        let turl = Url::parse(t).map_err(|e| format!("Invalid to_url '{t}': {e}"))?;
        from_urls.push(furl);
        to_urls.push(turl);
    }

    let result = url_utils::url_rewrite_plain_text(text.as_bytes(), &from_urls, &to_urls);
    Ok(String::from_utf8_lossy(&result).into_owned())
}

// ─── Module registration ─────────────────────────────────────────────────────

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
