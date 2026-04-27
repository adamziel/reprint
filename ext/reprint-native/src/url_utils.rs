/// Native URL utilities.
///
/// reprint_contains_any_domain: fast multi-pattern search using Aho-Corasick.
/// reprint_url_rewrite_plain_text: rewrite URLs in plain text using Rust's
/// WHATWG-compliant url crate — replaces URLInTextProcessor + WPURL::parse().

use aho_corasick::{AhoCorasick, AhoCorasickBuilder, MatchKind};
use url::Url;
use memchr;

/// Check whether `text` contains any of the given domain strings as substrings.
/// Also checks for "href=" and "src=" which indicate HTML with rewritable URLs.
pub fn contains_any_domain(text: &[u8], domains: &[&str]) -> bool {
    // Quick pass: HTML attribute markers.
    if memmem(text, b"href=") || memmem(text, b"src=") {
        return true;
    }

    if domains.is_empty() { return false; }

    let ac = AhoCorasickBuilder::new()
        .match_kind(MatchKind::LeftmostFirst)
        .build(domains)
        .expect("aho-corasick build");

    ac.is_match(text)
}

/// Rewrite URLs in plain text.
///
/// Scans `text` for http(s):// occurrences, parses each as a WHATWG URL,
/// checks whether it's a child of any `from_url`, and replaces the matched
/// prefix with the corresponding `to_url`.
///
/// This replaces the PHP `URLInTextProcessor` + `WPURL::parse()` loop.
pub fn url_rewrite_plain_text(
    text: &[u8],
    from_urls: &[Url],
    to_urls: &[Url],
) -> Vec<u8> {
    let mut result: Vec<u8> = Vec::with_capacity(text.len());
    let mut pos = 0;

    while pos < text.len() {
        // Find next http:// or https://
        let Some(http_pos) = find_http(text, pos) else {
            result.extend_from_slice(&text[pos..]);
            break;
        };

        // Append everything before the URL.
        result.extend_from_slice(&text[pos..http_pos]);

        // Extract the URL candidate: run to first whitespace or quote.
        let url_end = url_end_pos(text, http_pos);
        let raw = std::str::from_utf8(&text[http_pos..url_end]).unwrap_or("");

        // Parse with Rust's WHATWG-compliant url crate.
        match Url::parse(raw) {
            Ok(parsed) => {
                let mut replaced = false;
                for (from, to) in from_urls.iter().zip(to_urls.iter()) {
                    if is_child_url_of(&parsed, from) {
                        let rewritten = replace_base_url(&parsed, from, to, raw);
                        result.extend_from_slice(rewritten.as_bytes());
                        replaced = true;
                        break;
                    }
                }
                if !replaced {
                    result.extend_from_slice(&text[http_pos..url_end]);
                }
            }
            Err(_) => {
                result.extend_from_slice(&text[http_pos..url_end]);
            }
        }

        pos = url_end;
    }

    result
}

/// Is `child` a child URL of `base`? (same scheme+host, path starts with base's path)
fn is_child_url_of(child: &Url, base: &Url) -> bool {
    if child.scheme() != base.scheme() { return false; }
    if child.host() != base.host() { return false; }
    if child.port() != base.port() { return false; }

    let base_path = base.path();
    let child_path = child.path();

    if base_path == "/" {
        return true;
    }

    child_path.starts_with(base_path)
        && (child_path.len() == base_path.len()
            || child_path.as_bytes().get(base_path.len()) == Some(&b'/'))
}

/// Replace the base of `url` from `old_base` to `new_base`.
fn replace_base_url(url: &Url, old_base: &Url, new_base: &Url, raw: &str) -> String {
    let old_path = old_base.path();
    let url_path = url.path();

    // Strip the old base path prefix.
    let tail = if url_path.starts_with(old_path) {
        &url_path[old_path.len()..]
    } else {
        url_path
    };

    // Build new URL.
    let mut new = new_base.clone();

    // Append the tail to new_base's path.
    let new_path = if new_base.path().ends_with('/') {
        format!("{}{}", new_base.path(), tail.trim_start_matches('/'))
    } else if tail.is_empty() || tail == "/" {
        new_base.path().to_string()
    } else {
        format!("{}/{}", new_base.path().trim_end_matches('/'), tail.trim_start_matches('/'))
    };
    new.set_path(&new_path);
    new.set_query(url.query());
    new.set_fragment(url.fragment());

    // Preserve trailing-slash style from the original raw URL.
    // Rust's url crate normalises paths, so https://example.com becomes
    // https://example.com/ (path = "/"). We strip the added slash when the
    // original didn't have one.
    let result = new.to_string();
    let orig_path_part = raw.split('?').next().unwrap_or(raw);
    let orig_has_trailing = orig_path_part.ends_with('/');
    let result_ends_with_slash = result.trim_end_matches(|c| c == '?' || c == '#')
        .ends_with('/');

    if orig_has_trailing && !result_ends_with_slash {
        // Original had trailing slash but result lost it — add it back.
        if let Some(qi) = result.find('?').or_else(|| result.find('#')) {
            let (before, after) = result.split_at(qi);
            format!("{}/{}", before, after)
        } else {
            format!("{}/", result)
        }
    } else if !orig_has_trailing && result_ends_with_slash {
        // Result gained a trailing slash from normalisation — strip it,
        // but only if doing so leaves a valid URL (i.e. it still has "://").
        let stripped = result.trim_end_matches('/').to_string();
        if stripped.contains("://") {
            stripped
        } else {
            result
        }
    } else {
        result
    }
}

fn find_http(text: &[u8], start: usize) -> Option<usize> {
    let mut i = start;
    while i < text.len() {
        // Fast scan for 'h' using memchr.
        let rel = memchr::memchr(b'h', &text[i..])?;
        let pos = i + rel;
        if text[pos..].starts_with(b"http://") || text[pos..].starts_with(b"https://") {
            // Require a left boundary: the byte before http must not be
            // alphanumeric or [-_], so we don't match embedded strings like
            // "do-you-knowhttps://...".
            if pos == 0 || is_url_left_boundary(text[pos - 1]) {
                return Some(pos);
            }
        }
        i = pos + 1;
    }
    None
}

/// Returns true when `b` is a valid character to appear immediately before
/// an http(s) URL — i.e. not an alphanumeric, hyphen, or underscore.
fn is_url_left_boundary(b: u8) -> bool {
    !b.is_ascii_alphanumeric() && b != b'-' && b != b'_'
}

fn url_end_pos(text: &[u8], start: usize) -> usize {
    let mut i = start;
    while i < text.len() {
        match text[i] {
            // URL terminators: whitespace, quotes, angle brackets, parens
            b' ' | b'\t' | b'\n' | b'\r' | b'"' | b'\'' | b'<' | b'>' | b')' => break,
            _ => i += 1,
        }
    }
    // Strip trailing punctuation that's unlikely to be part of the URL.
    while i > start && matches!(text[i - 1], b'.' | b',' | b';' | b':' | b'!' | b'?') {
        i -= 1;
    }
    i
}

fn memmem(haystack: &[u8], needle: &[u8]) -> bool {
    if needle.is_empty() { return true; }
    haystack.windows(needle.len()).any(|w| w == needle)
}
