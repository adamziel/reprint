# Profiling and accelerating the `db-*` pipeline stages

## Measurement setup

This run measures `db-apply` only — the e2e site harness needs
NixOS-level services (nginx + php-fpm + MySQL configured via
`nixos-e2e-services.nix`) that aren't running on this Mac, so I
substituted a Docker-only path:

- MySQL 8.0 in Docker, port 33307, `--max_allowed_packet=256M`.
- `.context/bench/seed-and-dump.php` seeds the source DB with
  WordPress-shaped `wp_posts` + `wp_postmeta` (post_content embeds
  `http://localhost:9999/post/$i` so the URL rewriter has work),
  then drives `MySQLDumpProducer` directly to write `db.sql`.
- The importer CLI then runs `db-apply` against an empty target DB
  with `--rewrite-url http://localhost:9999 https://new-site.test`.
- Per-stage breakdown comes from the new
  `.import-apply-profile.json` written by the importer.

Because db-pull runs against a live HTTP exporter (which I don't
have here), this report does **not** measure the HTTP / multipart
transport, the producer's per-row server-side cost, or the
`AdaptiveTuner` behaviour. The numbers below are db-apply only.

PHP 8.4.5, mysql:8.0 in Docker on Apple Silicon Mac, localhost TCP.

## Measured numbers

| Scale | rows | db.sql | statements | wall | read I/O | parse | rewrite | exec |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| small | 17 k | 3.45 MB | 80 | 2.75 s | 0.6 ms | 0.55 s (20%) | 1.53 s (56%) | 0.67 s (24%) |
| medium | 170 k | 35.2 MB | 692 | 25.5 s | 7 ms | 5.53 s (22%) | 15.77 s (62%) | 4.15 s (16%) |
| large | 1.04 M | 224 MB | 4 174 | 159 s | 49 ms | 35.36 s (22%) | 99.77 s (63%) | 23.93 s (15%) |

Large scale matches the bench harness's default seed (320 k posts
+ 720 k postmeta).

Statement kinds at large scale: 4 160 `INSERT` (99.7%), 7 `SET`,
2 `CREATE`, 1 `COMMIT`. The dump prefaces with the standard
`UNIQUE_CHECKS=0; FOREIGN_KEY_CHECKS=0; AUTOCOMMIT=0;` and ends with
a `COMMIT;` (`class-mysql-dump-producer.php:522-541`), so per-row
fsync and FK/unique checks are already off.

Throughput is ~1.4 MB/s of `db.sql` regardless of scale — db-apply
cost is per-byte, not per-statement.

## What the profile actually says

**The URL rewriter is the single biggest hotspot, ~62% of db-apply
wall.** Inside the rewriter, the actual costs are very different
from what I first claimed (`base64_decode` is **not** the problem —
see "what I got wrong" below).

### Top level (medium scale, 35 MB, 692 statements, 26.26 s wall)

| bucket | time | % wall |
|---|---:|---:|
| read I/O | 19 ms | 0.07% |
| query stream `append_sql` | 1.5 ms | 0.006% |
| query stream `next_query` | 5.71 s | 21.8% |
| URL rewrite (total) | 16.57 s | 63.0% |
| `PDO::exec` | 3.85 s | 14.7% |

### Inside the URL rewrite (16.57 s)

| sub-bucket | time | % wall | % of rewrite |
|---|---:|---:|---:|
| `StructuredDataUrlRewriter::rewrite` (per matching value) | 8.51 s | 32.4% | 51% |
| `Base64ValueScanner::__construct` (lexer + decode) | 4.04 s | 15.4% | 24% |
| `map_values_to_columns` (column-map walk) | 3.52 s | 13.4% | 21% |
| `get_result` (string rebuild) | 0.22 s | 0.8% | 1% |
| `get_value` (cursor lookup) | 0.09 s | 0.4% | <1% |

### Inside `Base64ValueScanner::scan()` (4.04 s)

| sub-bucket | time | of which |
|---|---:|---|
| `WP_MySQL_Lexer` token walk | 4.04 s | almost all of scanner_ctor |
| `base64_decode()` (790 000 calls) | 0.45 s | tiny |

### Value counts

- `values_seen`: 790 000
- `values_with_http`: 50 000 (post_content only)
- `values_skipped_no_http`: 740 000 (94%)

### So where is db-apply actually spending time?

Re-allocating the wall-clock pie chart based on the *innermost*
bucket each unit of time is attributable to:

| innermost cost | % wall |
|---|---:|
| `StructuredDataUrlRewriter::rewrite()` on values that contain `http` | 32% |
| `WP_MySQL_Lexer` walk for `Base64ValueScanner::scan()` | 15% |
| `map_values_to_columns` (lexer walk + map build) | 13% |
| `WP_MySQL_Naive_Query_Stream::next_query()` | 22% |
| `PDO::exec()` (localhost) | 15% |
| `base64_decode()` itself | 1.7% |
| read I/O, `append_sql`, `get_result`, etc. | < 3% |

Three cost centres dominate — the structured-data rewriter doing
real work on legitimate URL-bearing values, and the lexer / parser
walking bytes (counted twice within the rewriter, once in the
scanner and again in the column-map walk; plus a third time in the
top-level query stream).

## What I got wrong on the first pass

In the first version of this doc I claimed that **`base64_decode`
was the cost** and that an "encoded-form fast path" (`strpos` for
`aHR0c` against the base64 string before decoding) would gut the
hot path. The measurements say that's wrong. `base64_decode` itself
is 0.45 s out of 26 s — 1.7%. The fast path would skip ~94% of
those calls, saving roughly 0.4 s — barely visible.

What's actually expensive is everything *around* `base64_decode`:
the `WP_MySQL_Lexer` walk that finds the FROM_BASE64 expressions
in the first place, the second lexer walk done by
`map_values_to_columns`, and `StructuredDataUrlRewriter::rewrite`
on the values that *do* contain a URL. Two of these run on every
statement regardless of how many values matter.

## Hotspots, ranked by *measured* impact

### 1. `StructuredDataUrlRewriter::rewrite()` on URL-bearing values  ★★★★★

**32% of wall** (8.51 s of the medium-scale 26 s run). Runs once
per value that contains `http` — 50 000 calls in this dataset, all
on `post_content` (block markup hint). This is real work:
HTML / block-comment-JSON / CSS-url / etc. parsing per value.

Knobs to consider:
- For block markup specifically, much of the cost is the HTML walk.
  If the value contains *no* URL of any host we map (which is
  common — block content with internal links + media references),
  a host-prefix `strpos` check on the *decoded* value before
  invoking the structured pipeline can short-circuit the parse.
  The current `strpos($value, 'http')` skip is too coarse: any
  external URL passes it.
- The `wp_rewrite_urls()` block walker is shared with the
  Data Liberation library. Profile *inside* it before optimizing —
  the cost may be in the HTML tokenizer, attribute decoding, or
  block-comment JSON parse, and each has different fixes.

This is the only hotspot whose cost grows with "real" content
(URL-bearing values). Items 2 and 3 below grow with *raw byte
count*, regardless of URL density.

### 2. `WP_MySQL_Lexer` walk inside `Base64ValueScanner`  ★★★★

**15% of wall** (4.04 s, of which `base64_decode` is only 0.45 s).
The lexer walks every byte of every statement that contains
`FROM_BASE64(` — i.e. every INSERT in a non-trivial dump. Most of
those bytes are inside base64 string literals where there's
nothing the lexer needs to recognize.

Knobs:
- Replace the full `WP_MySQL_Lexer` walk with a lighter scan that
  finds `FROM_BASE64(` and `CONVERT(` token boundaries via `strpos`
  / regex — possible because the producer-emitted SQL has a
  constrained shape. Fall back to the lexer for shapes the fast
  scanner doesn't recognize.
- Have the producer annotate `db.sql` with per-statement metadata
  (the offsets of every `FROM_BASE64('…')` value, the column each
  belongs to). The importer skips the entire scan + column-map
  step. Format-bump on `db.sql`; gated on a magic header for old
  files.

### 3. `map_values_to_columns()` does a *second* lexer walk  ★★★★

**13% of wall** (3.52 s). It walks the same statement again to
recover the table name and (column → byte-offset) map. Combined
with #2, the same statement is tokenized twice by `WP_MySQL_Lexer`
in addition to a third pass by `WP_MySQL_Naive_Query_Stream`.

Knob: fold #2 and #3 into a single lexer pass. Both want the same
token stream; the scanner needs FROM_BASE64 positions, the column
map needs the table name + columns clause + VALUES boundaries.
One walk that emits both is structurally easy.

This + #2 together account for 28% of wall. A merged single-pass
implementation is a candidate for the largest single win.

### 4. `WP_MySQL_Naive_Query_Stream::next_query()`  ★★★

**22% of wall** (5.71 s). Pure byte-walking PHP state machine.
The `append_sql` side is ≈0% — all the cost is in `next_query()`
finding statement boundaries.

Knobs:
- Format change: producer writes per-statement length sentinels
  (e.g. a comment line `-- LEN:12345` between statements);
  importer reads N bytes per query and skips the tokenizer
  entirely. Same format-bump conversation as #2.
- Faster boundary scan inside string literals: today the state
  machine inspects every byte for quote/escape/comment edges; for
  base64 literals (`[A-Za-z0-9+/=]+`) it could `strpos` directly
  for the closing quote.

### 5. `PDO::exec()`  ★★

**15% of wall on localhost**. Sensitive to RTT — on a real
LAN/WAN target this share grows. `mysqli_multi_query` for runs of
homogeneous INSERTs would help, but the localhost ranking
overstates the local benefit and understates the remote benefit.
Re-measure on a non-localhost target before sizing.

### 6. `base64_decode()` itself  ★

**1.7% of wall** (0.45 s for 790 000 calls). Almost free per call.
This is the call I previously claimed was the bottleneck — it's
not. The encoded-form fast path I proposed earlier would help
here, but the win is rounding error.

### 7. `LOAD DATA LOCAL INFILE`  ★★

Only worth scoping after #1–#3 land. Constraints unchanged:
server-config-gated, requires moving FROM_BASE64 + URL rewriting
work client-side, requires reworking cursor resumption.

### 8. db-pull / transport — not measured here

The Docker harness skips the HTTP path. Re-run the full e2e bench
once that infra is available.

### 9. SQLite target — not measured

Different code path, different workload, separate bench needed.

## What "10×" looks like given these numbers

A speed budget for db-apply at the medium-scale 26 s run:

- Today: 26 s.
- After merging #2 + #3 into a single lexer pass: optimistic case
  saves 4 s (cuts the redundant lexer walk). 22 s.
- After format-bump for #4 (length sentinels skip the parser):
  saves up to 5.7 s. 16 s.
- After `mysqli_multi_query` for #5 on localhost: saves up to 3 s.
  13 s. (Bigger gains expected on remote targets — re-measure.)
- After cutting `StructuredDataUrlRewriter` cost in #1 by 50% (a
  real engineering effort, requires its own profile inside the
  block walker): saves 4 s. 9 s.

Rough budget: 26 → 9 s = ~3× without changing transport and
without `LOAD DATA`. Reaching 10× requires `LOAD DATA` or a
fundamental shift (binary protocol, native MySQL `mysqldump`-shaped
dump bypassing FROM_BASE64 entirely for non-binary values, etc.).

These numbers are projections from the measured pie chart. They
are *not* measured speedups — that requires landing each item and
re-running the bench. Project numbers conservatively, claim only
what you measure.

## Reproduce

```bash
docker run --rm -d --name reprint-bench-mysql -p 33307:3306 \
    -e MYSQL_ROOT_PASSWORD=bench -e MYSQL_DATABASE=bench_src \
    mysql:8.0 --max_allowed_packet=256M

# wait for ready
until docker exec reprint-bench-mysql mysqladmin -uroot -pbench ping >/dev/null 2>&1; do sleep 2; done

# scale: small (5k/12k), med (50k/120k), large (320k/720k)
php .context/bench/seed-and-dump.php /tmp/bench-state-large 320007 720015

php importer/import.php db-apply - \
    --state-dir=/tmp/bench-state-large \
    --fs-root=/tmp/bench-state-large/fs \
    --target-host=127.0.0.1 --target-port=33307 \
    --target-user=root --target-pass=bench --target-db=bench_dst \
    --rewrite-url http://localhost:9999 https://new-site.test

cat /tmp/bench-state-large/.import-apply-profile.json
```
