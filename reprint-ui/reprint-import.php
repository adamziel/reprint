<?php
/**
 * Reprint importer page — runs INSIDE WordPress Playground.
 *
 * Baked into a Playground Blueprint as a literal file at
 * /wordpress/reprint-import.php. The deployed wizard hands off here
 * after authorising the exporter on WordPress.com, passing api_url +
 * secret in the URL fragment so they never reach a server log.
 *
 *   GET /reprint-import.php          → renders the progress UI
 *   POST /reprint-import.php?action=run  → runs reprint.phar pull,
 *                                          streams JSON-line events
 *   POST /reprint-import.php?action=activate
 *                                    → moves the imported SQLite into
 *                                      place + drops the uploads-proxy
 *                                      mu-plugin, then signals "done"
 */

if (($_GET['action'] ?? '') === 'run') {
    // One-shot endpoint: streams the pull, then runs the local
    // activation (move SQLite into place + drop the uploads-proxy
    // mu-plugin) inside the SAME request — so the JS only needs one
    // fetch. Splitting them previously caused the second POST to
    // 302 inside Playground's request handler before our code ran.
    stream_pull_and_activate();
    exit;
}

render_ui();

// ─────────────────────────────────────────────────────────────────

function render_ui(): void { ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cloning your site · Reprint</title>
<style>
  :root {
    --bg:#0b0d12; --panel:#12151c; --panel-2:#181c26; --border:#242936;
    --fg:#e7ecf3; --muted:#8a94a7; --accent:#7c5cff; --accent-2:#4ad1c2;
    --ok:#3ecf8e; --err:#ff6b6b; --warn:#f7b955;
    --mono:ui-monospace,SFMono-Regular,Menlo,monospace;
  }
  *{box-sizing:border-box}
  html,body{margin:0;background:var(--bg);color:var(--fg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif}
  body{min-height:100vh;background:
    radial-gradient(800px 400px at 10% -10%, rgba(124,92,255,.18), transparent 60%),
    radial-gradient(600px 400px at 90% 0%, rgba(74,209,194,.12), transparent 60%),
    var(--bg);}
  .shell{max-width:760px;margin:0 auto;padding:48px 24px}
  header.brand{display:flex;align-items:center;gap:12px;margin-bottom:24px}
  .logo{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent-2));box-shadow:0 4px 24px rgba(124,92,255,.35)}
  h1{font-size:22px;font-weight:600;letter-spacing:-0.01em;margin:0}
  .subtitle{color:var(--muted);font-size:14px;margin-top:2px}
  .card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:28px;box-shadow:0 1px 0 rgba(255,255,255,.02) inset, 0 12px 32px rgba(0,0,0,.35)}
  .warn{display:flex;align-items:flex-start;gap:10px;margin:0 0 22px;padding:13px 14px;background:rgba(247,185,85,.08);border:1px solid rgba(247,185,85,.35);border-radius:10px;font-size:13px;line-height:1.5;color:#ffd9a8}
  .warn svg{flex:none;width:18px;height:18px;margin-top:1px;color:var(--warn)}
  .progress-list{display:flex;flex-direction:column;gap:10px;margin-top:6px}
  .phase{display:grid;grid-template-columns:28px 1fr auto;gap:12px;align-items:center;padding:12px 14px;background:var(--panel-2);border:1px solid var(--border);border-radius:10px}
  .phase .dot{width:10px;height:10px;border-radius:50%;background:#35394a;justify-self:center}
  .phase.active .dot{background:var(--accent);box-shadow:0 0 12px var(--accent);animation:pulse 1.4s infinite}
  .phase.done .dot{background:var(--ok)}
  .phase.error .dot{background:var(--err)}
  .phase .name{font-size:14px}
  .phase .meta{font-size:12px;color:var(--muted);font-family:var(--mono)}
  @keyframes pulse{50%{opacity:.4}}
  .bar{height:6px;background:var(--panel-2);border-radius:999px;overflow:hidden;margin-top:14px}
  .bar > div{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent-2));width:0%;transition:width .25s}
  .log{margin-top:20px;padding:14px;background:#07080c;border:1px solid var(--border);border-radius:10px;font-family:var(--mono);font-size:12px;color:#c7cddb;max-height:200px;overflow:auto;white-space:pre-wrap}
  .log .err{color:var(--err)}
  .log .info{color:var(--accent-2)}
  .actions{display:flex;gap:10px;align-items:center;margin-top:22px}
  button.primary{background:linear-gradient(135deg,var(--accent),#5a3fe0);color:#fff;border:0;padding:11px 18px;border-radius:9px;font-size:14px;font-weight:500;cursor:pointer;box-shadow:0 6px 18px rgba(124,92,255,.35);transition:transform .06s,box-shadow .15s}
  button.primary:hover{box-shadow:0 10px 24px rgba(124,92,255,.45)}
  button.primary:active{transform:translateY(1px)}
  .hidden{display:none!important}
  .err-banner{margin-top:16px;padding:14px 16px;background:rgba(255,107,107,.08);border:1px solid rgba(255,107,107,.4);border-radius:10px;color:#ffb0b0;font-size:13px;line-height:1.5}
  footer{text-align:center;color:var(--muted);font-size:12px;margin-top:32px}
</style>
</head>
<body>
<div class="shell">
  <header class="brand">
    <div class="logo"></div>
    <div>
      <h1>Cloning your site</h1>
      <div class="subtitle" id="subtitle">Importing into WordPress Playground…</div>
    </div>
  </header>

  <section class="card">
    <div class="warn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
      <div>Don't navigate away or close this tab. The clone is in progress in your browser — leaving this page will lose it.</div>
    </div>

    <div class="progress-list" id="phases">
      <div class="phase" data-phase="preflight"><div class="dot"></div><div class="name">Preflight</div><div class="meta">queued</div></div>
      <div class="phase" data-phase="files"><div class="dot"></div><div class="name">Files</div><div class="meta">queued</div></div>
      <div class="phase" data-phase="database"><div class="dot"></div><div class="name">Database</div><div class="meta">queued</div></div>
      <div class="phase" data-phase="apply_runtime"><div class="dot"></div><div class="name">Finalize</div><div class="meta">queued</div></div>
    </div>
    <div class="bar"><div id="overall-bar"></div></div>
    <div class="log" id="log"></div>

    <div id="error-banner" class="hidden err-banner"></div>

    <div class="actions">
      <button class="primary hidden" id="open-site">Open the imported site →</button>
    </div>
  </section>

  <footer>Powered by <code>reprint.phar</code> — running entirely inside Playground</footer>
</div>

<script>
// Credentials baked in by /reprint.php?action=blueprint at fetch time.
// JSON-encoded so values survive any quoting hazard in the URL.
window.__REPRINT_API_URL__       = <?= json_encode(defined('REPRINT_API_URL') ? REPRINT_API_URL : '') ?>;
window.__REPRINT_SECRET__        = <?= json_encode(defined('REPRINT_SECRET') ? REPRINT_SECRET : '') ?>;
window.__REPRINT_SOURCE_ORIGIN__ = <?= json_encode(defined('REPRINT_SOURCE_ORIGIN') ? REPRINT_SOURCE_ORIGIN : '') ?>;

const $ = (s) => document.querySelector(s);
function logLine(text, cls) {
  const el = $('#log');
  const div = document.createElement('div');
  if (cls) div.className = cls;
  div.textContent = text;
  el.appendChild(div);
  el.scrollTop = el.scrollHeight;
}
function setPhase(phase, state, meta) {
  const el = document.querySelector(`[data-phase="${phase}"]`);
  if (!el) return;
  el.classList.remove('active', 'done', 'error');
  if (state) el.classList.add(state);
  if (meta != null) el.querySelector('.meta').textContent = meta;
}
function showError(msg) {
  const banner = $('#error-banner');
  banner.textContent = msg;
  banner.classList.remove('hidden');
}

let lastFilePath = '';
let skipCount = 0;

function handleEvent(ev) {
  if (!ev || typeof ev !== 'object') return;

  if (ev.message && (ev.type === 'lifecycle' || ev.type === 'message')) {
    logLine(ev.message);
  }

  if (ev.type === 'file_skipped') {
    skipCount++;
  }

  if (ev.type === 'file_progress' || (ev.heartbeat && ev.files_total != null)) {
    const done = ev.files_done ?? 0;
    const total = ev.files_total ?? 0;
    const pct = total ? Math.round((done / total) * 100) : 0;
    if (ev.path) lastFilePath = ev.path;
    const last = lastFilePath ? ' · ' + lastFilePath.slice(-36) : '';
    const skip = skipCount ? ' · ' + skipCount.toLocaleString() + ' skipped' : '';
    setPhase('files', 'active',
      total ? `${done.toLocaleString()} / ${total.toLocaleString()} (${pct}%)${last}${skip}`
            : `${done.toLocaleString()} files${skip}`);
    if (total) $('#overall-bar').style.width = (5 + pct * 0.80) + '%';
  }

  if (ev.phase === 'db-index' && ev.status === 'complete' && ev.tables_processed != null) {
    setPhase('database', 'active', ev.tables_processed + ' tables fetched');
  }
  if (ev.phase === 'sql' && ev.status === 'starting') {
    setPhase('database', 'active', 'downloading SQL dump…');
  }
  if (ev.phase === 'sql' && ev.status === 'complete') {
    $('#overall-bar').style.width = '70%';
  }

  if (ev.phase === 'db-apply') {
    const done = ev.statements_executed ?? 0;
    const total = ev.statements_total ?? 0;
    if (total) {
      const pct = Math.round((done / total) * 100);
      setPhase('database', 'active', `${done.toLocaleString()} / ${total.toLocaleString()} statements (${pct}%)`);
    } else if (done) {
      setPhase('database', 'active', `${done.toLocaleString()} statements`);
    }
    if (ev.status === 'complete') {
      setPhase('database', 'done', `${done.toLocaleString()} statements`);
      $('#overall-bar').style.width = '90%';
    }
  }

  if (ev.command === 'preflight' && ev.status === 'complete') {
    setPhase('preflight', 'done', 'ok');
  }
  if (ev.command === 'flat-docroot' && ev.status === 'complete') {
    setPhase('files', 'done', 'flattened');
  }

  if (ev.status === 'error') {
    const failed = ev.failed_stage || ev.phase || ev.command || 'import';
    const phaseId = failed === 'preflight' ? 'preflight'
                  : failed === 'files-pull' ? 'files'
                  : 'database';
    setPhase(phaseId, 'error', (ev.error || ev.message || 'failed').slice(0, 80));
  }
}

async function runImport() {
  // Credentials are baked into the page server-side by the wizard's
  // ?action=blueprint endpoint. We read them via tiny PHP echoes into
  // window globals, set just before this script runs.
  const apiUrl = window.__REPRINT_API_URL__ || '';
  const secret = window.__REPRINT_SECRET__ || '';
  const source = window.__REPRINT_SOURCE_ORIGIN__ || '';

  if (!apiUrl || !secret) {
    showError('Missing api_url or secret. Re-launch from the wizard so the blueprint can be re-built with credentials.');
    return;
  }

  setPhase('preflight', 'active', 'starting…');
  setPhase('files', 'active', 'queued');
  setPhase('database', 'active', 'queued');
  logLine('→ reprint pull (this can take a few minutes)', 'info');

  let activateResult = null;
  let sawAnyEvent = false;

  try {
    const fd = new FormData();
    fd.append('api_url', apiUrl);
    fd.append('secret', secret);

    const res = await fetch('/reprint-import.php?action=run', { method: 'POST', body: fd });
    if (!res.ok) throw new Error('HTTP ' + res.status);

    // Drain NDJSON. Playground's request handler tends to buffer the
    // body until the PHP script ends, so don't assume we'll see
    // events live — but the loop is the same either way.
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    const consumeLine = (line) => {
      const trimmed = line.trim();
      if (!trimmed) return;
      sawAnyEvent = true;
      try {
        const ev = JSON.parse(trimmed);
        if (ev && ev.type === 'activate') {
          activateResult = ev;
          return;
        }
        handleEvent(ev);
      } catch {
        // Non-JSON line — surface it in the log so PHP errors etc.
        // don't disappear silently.
        logLine(trimmed, 'err');
      }
    };
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let nl;
      while ((nl = buffer.indexOf('\n')) >= 0) {
        consumeLine(buffer.slice(0, nl));
        buffer = buffer.slice(nl + 1);
      }
    }
    consumeLine(buffer);
  } catch (e) {
    showError('Import failed: ' + e.message);
    return;
  }

  if (!sawAnyEvent) {
    showError('The importer returned an empty response. Check the browser console for errors and try again.');
    return;
  }

  if (!activateResult) {
    showError('Pull finished without an activation event — the import probably failed before completing. Open the network tab to see the raw response.');
    return;
  }

  if (activateResult.status !== 'complete') {
    const err = (activateResult.data && activateResult.data.error) || 'unknown';
    setPhase('apply_runtime', 'error', err.slice(0, 80));
    showError('Activation failed: ' + err);
    return;
  }

  // The pull stream may have buffered, in which case our phase rows
  // never moved past 'queued'. Mark them all done now so the user
  // can see the import landed.
  ['preflight', 'files', 'database'].forEach((p) => setPhase(p, 'done', 'ok'));
  setPhase('apply_runtime', 'done', 'sqlite ' + (activateResult.data.sqlite_size || 0) + ' B');
  $('#overall-bar').style.width = '100%';
  $('#subtitle').textContent = 'Done — your site is ready.';
  const btn = $('#open-site');
  btn.classList.remove('hidden');
  btn.onclick = () => {
    // Inside Playground, the iframe URL is something like
    // /scope:funny-name/reprint-import.php. Going to '/' would jump
    // out to playground.wordpress.net's home page instead of the
    // imported site. Strip the file off our own pathname instead so
    // we land on the iframe's own site root.
    const here = window.location.pathname;
    const slash = here.lastIndexOf('/');
    window.location.href = (slash >= 0 ? here.slice(0, slash + 1) : '/') + window.location.search;
  };
}

window.addEventListener('DOMContentLoaded', runImport);
</script>
</body>
</html>
<?php }

// ─────────────────────────────────────────────────────────────────

function stream_pull_and_activate(): void {
    // The phar's CLI entry-point exit(0)s at the end of `pull`, so any
    // code after stream_pull() returns is dead. Register the
    // activation as a shutdown function instead — it runs whether
    // the phar exits cleanly, throws, or returns.
    register_shutdown_function(function () {
        $activate = run_local_activation();
        echo json_encode([
            'type' => 'activate',
            'status' => !empty($activate['ok']) ? 'complete' : 'error',
            'data' => $activate,
        ]) . "\n";
    });
    stream_pull();
}

function stream_pull(): void {
    @ini_set('display_errors', '0');
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @ob_implicit_flush(true);

    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    // Credentials come from POST body (the wizard's JS submits them as
    // form data) but fall back to the constants baked in by
    // /reprint.php?action=blueprint, so direct curl-style invocations
    // work too.
    $api = (string) ($_POST['api_url'] ?? '');
    $secret = (string) ($_POST['secret'] ?? '');
    if ($api === '' && defined('REPRINT_API_URL'))     { $api    = (string) REPRINT_API_URL; }
    if ($secret === '' && defined('REPRINT_SECRET'))   { $secret = (string) REPRINT_SECRET; }
    if ($api === '' || $secret === '') {
        echo json_encode(['type' => 'error', 'message' => 'missing api_url/secret']) . "\n";
        return;
    }

    $phar = '/wordpress/reprint.phar';
    if (!is_file($phar)) {
        echo json_encode(['type' => 'error', 'message' => 'reprint.phar missing at ' . $phar]) . "\n";
        return;
    }

    // The phar is a CLI program. Set up the magic STDOUT/STDERR/STDIN
    // streams it needs, plus IMPORTER_WEB_ENTRY which makes pull's
    // start_server step a no-op (we run the server via Playground itself,
    // not via the phar's built-in PHP server).
    if (!defined('IMPORTER_WEB_ENTRY')) define('IMPORTER_WEB_ENTRY', true);
    if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'w'));
    if (!defined('STDERR')) define('STDERR', fopen('php://output', 'w'));
    if (!defined('STDIN'))  define('STDIN',  fopen('php://memory', 'r'));

    // Disarm Playground's $wpdb proxy. Its 0-sqlite.php auto-prepend
    // installs a Playground_SQLite_Integration_Loader that lazy-loads
    // /internal/shared/sqlite-database-integration on first access.
    // The phar bundles its own copy of the same project at a different
    // path (phar://.../lib/sqlite-database-integration), so once the
    // phar loads its copy and anything later touches $wpdb, the
    // proxy's lazy loader hits a "class WP_Parser_Grammar already
    // declared" fatal mid-import. We don't need $wpdb during the
    // import — the phar drives a separate WP_PDO_MySQL_On_SQLite
    // connection — so just clear the proxy so it can't fire.
    if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])
        && get_class($GLOBALS['wpdb']) === 'Playground_SQLite_Integration_Loader') {
        unset($GLOBALS['wpdb']);
    }

    // Let the phar's progress writer hit php://output directly. With
    // implicit_flush=1 each fwrite turns into an HTTP chunk — assuming
    // Playground's request handler propagates partial bodies. If it
    // buffers, the browser still gets a single ndjson blob at the end
    // and the JS reader drains it fine.
    global $argv, $argc;
    $argv = [
        $phar,
        'pull',
        $api,
        '--secret=' . $secret,
        '--state-dir=/internal/shared/reprint-state',
        '--fs-root=/internal/shared/reprint-site',
        '--target-engine=sqlite',
        '--target-sqlite-path=/internal/shared/imported.sqlite',
        '--flatten-to=/wordpress',
        '--new-site-url=' . _reprint_self_origin(),
        // The web Playground iframe doesn't have a runtime= adapter
        // (no host-FS mounts, no start.sh). Skip apply-runtime; we do
        // a lightweight activation step ourselves on completion.
        '--runtime=none',
        '--no-adaptive',
        // Defer uploads on the initial pull — they're the bulk of the
        // bytes and not strictly needed for the site to boot. The
        // uploads-proxy mu-plugin redirects missing /wp-content/uploads/*
        // to the source site so media still renders. Users can opt
        // into a follow-up files-pull --filter=skipped-earlier later.
        '--filter=essential-files',
        // /wordpress already has Playground's fresh WP install. The
        // flat-docroot stage symlinks the imported tree on top, and
        // refuses to clobber existing files unless we say so. We do
        // — the install is empty/disposable, the imported site is
        // what the user actually wants to see.
        '--force',
    ];
    $argc = count($argv);

    try {
        include $phar;
    } catch (Throwable $e) {
        echo json_encode([
            'type' => 'error',
            'failed_stage' => 'pull',
            'message' => $e->getMessage(),
        ]) . "\n";
    }
}

function run_local_activation(): array {
    if (!is_dir('/wordpress/wp-content')) {
        return ['ok' => false, 'error' => '/wordpress/wp-content missing after pull'];
    }

    // 1. Move the imported SQLite into WP's expected location.
    @mkdir('/wordpress/wp-content/database', 0777, true);
    $src = '/internal/shared/imported.sqlite';
    $dst = '/wordpress/wp-content/database/.ht.sqlite';
    $src_size = is_file($src) ? filesize($src) : 0;
    if ($src_size > 0) {
        if (!@rename($src, $dst)) {
            @copy($src, $dst);
            @unlink($src);
        }
    }
    $sqlite_size = is_file($dst) ? filesize($dst) : 0;

    // 2. Drop the uploads-proxy mu-plugin so missing /wp-content/uploads/*
    //    requests redirect back to the source site, keeping media live
    //    until uploads are fetched locally. The source origin is
    //    baked into the importer page by /reprint.php?action=blueprint.
    $source_origin = defined('REPRINT_SOURCE_ORIGIN') ? (string) REPRINT_SOURCE_ORIGIN : '';
    if ($source_origin !== '') {
        @mkdir('/wordpress/wp-content/mu-plugins', 0777, true);
        $mu = "<?php\n"
            . "// Reprint uploads proxy — redirect missing /wp-content/uploads/*\n"
            . "// requests to the source site so media keeps rendering until\n"
            . "// the uploads are downloaded locally.\n"
            . "add_action('init', function () {\n"
            . "  \$req = \$_SERVER['REQUEST_URI'] ?? '';\n"
            . "  if (strpos(\$req, '/wp-content/uploads/') === false) return;\n"
            . "  \$path = parse_url(\$req, PHP_URL_PATH) ?: '';\n"
            . "  \$pos = strpos(\$path, '/wp-content/uploads/');\n"
            . "  if (\$pos === false) return;\n"
            . "  \$rel = substr(\$path, \$pos);\n"
            . "  \$local = WP_CONTENT_DIR . substr(\$rel, strlen('/wp-content'));\n"
            . "  if (file_exists(\$local)) return;\n"
            . "  header('Location: ' . " . var_export($source_origin, true) . " . \$rel, true, 302);\n"
            . "  exit;\n"
            . "}, 0);\n";
        file_put_contents('/wordpress/wp-content/mu-plugins/0-reprint-uploads-proxy.php', $mu);
    }

    return [
        'ok' => $sqlite_size > 0,
        'sqlite_size' => $sqlite_size,
        'source_origin' => $source_origin,
        'error' => $sqlite_size > 0 ? null : 'SQLite was not produced by the importer',
    ];
}

function _reprint_self_origin(): string {
    // Playground defines WP_HOME / WP_SITEURL early (via auto_prepend's
    // consts.json loader) to its full session URL — including the
    // /<random-slug>/ path segment that scopes each user's playground.
    // We need that prefix in --new-site-url so the SQL rewriter
    // points wp_options.siteurl/home at the URL the iframe is
    // actually serving, not just scheme://host. Without the prefix WP
    // canonical-redirects every request and the user gets a loop.
    if (defined('WP_HOME')) return rtrim((string) constant('WP_HOME'), '/');
    if (defined('WP_SITEURL')) return rtrim((string) constant('WP_SITEURL'), '/');
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$scheme://$host";
}
