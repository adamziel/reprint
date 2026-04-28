<?php
/**
 * Reprint Web Wizard — full WordPress.com → Playground experience.
 *
 * 1. Sign in with WordPress.com (OAuth authorization-code flow).
 * 2. List the user's Atomic/WP.com sites.
 * 3. PHP side enables the reprint exporter + rotates the HMAC secret
 *    via the WP.com REST API and returns {api_url, secret}.
 * 4. Browser boots a WordPress Playground iframe, copies reprint.phar
 *    into it, and runs the importer FROM the Playground PHP runtime —
 *    so HTTP requests come from the user's browser (with normal
 *    User-Agent and direct routing) rather than from this server.
 *    Playground polls a progress file and renders status live.
 * 5. When the import completes, the Playground iframe IS the cloned
 *    site — no Blueprint or artifact staging needed.
 */

require __DIR__ . '/bootstrap.php';

reprint_session_start();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':       action_login(); exit;
    case 'logout':      action_logout(); exit;
    case 'sites':       action_sites(); exit;
    case 'me':          action_me(); exit;
    case 'provision':   action_provision(); exit;
    case 'status':      action_status(); exit;
}

render_wizard();

// ──────────────────────────────────────────────────────────────────────────
// OAuth + WP.com site listing
// ──────────────────────────────────────────────────────────────────────────

function action_login(): void {
    [$client_id, ] = reprint_client_credentials();
    if ($client_id === '') { http_response_code(500); echo 'WPCOM_CLIENT_ID not configured.'; return; }
    $state = bin2hex(random_bytes(16));
    // 10-minute TTL — long enough to complete the WP.com authorize
    // round-trip but bounded so a leaked nonce can't be reused later.
    reprint_cookie_set('rp_state', ['s' => $state], 600);
    $params = [
        'client_id' => $client_id,
        'redirect_uri' => reprint_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'global',
        'state' => $state,
    ];
    header('Location: https://public-api.wordpress.com/oauth2/authorize?' . http_build_query($params));
}

function action_logout(): void {
    reprint_cookie_clear('rp_tok');
    reprint_cookie_clear('rp_state');
    header('Location: /reprint.php');
}

function action_sites(): void {
    header('Content-Type: application/json');
    $token = reprint_wpcom_token();
    if ($token === '') { http_response_code(401); echo json_encode(['error' => 'not_authenticated']); return; }
    $sites = wpcom_get([
        'url' => 'https://public-api.wordpress.com/rest/v1.1/me/sites?filter=atomic,wpcom&fields=ID,URL,name,icon,is_wpcom_atomic',
        'token' => $token,
    ]);
    echo $sites;
}

function action_me(): void {
    header('Content-Type: application/json');
    $token = reprint_wpcom_token();
    if ($token === '') { http_response_code(401); echo json_encode(['error' => 'not_authenticated']); return; }
    echo wpcom_get([
        'url' => 'https://public-api.wordpress.com/rest/v1.1/me?fields=display_name,username,avatar_URL,email',
        'token' => $token,
    ]);
}

/** GET the given WP.com URL with a bearer token. Returns raw JSON string. */
function wpcom_get(array $opts): string {
    $ch = curl_init($opts['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $opts['token'],
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return json_encode(['error' => 'network']);
    if ($status >= 400) { http_response_code($status); }
    return (string) $body;
}

/**
 * $encoding: 'json' for endpoints that expect a JSON body (e.g. the
 * Jetpack REST bridge at /jetpack-blogs/<id>/rest-api), 'form' for the
 * classic WP.com REST endpoints (e.g. /sites/<id>/settings) — wpcom.js
 * defaults to form-encoded and those endpoints silently ignore JSON
 * bodies, dropping unknown keys with a 200 OK.
 */
function wpcom_post(string $url, string $token, array $body, string $encoding = 'json'): array {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($encoding === 'form') {
        $payload = http_build_query($body);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        $payload = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $resp, 'json' => json_decode((string) $resp, true)];
}

/**
 * Enables the reprint exporter on the chosen site (sliding 60-min window),
 * then rotates the HMAC secret. Returns [api_url, secret].
 *
 * Mirrors Studio's pull-reprint flow: enable → rotate → use.
 *
 * `$site_url` is passed by the client from the already-loaded site list
 * — avoids an extra `/sites/<id>?fields=URL` round-trip that the
 * current token scope doesn't always cover.
 */
function wpcom_provision_reprint(int $site_id, string $site_url, string $token): array {
    // Step 1: set reprint_exporter_enabled=<timestamp> via /sites/<id>/settings.
    // WP.com's settings endpoint whitelists keys and silently drops
    // unknown ones with a 200 OK, so we verify that the key actually
    // appears in the response's `updated` object — matching Studio's
    // enableReprintExporter behavior in yangon/apps/cli/lib/api.ts.
    $enabled = wpcom_post(
        "https://public-api.wordpress.com/rest/v1.1/sites/{$site_id}/settings",
        $token,
        ['reprint_exporter_enabled' => time()],
        'form'
    );
    if ($enabled['status'] >= 400) {
        throw new RuntimeException("enable-export-api failed (HTTP {$enabled['status']}): " . substr((string)$enabled['body'], 0, 300));
    }
    $updated = $enabled['json']['updated'] ?? null;
    if (!is_array($updated) || !array_key_exists('reprint_exporter_enabled', $updated)) {
        throw new RuntimeException(
            'The site did not acknowledge the reprint exporter activation. ' .
            'The feature may not be available yet on this WordPress.com site. ' .
            'Response: ' . substr((string) $enabled['body'], 0, 400)
        );
    }

    // Step 2: rotate the HMAC secret via the Jetpack bridge.
    $rotated = wpcom_post(
        "https://public-api.wordpress.com/rest/v1.1/jetpack-blogs/{$site_id}/rest-api?http_envelope=1",
        $token,
        ['path' => '/wpcomsh/v1/reprint/rotate-export-secret']
    );
    $env = $rotated['json'] ?? null;
    if (!is_array($env) || ($env['code'] ?? 0) !== 200) {
        throw new RuntimeException('rotate-export-secret failed: ' . substr((string)$rotated['body'], 0, 300));
    }
    // Jetpack bridge returns the inner response in `body`. WP.com sometimes
    // hands it back as an object, sometimes as a JSON-encoded string —
    // normalise both shapes.
    $inner = $env['body'] ?? null;
    if (is_string($inner)) {
        $inner = json_decode($inner, true);
    }
    $secret = is_array($inner) ? ($inner['data']['secret'] ?? null) : null;
    if (!$secret) {
        throw new RuntimeException('No secret in rotate response: ' . substr((string)$rotated['body'], 0, 400));
    }

    // Belt-and-suspenders: if the site also has the legacy
    // `reprint-exporter-wp` standalone plugin installed, that plugin
    // intercepts ?reprint-api at plugin-load time and reads its secret
    // from the `site_export_secret` option (NOT wpcomsh's
    // `reprint_exporter_secret`). Writing the rotated value to both
    // options means whichever handler fires first will accept us.
    // The settings endpoint silently drops keys it doesn't whitelist,
    // so this is a no-op on sites without the legacy plugin.
    $legacy_sync = wpcom_post(
        "https://public-api.wordpress.com/rest/v1.1/sites/{$site_id}/settings",
        $token,
        ['site_export_secret' => $secret],
        'form'
    );

    $debug = [
        'enable_updated' => $updated,
        'rotate_raw' => (string) $rotated['body'],
        'legacy_sync_status' => $legacy_sync['status'],
        'legacy_sync_updated' => $legacy_sync['json']['updated'] ?? null,
    ];
    return [rtrim($site_url, '/') . '/?reprint-api', $secret, $debug];
}

/**
 * (Unused now that the import runs inside Playground; kept here in case
 * a future debug action wants to verify HMAC server-side.) Pings
 * <site>/?reprint-api&endpoint=preflight using the same
 * Site_Export_HMAC_Client the phar bundles.
 *
 * Returns ['ok'=>bool, 'http'=>int, 'body'=>string, 'error'=>string|null].
 */
function reprint_phar_preflight_ping(string $api_url, string $secret, ?callable $emit = null): array {
    $phar = REPRINT_UI_DIR . '/reprint.phar';
    if (!file_exists($phar)) return ['ok'=>false,'http'=>0,'body'=>'','error'=>'reprint.phar missing'];

    if (!class_exists('Site_Export_HMAC_Client', false)) {
        if ($emit) $emit(['phase'=>'provision','status'=>'progress','message'=>'Loading HMAC client from phar…']);
        try {
            // loadPhar() registers the archive so subsequent phar://
            // URLs resolve. It does NOT execute the stub, so import.php
            // and its global side-effects stay dormant.
            \Phar::loadPhar($phar);
            // Inside the phar the exporter source lives under vendor/,
            // not packages/ — confirmed via Phar iterator at deploy time.
            $hmac_path = 'phar://' . $phar . '/vendor/wp-php-toolkit/reprint-exporter/src/class-hmac-client.php';
            require_once $hmac_path;
        } catch (\Throwable $e) {
            return ['ok'=>false,'http'=>0,'body'=>'','error'=>'phar load failed: '.$e->getMessage()];
        }
        if (!class_exists('Site_Export_HMAC_Client', false)) {
            return ['ok'=>false,'http'=>0,'body'=>'','error'=>'Site_Export_HMAC_Client not defined after require'];
        }
    }

    // Drop the legacy &site-export-api alias — wpcomsh's handler only
    // looks for ?reprint-api, and the alias can confuse $wp->request
    // parsing on some hosts (the empty-value param ends up looking like
    // a path component).
    $url = $api_url . '&endpoint=preflight&_cache_bust=' . time();
    if ($emit) $emit(['phase'=>'provision','status'=>'progress','message'=>'Pinging '.$url]);

    $client = new \Site_Export_HMAC_Client($secret);
    $headers = $client->get_curl_headers('');

    // wp.com's edge / WAF will serve the homepage HTML to unrecognized
    // User-Agents, even when the request is otherwise valid. Cycle
    // through the same UA list the phar uses so we don't get false
    // negatives just because Reprint/1.0 was the unlucky pick.
    $uas = [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
        'Reprint/1.0',
    ];
    $last_http = 0; $last_body = ''; $last_err = null;
    foreach ($uas as $ua) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);
        $body = curl_exec($ch);
        $last_http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $last_err = curl_error($ch) ?: null;
        curl_close($ch);

        if ($body === false) continue;
        $last_body = (string) $body;
        $json = json_decode($last_body, true);
        if (is_array($json)) {
            if ($last_http >= 400) {
                return ['ok'=>false,'http'=>$last_http,'body'=>$last_body,'error'=>(string)($json['error'] ?? 'HTTP error')];
            }
            return ['ok'=>true,'http'=>$last_http,'body'=>$last_body,'error'=>null];
        }
        // non-JSON → likely WAF/UA reject; try next UA
    }
    return ['ok'=>false,'http'=>$last_http,'body'=>$last_body,'error'=>$last_err ?? 'non-JSON response (WAF or wpcomsh not loaded)'];
}

// ──────────────────────────────────────────────────────────────────────────
// Import orchestration
// ──────────────────────────────────────────────────────────────────────────

/**
 * Server-side provisioning: enables the reprint exporter on the chosen
 * WP.com site and rotates the HMAC secret. Returns JSON with the
 * `api_url` (site ?reprint-api endpoint) and `secret` so the browser
 * can hand them to a Playground iframe that runs the actual import.
 *
 * No phar invocation, no streaming, no artifact staging — that all
 * happens client-side now, inside Playground, where browser-class
 * User-Agent and direct routing avoid the WAF/UA rejections we hit
 * when calling from this server.
 */
function action_provision(): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $token = reprint_wpcom_token();
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['error' => 'not_authenticated']);
        return;
    }

    $site_id = (int) ($_POST['site_id'] ?? 0);
    $site_url = (string) ($_POST['site_url'] ?? '');
    if ($site_id <= 0 || $site_url === '' || !preg_match('~^https?://[^\s]+$~i', $site_url)) {
        http_response_code(400);
        echo json_encode(['error' => 'site_id and a valid site_url are required']);
        return;
    }

    try {
        [$api_url, $secret, ] = wpcom_provision_reprint($site_id, $site_url, $token);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => 'provision_failed', 'message' => $e->getMessage()]);
        return;
    }

    echo json_encode([
        'api_url' => $api_url,
        'secret' => $secret,
        'site_url' => $site_url,
        'site_id' => $site_id,
    ]);
}

function action_status(): void {
    header('Content-Type: application/json');
    echo json_encode([
        'authenticated' => reprint_wpcom_token() !== '',
        'client_id' => reprint_client_credentials()[0],
    ]);
}

// ──────────────────────────────────────────────────────────────────────────
// UI
// ──────────────────────────────────────────────────────────────────────────

function render_wizard(): void {
    $authed = reprint_wpcom_token() !== '';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reprint · WordPress.com → Playground</title>
<style>
  :root {
    --bg:#0b0d12; --panel:#12151c; --panel-2:#181c26; --border:#242936;
    --fg:#e7ecf3; --muted:#8a94a7; --accent:#7c5cff; --accent-2:#4ad1c2;
    --ok:#3ecf8e; --err:#ff6b6b;
    --mono:ui-monospace,SFMono-Regular,Menlo,monospace;
  }
  *{box-sizing:border-box}
  html,body{margin:0;background:var(--bg);color:var(--fg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif}
  body{min-height:100vh;background:
    radial-gradient(800px 400px at 10% -10%, rgba(124,92,255,.18), transparent 60%),
    radial-gradient(600px 400px at 90% 0%, rgba(74,209,194,.12), transparent 60%),
    var(--bg);}
  .shell{max-width:880px;margin:0 auto;padding:48px 24px 96px}
  header.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}
  .logo{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent-2));box-shadow:0 4px 24px rgba(124,92,255,.35)}
  h1{font-size:22px;font-weight:600;letter-spacing:-0.01em;margin:0}
  .subtitle{color:var(--muted);font-size:14px;margin-top:2px}
  .stepper{display:flex;gap:8px;margin:24px 0 20px}
  .step{flex:1;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--panel);font-size:13px;color:var(--muted);display:flex;align-items:center;gap:10px}
  .step .num{width:22px;height:22px;border-radius:50%;background:var(--panel-2);display:grid;place-items:center;font-size:12px;color:var(--muted)}
  .step.active{border-color:var(--accent);color:var(--fg)}
  .step.active .num{background:var(--accent);color:#fff}
  .step.done .num{background:var(--ok);color:#0b0d12}
  .step.done{color:var(--fg)}
  .card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:28px;box-shadow:0 1px 0 rgba(255,255,255,.02) inset, 0 12px 32px rgba(0,0,0,.35)}
  .card h2{margin:0 0 6px;font-size:17px}
  .card p.desc{margin:0 0 20px;color:var(--muted);font-size:14px;line-height:1.5}
  button.primary{background:linear-gradient(135deg,var(--accent),#5a3fe0);color:#fff;border:0;padding:11px 18px;border-radius:9px;font-size:14px;font-weight:500;cursor:pointer;box-shadow:0 6px 18px rgba(124,92,255,.35);transition:transform .06s,box-shadow .15s}
  button.primary:hover{box-shadow:0 10px 24px rgba(124,92,255,.45)}
  button.primary:active{transform:translateY(1px)}
  button.primary:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
  button.ghost{background:transparent;color:var(--muted);border:1px solid var(--border);padding:10px 14px;border-radius:9px;cursor:pointer;font-size:13px}
  button.ghost:hover{color:var(--fg);border-color:var(--accent)}
  .actions{display:flex;gap:10px;align-items:center;margin-top:22px}
  .site-list{display:flex;flex-direction:column;gap:10px;margin-top:12px;max-height:380px;overflow-y:auto;padding-right:4px}
  input[type="search"]{width:100%;padding:11px 13px;font-size:14px;background:var(--panel-2);color:var(--fg);border:1px solid var(--border);border-radius:9px;font-family:inherit;transition:border-color .15s,box-shadow .15s}
  input[type="search"]:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,92,255,.22)}
  .site{display:grid;grid-template-columns:40px 1fr auto;gap:14px;align-items:center;padding:12px 14px;background:var(--panel-2);border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color .1s}
  .site:hover{border-color:var(--accent)}
  .site.selected{border-color:var(--accent);box-shadow:0 0 0 2px rgba(124,92,255,.2)}
  .site.disabled{opacity:.45;cursor:not-allowed;filter:grayscale(.6)}
  .site.disabled:hover{border-color:var(--border);box-shadow:none}
  .site .reason{font-size:11px;color:var(--muted);margin-top:3px;font-style:italic}
  .user-pill img.avatar{width:22px;height:22px;border-radius:50%;object-fit:cover}
  .site img,.site .site-icon{width:40px;height:40px;border-radius:8px;background:var(--panel);object-fit:cover;display:grid;place-items:center;color:var(--muted);font-size:14px;font-weight:600}
  .site .site-title{font-size:14px;font-weight:500}
  .site .site-url{font-size:12px;color:var(--muted);font-family:var(--mono);margin-top:2px}
  .badge{font-size:11px;padding:3px 8px;border-radius:999px;background:var(--panel);color:var(--muted);border:1px solid var(--border)}
  .progress-list{display:flex;flex-direction:column;gap:10px;margin-top:6px}
  .phase{display:grid;grid-template-columns:28px 1fr auto;gap:12px;align-items:center;padding:12px 14px;background:var(--panel-2);border:1px solid var(--border);border-radius:10px}
  .phase .dot{width:10px;height:10px;border-radius:50%;background:#35394a;justify-self:center}
  .phase.active .dot{background:var(--accent);box-shadow:0 0 12px var(--accent);animation:pulse 1.4s infinite}
  .phase.done .dot{background:var(--ok)}
  .phase.error .dot{background:var(--err)}
  .phase .name{font-size:14px}
  .phase .meta{font-size:12px;color:var(--muted);font-family:var(--mono)}
  @keyframes pulse{50%{opacity:.4}}
  @keyframes spin{to{transform:rotate(360deg)}}
  #site-refresh{display:inline-flex;align-items:center;color:var(--accent-2);opacity:.85}
  .bar{height:6px;background:var(--panel-2);border-radius:999px;overflow:hidden;margin-top:14px}
  .bar > div{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent-2));width:0%;transition:width .25s}
  .log{margin-top:20px;padding:14px;background:#07080c;border:1px solid var(--border);border-radius:10px;font-family:var(--mono);font-size:12px;color:#c7cddb;max-height:260px;overflow:auto;white-space:pre-wrap}
  .log .err{color:var(--err)}
  .log .info{color:var(--accent-2)}
  .hidden{display:none!important}
  .playground-frame{width:100%;height:640px;border:1px solid var(--border);border-radius:10px;background:#000;margin-top:14px}
  .user-pill{display:flex;align-items:center;gap:8px;margin-left:auto;font-size:12px;color:var(--muted)}
  .user-pill a{color:var(--accent-2);text-decoration:none}
  footer{text-align:center;color:var(--muted);font-size:12px;margin-top:40px}
</style>
</head>
<body>
<div class="shell">
  <header class="brand">
    <div class="logo"></div>
    <div>
      <h1>Reprint</h1>
      <div class="subtitle">Clone a WordPress.com site into Playground</div>
    </div>
    <?php if ($authed): ?>
      <div class="user-pill" id="user-pill"><span>Signed in to WordPress.com</span> · <a href="?action=logout">Not you?</a></div>
    <?php endif; ?>
  </header>

  <div class="stepper">
    <div class="step <?= $authed ? 'done' : 'active' ?>" data-step="1"><span class="num">1</span>Authorize</div>
    <div class="step <?= $authed ? 'active' : '' ?>" data-step="2"><span class="num">2</span>Pick a site</div>
    <div class="step" data-step="3"><span class="num">3</span>Clone into Playground</div>
  </div>

  <!-- STEP 1 — Connect WP.com -->
  <section class="card <?= $authed ? 'hidden' : '' ?>" id="card-1">
    <h2>Connect your WordPress.com account</h2>
    <p class="desc">We'll redirect you to WordPress.com to authorize Reprint.
      This lets us list your sites and temporarily enable the reprint exporter
      on the one you choose. Your access token is kept in an HTTP-only session
      cookie on this site only — nothing is written to a database, and it's
      gone the moment you close the tab or click "Sign out".</p>
    <div class="actions">
      <a href="?action=login"><button class="primary">Sign in with WordPress.com →</button></a>
    </div>
  </section>

  <!-- STEP 2 — Pick site -->
  <section class="card <?= $authed ? '' : 'hidden' ?>" id="card-2">
    <h2>Choose a site to clone</h2>
    <p class="desc">Atomic sites can be cloned with reprint. Simple WordPress.com sites are listed for reference but are dimmed and not selectable — they don't run wpcomsh's export endpoint. The exporter is enabled on the site you pick for a rolling 60-minute window.</p>
    <input type="search" id="site-filter" placeholder="Filter by name or URL…" autocomplete="off" class="hidden">
    <div class="site-list" id="site-list"><p class="desc" id="sites-loading">Loading your sites…</p></div>
    <p class="desc site-count hidden" id="site-count" style="margin:10px 0 0;display:flex;align-items:center;gap:8px">
      <span id="site-count-text"></span>
      <span id="site-refresh" class="hidden" title="Refreshing site list from WordPress.com…">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 0.9s linear infinite">
          <path d="M21 12a9 9 0 1 1-6.2-8.55"></path>
          <path d="M21 4v5h-5"></path>
        </svg>
      </span>
    </p>
    <div class="actions">
      <button class="primary" id="start-btn" disabled>Start import →</button>
      <span class="subtitle" id="start-hint"></span>
    </div>
    <script>
      // Synchronous cache hydrate. Placed AFTER all referenced
      // elements (site-list, site-filter, site-count, site-count-text)
      // so getElementById always succeeds. Renders minimal-HTML rows
      // before the bottom-of-body JS upgrades them to interactive ones.
      (function () {
        try {
          const key = 'reprint:sites:' + (window.__REPRINT_USER_ID__ || 'anon');
          const raw = localStorage.getItem(key);
          if (!raw) return;
          const parsed = JSON.parse(raw);
          const sites = parsed && parsed.sites;
          if (!Array.isArray(sites) || !sites.length) return;
          const sorted = sites.slice().sort((a, b) =>
            (b.is_wpcom_atomic ? 1 : 0) - (a.is_wpcom_atomic ? 1 : 0));
          const esc = (s) => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
          document.getElementById('site-list').innerHTML = sorted.map((s) => {
            const atomic = !!s.is_wpcom_atomic;
            const icon = s.icon && s.icon.img
              ? '<img src="' + esc(s.icon.img) + '" alt="">'
              : '<div class="site-icon">' + esc((s.name || '?')[0]) + '</div>';
            const reason = atomic ? ''
              : '<div class="reason">Reprint requires Atomic hosting — Simple WordPress.com sites can\'t be cloned.</div>';
            return '<div class="site ' + (atomic ? '' : 'disabled') + '" data-pending="1">'
              + icon
              + '<div>'
              +   '<div class="site-title">' + esc(s.name || s.URL || 'Untitled') + '</div>'
              +   '<div class="site-url">' + esc(s.URL) + '</div>'
              +   reason
              + '</div>'
              + '<span class="badge">' + (atomic ? 'Atomic' : 'Simple') + '</span>'
              + '</div>';
          }).join('');
          document.getElementById('site-filter').classList.remove('hidden');
          document.getElementById('site-count').classList.remove('hidden');
          document.getElementById('site-count-text').textContent = sites.length + ' sites';
          window.__REPRINT_CACHED_SITES__ = sites;
        } catch (e) { console.warn('reprint cache hydrate failed:', e); }
      })();
    </script>
  </section>

  <!-- STEP 3 — Provision (server-side WP.com calls) -->
  <section class="card hidden" id="card-3">
    <h2>Cloning into Playground…</h2>
    <p class="desc" id="playground-desc">Authorizing exporter, then booting Playground in your browser to run the import.</p>
    <div class="progress-list" id="phases">
      <div class="phase" data-phase="provision">   <div class="dot"></div><div class="name">Authorize exporter</div><div class="meta">—</div></div>
      <div class="phase" data-phase="preflight">   <div class="dot"></div><div class="name">Boot Playground</div>  <div class="meta">—</div></div>
      <div class="phase" data-phase="database">    <div class="dot"></div><div class="name">Database</div>        <div class="meta">—</div></div>
      <div class="phase" data-phase="files">       <div class="dot"></div><div class="name">Files</div>           <div class="meta">—</div></div>
      <div class="phase" data-phase="apply_runtime"><div class="dot"></div><div class="name">Finalize</div>         <div class="meta">—</div></div>
    </div>
    <div class="bar"><div id="overall-bar"></div></div>
    <div class="log" id="log"></div>
    <iframe id="playground-frame" class="playground-frame hidden"></iframe>
    <div id="error-banner" class="hidden" style="margin-top:16px;padding:14px 16px;background:rgba(255,107,107,.08);border:1px solid rgba(255,107,107,.4);border-radius:10px;color:#ffb0b0;font-size:13px;line-height:1.5"></div>
    <div class="actions">
      <button class="primary hidden" id="download-uploads">Download uploads from source site →</button>
      <button class="ghost hidden" id="retry-btn">← Pick a different site</button>
    </div>
  </section>

  <footer>Powered by <code>reprint.phar</code> · HMAC secrets rotate per import</footer>
</div>

<script>
const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);
const UI_BASE = <?= json_encode(REPRINT_UI_URL_BASE) ?>;

function setStep(n) {
  $$('.step').forEach(el => {
    const i = +el.dataset.step;
    el.classList.toggle('active', i === n);
    el.classList.toggle('done', i < n);
  });
  [1,2,3].forEach(i => $('#card-' + i).classList.toggle('hidden', i !== n));
}
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
function fmtBytes(n){if(n<1024)return n+' B';if(n<1048576)return (n/1024).toFixed(1)+' KB';if(n<1073741824)return (n/1048576).toFixed(1)+' MB';return (n/1073741824).toFixed(2)+' GB';}

let selectedSiteId = null;
let selectedSiteUrl = null;

<?php if ($authed): ?>
(async function loadMe() {
  try {
    const res = await fetch(UI_BASE + '?action=me');
    if (!res.ok) return;
    const me = await res.json();
    const pill = $('#user-pill');
    if (!pill || !me) return;
    const name = me.display_name || me.username || 'WordPress.com user';
    const avatar = me.avatar_URL ? `<img class="avatar" src="${me.avatar_URL}" alt="">` : '';
    pill.innerHTML = `${avatar}<span>Signed in as <strong>${name.replace(/</g,'&lt;')}</strong></span> · <a href="?action=logout">Not you?</a>`;
  } catch {}
})();
// Hoisted so the loadSites IIFE below isn't blocked by the TDZ.
var ALL_SITES = [];
function sitesFingerprint(sites) {
  return (sites || [])
    .map(s => `${s.ID}|${s.URL}|${s.name}|${s.is_wpcom_atomic ? 1 : 0}|${s.icon?.img || ''}`)
    .sort()
    .join('::');
}

(async function loadSites() {
  setStep(2);

  // The inline <script> next to #site-list already painted the cached
  // rows (or did nothing if no cache). Upgrade them to fully
  // interactive ones, then refetch in the background.
  const cacheKey = 'reprint:sites:' + (window.__REPRINT_USER_ID__ || 'anon');
  const cachedSites = window.__REPRINT_CACHED_SITES__;
  let renderedFingerprint = '';
  if (Array.isArray(cachedSites) && cachedSites.length) {
    renderSites(cachedSites);
    renderedFingerprint = sitesFingerprint(cachedSites);
    $('#site-refresh').classList.remove('hidden');
  }

  try {
    const res = await fetch(UI_BASE + '?action=sites');
    if (res.status === 401) { location.href = UI_BASE + '?action=login'; return; }
    const data = await res.json();
    const sites = Array.isArray(data) ? data : (data.sites || []);
    try { localStorage.setItem(cacheKey, JSON.stringify({ sites, ts: Date.now() })); } catch {}
    // Skip re-render when nothing meaningful changed — keeps the UI
    // stable across the background refresh, no "jump" when fresh
    // data arrives with the same sites.
    const liveFingerprint = sitesFingerprint(sites);
    if (liveFingerprint !== renderedFingerprint) {
      renderSites(sites);
    }
  } catch (e) {
    if (!cachedSites) {
      $('#site-list').innerHTML = '<p class="desc" style="color:var(--err)">Failed to load sites: ' + e.message + '</p>';
    }
    // If we had a cache, leave it on screen — better than wiping it.
  } finally {
    $('#site-refresh').classList.add('hidden');
  }
})();
<?php endif; ?>

// ALL_SITES is hoisted above the loadSites IIFE — declaring it again
// here would shadow the assignment.
function renderSites(sites) {
  // Atomic sites first (those are the importable ones), Simple sites
  // last so they don't crowd the picker. Within each group, keep the
  // server-given order (recently active first).
  ALL_SITES = (sites || []).slice().sort((a, b) => {
    const aA = a.is_wpcom_atomic ? 1 : 0;
    const bA = b.is_wpcom_atomic ? 1 : 0;
    return bA - aA;
  });
  const list = $('#site-list');
  const filter = $('#site-filter');
  const count = $('#site-count');
  if (!ALL_SITES.length) {
    list.innerHTML = '<p class="desc">No Atomic or WordPress.com sites found on this account.</p>';
    return;
  }
  filter.classList.remove('hidden');
  count.classList.remove('hidden');
  // Bind the input listener once. Refocusing on every render would
  // steal focus from the user mid-typing when the background refresh
  // returns, so only focus on the very first call.
  if (!filter.dataset.bound) {
    filter.addEventListener('input', applySiteFilter);
    filter.dataset.bound = '1';
    filter.focus();
  }
  applySiteFilter();
}

function applySiteFilter() {
  const q = ($('#site-filter').value || '').trim().toLowerCase();
  const filtered = q
    ? ALL_SITES.filter(s => (s.name || '').toLowerCase().includes(q) || (s.URL || '').toLowerCase().includes(q))
    : ALL_SITES;

  const list = $('#site-list');
  list.innerHTML = '';
  if (!filtered.length) {
    list.innerHTML = '<p class="desc">No sites match that filter.</p>';
  } else {
    filtered.forEach(s => list.appendChild(renderSiteRow(s)));
  }
  $('#site-count-text').textContent = q
    ? `${filtered.length} of ${ALL_SITES.length} sites`
    : `${ALL_SITES.length} sites`;

  // If the previously selected site got filtered out, clear selection.
  if (selectedSiteId && !filtered.some(s => s.ID === selectedSiteId)) {
    selectedSiteId = null;
    $('#start-btn').disabled = true;
    $('#start-hint').textContent = '';
  }
}

function renderSiteRow(s) {
  const el = document.createElement('div');
  el.className = 'site';
  const isAtomic = !!s.is_wpcom_atomic;
  if (!isAtomic) el.classList.add('disabled');
  if (s.ID === selectedSiteId) el.classList.add('selected');
  el.dataset.siteId = s.ID;
  const reason = isAtomic ? '' :
    `<div class="reason">Reprint requires Atomic hosting — Simple WordPress.com sites can't be cloned.</div>`;
  el.innerHTML = `
    ${s.icon && s.icon.img ? `<img src="${s.icon.img}" alt="">` : `<div class="site-icon">${(s.name||'?')[0]}</div>`}
    <div>
      <div class="site-title">${(s.name || s.URL || 'Untitled').replace(/</g,'&lt;')}</div>
      <div class="site-url">${(s.URL || '').replace(/</g,'&lt;')}</div>
      ${reason}
    </div>
    <span class="badge">${isAtomic ? 'Atomic' : 'Simple'}</span>`;
  if (!isAtomic) {
    el.title = 'Reprint requires Atomic hosting';
    return el;
  }
  el.addEventListener('click', () => {
    $$('.site').forEach(x => x.classList.remove('selected'));
    el.classList.add('selected');
    selectedSiteId = s.ID;
    selectedSiteUrl = s.URL;
    $('#start-btn').disabled = false;
    $('#start-hint').textContent = `Will clone ${s.URL}`;
  });
  return el;
}

$('#start-btn')?.addEventListener('click', async () => {
  if (!selectedSiteId) return;
  setStep(3);
  $('#log').innerHTML = '';
  ['provision','preflight','database','files','apply_runtime'].forEach(p => setPhase(p, '', '—'));
  $('#error-banner').classList.add('hidden');
  $('#retry-btn').classList.add('hidden');
  $('#overall-bar').style.background = '';
  $('#overall-bar').style.width = '0%';

  setPhase('provision', 'active', 'Enabling exporter & rotating secret…');
  logLine('Calling /sites/' + selectedSiteId + '/settings + rotate-export-secret on wp.com…');

  let provisioned;
  try {
    const res = await fetch(UI_BASE + '?action=provision', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'site_id=' + encodeURIComponent(selectedSiteId)
          + '&site_url=' + encodeURIComponent(selectedSiteUrl || ''),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'HTTP ' + res.status);
    }
    provisioned = await res.json();
  } catch (e) {
    setPhase('provision', 'error', e.message.slice(0, 80));
    showImportError('Provision failed: ' + e.message);
    return;
  }

  setPhase('provision', 'done', 'authorized');
  logLine('api_url=' + provisioned.api_url);
  logLine('secret length=' + provisioned.secret.length, 'info');

  // Hand off to Playground.
  await runImportInPlayground(provisioned);
});

/**
 * Boot a Playground iframe, drop reprint.phar into it, and run
 * `reprint.phar pull` from inside Playground. The HTTP requests then
 * originate from the user's browser (origin: playground.wordpress.net,
 * with browser User-Agent), which avoids the WAF/UA rejections we hit
 * when calling the source site from this PHP server.
 */
async function runImportInPlayground({ api_url, secret, site_url }) {
  setPhase('preflight', 'active', 'booting Playground…');
  $('#playground-desc').textContent = 'Booting WordPress Playground (~10s)…';
  const frame = $('#playground-frame');
  frame.classList.remove('hidden');

  let client;
  try {
    const mod = await import('https://playground.wordpress.net/client/index.js');
    client = await mod.startPlaygroundWeb({
      iframe: frame,
      remoteUrl: 'https://playground.wordpress.net/remote.html',
      blueprint: {
        landingPage: '/',
        preferredVersions: { php: '8.2', wp: 'latest' },
        features: { networking: true },
      },
    });
    await client.isReady();
  } catch (e) {
    setPhase('preflight', 'error', e.message.slice(0, 80));
    showImportError('Could not boot Playground: ' + e.message);
    return;
  }

  setPhase('preflight', 'done', 'Playground booted');
  setPhase('database', 'active', 'fetching reprint.phar…');
  $('#playground-desc').textContent = 'Importing ' + site_url + ' inside Playground…';

  // Push reprint.phar into Playground's filesystem.
  let pharBytes;
  try {
    const r = await fetch('/reprint.phar');
    if (!r.ok) throw new Error('HTTP ' + r.status);
    pharBytes = new Uint8Array(await r.arrayBuffer());
    await client.writeFile('/internal/shared/reprint.phar', pharBytes);
    logLine('reprint.phar uploaded to Playground (' + pharBytes.length + ' bytes)', 'info');
  } catch (e) {
    setPhase('database', 'error', 'phar upload failed');
    showImportError('Could not upload reprint.phar to Playground: ' + e.message);
    return;
  }

  // Reserve a SEPARATE docroot for the import, NOT /wordpress (which
  // already has the freshly-booted WP). The activation step at the
  // end wipes /wordpress and copies the imported tree over it, so
  // pull-time and Playground-default files never coexist.
  //
  // Recursive wipe of any leftover from a prior import in this same
  // Playground session — @rmdir only deletes empty dirs, so without
  // this the phar's preserve-local mode walks the prior 30k files
  // and "skips" each one (file_exists per file ≈ 6+ minutes in WASM).
  // Open tag split keeps PHP's tokenizer from treating the JS
  // template literal as a PHP open tag.
  setPhase('preflight', 'active', 'wiping previous import…');
  await client.run({ code: '<' + "?php\n" + `
    function _rrmdir($d, $keep_root = false) {
      if (!is_dir($d)) return;
      foreach (scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $d . '/' . $e;
        if (is_link($p) || is_file($p)) @unlink($p);
        elseif (is_dir($p)) _rrmdir($p);
      }
      if (!$keep_root) @rmdir($d);
    }

    // Stash Playground's SQLite integration before wiping /wordpress.
    // It's NOT just db.php — db.php is a tiny drop-in that requires
    // /wordpress/wp-content/plugins/sqlite-database-integration/load.php.
    // Without that plugin tree restored after the wipe, db.php would
    // fatal on the include and WP would fall back to MySQL with the
    // imported wp-config's Atomic credentials, which can't connect,
    // which shows the install wizard.
    function _rcopy_pre(string $src, string $dst): void {
      if (is_link($src)) {
        $t = @readlink($src);
        if ($t !== false) @symlink($t, $dst);
        return;
      }
      if (is_file($src)) { @copy($src, $dst); return; }
      if (is_dir($src)) {
        @mkdir($dst, 0777, true);
        foreach (scandir($src) ?: [] as $e) {
          if ($e === '.' || $e === '..') continue;
          _rcopy_pre($src . '/' . $e, $dst . '/' . $e);
        }
      }
    }
    _rrmdir('/tmp/saved-sqlite');
    @mkdir('/tmp/saved-sqlite', 0777, true);
    if (is_file('/wordpress/wp-content/db.php')) {
      @copy('/wordpress/wp-content/db.php', '/tmp/saved-sqlite/db.php');
    }
    if (is_dir('/wordpress/wp-content/plugins/sqlite-database-integration')) {
      _rcopy_pre(
        '/wordpress/wp-content/plugins/sqlite-database-integration',
        '/tmp/saved-sqlite/sqlite-database-integration'
      );
    }
    if (is_dir('/wordpress/wp-content/mu-plugins/sqlite-database-integration')) {
      _rcopy_pre(
        '/wordpress/wp-content/mu-plugins/sqlite-database-integration',
        '/tmp/saved-sqlite/mu-plugin-sqlite-database-integration'
      );
    }

    _rrmdir('/internal/shared/reprint-state');
    _rrmdir('/internal/shared/reprint-site');
    @unlink('/tmp/imported.sqlite');
    @mkdir('/internal/shared/reprint-state', 0777, true);
    @mkdir('/internal/shared/reprint-site',  0777, true);

    // Wipe /wordpress contents (keep the mount point itself). Pull's
    // flat-docroot stage will recreate it from the imported tree.
    _rrmdir('/wordpress', true);
  ` });

  // ─── Single long phar run with live streaming via post_message_to_js.
  // The phar emits NDJSON lines to STDOUT; an output-buffer callback
  // intercepts every chunk and forwards it to JS as a postMessage. JS
  // updates the UI live as bytes arrive. No chunking, no --max-exec —
  // the phar just runs to completion. ───
  const phpPullCode = '<' + "?php\n" + `
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    error_reporting(E_ERROR | E_PARSE);
    if (!defined('IMPORTER_WEB_ENTRY')) define('IMPORTER_WEB_ENTRY', true);
    if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'w'));
    if (!defined('STDERR')) define('STDERR', fopen('php://output', 'w'));
    if (!defined('STDIN'))  define('STDIN',  fopen('php://memory', 'r'));

    // Forward every output chunk to JS as it's produced. chunk_size=1
    // makes the callback fire on every fflush() call, so each NDJSON
    // line the phar emits crosses the worker→main boundary immediately.
    ob_start(function ($chunk) {
      if ($chunk !== '' && function_exists('post_message_to_js')) {
        post_message_to_js($chunk);
      }
      return ''; // swallow — we don't need it captured in the final response
    }, 1);

    // No --max-exec / no execution caps — let pull run to completion
    // in a single call. --no-adaptive disables the request-tuning
    // backoffs/pauses that don't help in the browser environment.
    $argv = [
      '/internal/shared/reprint.phar',
      'pull',
      ${JSON.stringify(api_url)},
      '--secret=' . ${JSON.stringify(secret)},
      '--state-dir=/internal/shared/reprint-state',
      '--fs-root=/internal/shared/reprint-site',
      '--target-engine=sqlite',
      // Write the SQLite DB to /tmp instead of straight into
      // /wordpress — activation wipes /wordpress before copying the
      // imported site over it, and we want the DB intact afterwards.
      '--target-sqlite-path=/tmp/imported.sqlite',
      '--new-site-url=https://playground.wordpress.net',
      // No preserve-local: we already nuked /internal/shared/reprint-site
      // before invoking the phar, so fs-root is provably empty.
      // preserve-local would otherwise stat 30k+ files at ~10ms each.
      '--on-fs-root-nonempty=error',
      // --no-adaptive turns off the request-budget tuner entirely;
      // when set, the phar omits max_execution_time / memory_threshold
      // from outgoing requests and the server uses its own defaults.
      '--no-adaptive',
      // Skip wp-content/uploads on the initial pull so the cloned
      // site is usable in seconds. The skipped paths get recorded in
      // .import-download-list-skipped.jsonl and can be fetched later
      // via files-pull --filter=skipped-earlier (the wizard exposes a
      // button for that). A mu-plugin installed during activation
      // 302-redirects missing /wp-content/uploads/* to the source
      // site so media still renders meanwhile.
      '--filter=essential-files',
      // The web Playground iframe is fundamentally different from the
      // playground-cli Node binary — no host-FS mounts, no runtime.php
      // hot-loading, no start.sh. The runtime= adapters (php-builtin,
      // playground-cli, nginx-fpm) all generate config that doesn't
      // apply here, so skip apply-runtime entirely. The wizard does
      // its own activation (symlinks + uploads-proxy mu-plugin) in JS
      // after the phar exits.
      '--runtime=none',
      // Flatten the split-root Atomic layout (docroot at /srv/htdocs,
      // ABSPATH at /wordpress/core/<ver>) directly into Playground's
      // document root at /wordpress. /wordpress was wiped before the
      // pull, so flat-docroot writes there cleanly. wp-admin and
      // wp-includes get symlinked into the imported core tree under
      // /internal/shared/reprint-site/, which stays alive for the
      // session.
      '--flatten-to=/wordpress',
    ];
    $argc = count($argv);
    @ob_implicit_flush(true);
    include '/internal/shared/reprint.phar';
  `;

  const readState = async () => {
    try { return JSON.parse(await client.readFileAsText('/internal/shared/reprint-state/.import-state.json')); }
    catch { return null; }
  };

  // Drains a stdout blob (NDJSON-ish) into the in-page log AND
  // returns the latest progress event we found in it (so the caller
  // can render live counts after each chunk).
  const streamLog = (text) => {
    const out = { lastFile: null, lastDb: null, lastApply: null, raw: [] };
    if (!text) return out;
    for (const line of text.split('\n')) {
      const t = line.trim();
      if (!t) continue;
      try {
        const ev = JSON.parse(t);
        out.raw.push(ev);
        if (ev.type === 'file_progress' || ev.type === 'file_downloaded' || (ev.heartbeat && ev.files_total)) {
          out.lastFile = ev;
        }
        if (ev.phase === 'sql' || ev.phase === 'db-index' || ev.type === 'db_row' || ev.bytes_received) {
          out.lastDb = ev;
        }
        if (ev.phase === 'db-apply' && (ev.statements_total || ev.statements_executed)) {
          out.lastApply = ev;
        }
        if (ev.message) logLine(ev.message, ev.status === 'error' ? 'err' : (ev.status === 'complete' ? 'info' : ''));
      } catch {
        if (t.startsWith('#!')) continue; // shebang line from phar
        logLine(t);
      }
    }
    return out;
  };

  /**
   * Run a sub-command repeatedly until it reports completion.
   *
   * The phar's files-pull / db-pull use a `--max-exec=N` budget: each
   * invocation runs at most N seconds, persists cursor state, exits
   * with code 2 if there's more work, code 0 when done. We loop and
   * read the state file between iterations, which is when the worker
   * is free and `readFileAsText` can actually return live counts.
   *
   * `renderFromState(state)` is called after every iteration so the
   * UI reflects the latest state-file values.
   */
  const fmtBytes2 = (n) => {
    if (!n) return '';
    if (n < 1024) return n + 'B';
    if (n < 1024*1024) return (n/1024).toFixed(1) + 'KB';
    if (n < 1024*1024*1024) return (n/1024/1024).toFixed(1) + 'MB';
    return (n/1024/1024/1024).toFixed(2) + 'GB';
  };
  const truncate = (s, n) => s && s.length > n ? '…' + s.slice(-n) : (s || '');

  // ─── Live message handler: every chunk the phar writes to stdout
  // arrives here as a separate string. Parse NDJSON lines and update
  // the matching phase row in real time. ───
  let msgBuffer = '';
  const onChunkText = (chunk) => {
    if (typeof chunk !== 'string') {
      try { chunk = String(chunk); } catch { return; }
    }
    msgBuffer += chunk;
    let nl;
    while ((nl = msgBuffer.indexOf('\n')) !== -1) {
      const line = msgBuffer.slice(0, nl).trim();
      msgBuffer = msgBuffer.slice(nl + 1);
      if (!line) continue;
      let ev;
      try { ev = JSON.parse(line); }
      catch { if (!line.startsWith('#!')) logLine(line); continue; }
      handleStreamEvent(ev);
    }
  };

  // Subscribe to messages from the phar (one per output chunk).
  client.onMessage(onChunkText);

  setPhase('preflight', 'active', 'starting…');
  setPhase('database', '', '—');
  setPhase('files', '', '—');

  // Aggregate counters for high-volume events so the log doesn't get
  // flooded. We update a single sticky line per kind instead of
  // appending one new line per event.
  let symlinkCount = 0, skipCount = 0;
  let symlinkLogEl = null, skipLogEl = null;
  let lastFilePath = '';

  const updateOrInsertCounter = (refSlot, label, count) => {
    let el = refSlot();
    if (!el) {
      el = document.createElement('div');
      $('#log').appendChild(el);
      $('#log').scrollTop = $('#log').scrollHeight;
    }
    el.textContent = label.replace('{n}', count.toLocaleString());
    return el;
  };

  function handleStreamEvent(ev) {
    // Coalesce noisy event streams into a single in-place counter line.
    if (ev.type === 'symlink_follow') {
      symlinkCount++;
      symlinkLogEl = updateOrInsertCounter(() => symlinkLogEl, 'Following {n} symlink targets', symlinkCount);
      return;
    }
    if (ev.type === 'skip') {
      skipCount++;
      skipLogEl = updateOrInsertCounter(() => skipLogEl, 'Skipping {n} files (preserve-local)', skipCount);
      // Reflect in the files row meta as well
      const cur = document.querySelector('[data-phase="files"] .meta')?.textContent || '';
      if (!cur.includes('skipped')) {
        // Append a non-blinking suffix
        document.querySelector('[data-phase="files"] .meta').textContent = cur + ' · ' + skipCount + ' skipped';
      } else {
        document.querySelector('[data-phase="files"] .meta').textContent =
          cur.replace(/\d+ skipped/, skipCount + ' skipped');
      }
      return;
    }

    // Phase rows already show progress for these — don't double-print.
    const renderedAsRow =
      ev.type === 'lifecycle' ||
      ev.type === 'file_progress' ||
      ev.type === 'file_downloaded' ||
      ev.heartbeat ||
      ev.phase === 'db-apply' ||
      ev.phase === 'db-index' ||
      ev.phase === 'sql';
    const shouldLog = ev.message && (
      ev.status === 'error' ||
      ev.status === 'complete' ||
      !renderedAsRow
    );
    if (shouldLog) logLine(ev.message, ev.status === 'error' ? 'err' : (ev.status === 'complete' ? 'info' : ''));

    // ── lifecycle: mark phase started/complete ──
    if (ev.type === 'lifecycle') {
      const c = ev.command;
      if (c === 'preflight' && ev.event === 'starting') setPhase('preflight', 'active', 'probing…');
      if (c === 'preflight' && ev.event === 'complete') setPhase('preflight', 'done', 'ok');
      if (c === 'files-pull' && ev.event === 'starting') setPhase('files', 'active', 'starting…');
      if (c === 'files-pull' && ev.event === 'complete') {
        setPhase('files', 'done', (ev.files_indexed ?? 0).toLocaleString() + ' files');
        $('#overall-bar').style.width = '85%';
      }
      if (c === 'db-pull' && ev.event === 'starting') setPhase('database', 'active', 'pulling SQL…');
      if (c === 'db-pull' && ev.event === 'complete') $('#overall-bar').style.width = '92%';
      if (c === 'db-apply' && ev.event === 'starting') setPhase('database', 'active', 'applying SQL…');
      if (c === 'db-apply' && ev.event === 'complete') {
        setPhase('database', 'done', 'imported');
        $('#overall-bar').style.width = '97%';
      }
    }

    // ── files: live counts ──
    if (ev.type === 'file_progress' || (ev.heartbeat && ev.files_total != null)) {
      const done = ev.files_done ?? 0;
      const total = ev.files_total ?? 0;
      const pct = total ? Math.round((done / total) * 100) : 0;
      // Sticky last filename — keep the previous one when the current
      // event doesn't carry a path (e.g. heartbeats), so the meta
      // doesn't flicker between "with file" and "without file".
      if (ev.path) lastFilePath = ev.path;
      const last = lastFilePath ? ' · ' + truncate(lastFilePath, 36) : '';
      const skipSuffix = skipCount ? ' · ' + skipCount.toLocaleString() + ' skipped' : '';
      setPhase('files', 'active',
        total ? `${done.toLocaleString()} / ${total.toLocaleString()} (${pct}%)${last}${skipSuffix}`
              : `${done.toLocaleString()} files${skipSuffix}`);
      // Files-pull is the bulk of the work, so weight it heavily on
      // the overall bar: 5% (preflight) → 85% (files done).
      if (total) $('#overall-bar').style.width = (5 + pct * 0.80) + '%';
    }

    // ── database pull progress ──
    if (ev.phase === 'db-index' && ev.status === 'complete' && ev.tables_processed != null) {
      setPhase('database', 'active', ev.tables_processed + ' tables fetched');
    }
    if (ev.phase === 'sql' && ev.status === 'starting') {
      setPhase('database', 'active', 'downloading SQL dump…');
    }
    if (ev.phase === 'sql' && ev.status === 'complete' && ev.batches_processed != null) {
      setPhase('database', 'active', ev.batches_processed + ' batches downloaded');
      $('#overall-bar').style.width = '70%';
    }

    // ── database apply progress ──
    if (ev.phase === 'db-apply') {
      const done = ev.statements_executed ?? 0;
      const total = ev.statements_total ?? 0;
      if (total) {
        const pct = Math.round((done / total) * 100);
        setPhase('database', 'active', `${done.toLocaleString()} / ${total.toLocaleString()} statements (${pct}%)`);
      } else if (done) {
        setPhase('database', 'active', `${done.toLocaleString()} statements`);
      }
    }

    // ── apply-runtime / final ──
    if (ev.command === 'apply-runtime' && ev.status === 'complete') {
      setPhase('database', 'done', 'imported');
    }

    if (ev.status === 'error') {
      const failed = ev.failed_stage || ev.phase || ev.command;
      const phaseId = failed === 'preflight' ? 'preflight'
                    : failed === 'files-pull' ? 'files'
                    : 'database';
      setPhase(phaseId, 'error', (ev.error || ev.message || 'failed').slice(0, 80));
    }
  }

  // ─── Mark preflight active and run the whole pull as one call. ───
  setPhase('preflight', 'active', 'starting…');
  setPhase('files', 'active', 'queued');
  setPhase('database', 'active', 'queued');
  logLine('→ reprint pull (single run, streaming)', 'info');

  let runResult, runError = null;
  try { runResult = await client.run({ code: phpPullCode }); }
  catch (e) { runError = e; runResult = e?.result || {}; }

  // Drain any tail of the buffer.
  if (msgBuffer.trim()) {
    try { handleStreamEvent(JSON.parse(msgBuffer.trim())); } catch {}
    msgBuffer = '';
  }

  if (runResult.errors) logLine('STDERR: ' + runResult.errors, 'err');
  if (runError)         logLine('JS error: ' + runError.message, 'err');

  const exitCode = runResult.exitCode ?? (runError ? -1 : 0);
  if (exitCode !== 0) {
    showImportError('reprint.phar exited with code ' + exitCode + '. See log above.');
    return;
  }

  const state = await readState();
  if (!state || state.status !== 'complete') {
    showImportError(
      state ? 'Import stopped at ' + (state.command || 'unknown') + '.' : 'Import did not produce a state file.'
    );
    return;
  }

  // ─── Activation: symlink wp-content/* from the imported docroot
  // into /wordpress/wp-content so the booted Playground sees the
  // imported plugins/themes/uploads. db-apply already wrote the SQL
  // straight into /wordpress/wp-content/database/.ht.sqlite so the
  // database side is live without further work.
  //
  // Also installs a mu-plugin that 302-redirects missing
  // /wp-content/uploads/* to the source site, since uploads were
  // skipped on the initial pull. ───
  setPhase('apply_runtime', 'active', 'mounting wp-content…');
  const sourceOrigin = new URL(site_url).origin;
  const proxyMuPlugin = '<' + "?php\n" + `
    /**
     * Reprint uploads proxy — redirects missing /wp-content/uploads/*
     * requests to the source site, so media keeps rendering until
     * the uploads are downloaded locally.
     */
    add_action('init', function () {
      $req = $_SERVER['REQUEST_URI'] ?? '';
      if (strpos($req, '/wp-content/uploads/') === false) return;
      $path = parse_url($req, PHP_URL_PATH) ?: '';
      $marker = '/wp-content/uploads/';
      $pos = strpos($path, $marker);
      if ($pos === false) return;
      $rel = substr($path, $pos);
      $local = WP_CONTENT_DIR . substr($rel, strlen('/wp-content'));
      if (file_exists($local)) return;
      header('Location: ${sourceOrigin}' . $rel, true, 302);
      exit;
    }, 0);
  `;
  const activatePhp = '<' + "?php\n" + `
    // Pull's flat-docroot stage wrote the imported site straight into
    // /wordpress (we wiped it before the pull and passed
    // --flatten-to=/wordpress). Activation is now just three small
    // wiring steps: SQLite drop-in, SQLite database file, mu-plugin.
    if (!is_dir('/wordpress/wp-content')) {
      echo json_encode(['error' => '/wordpress/wp-content missing — flatten failed?']);
      exit(1);
    }

    // 1. Restore Playground's SQLite integration. db.php is the thin
    //    drop-in stub; sqlite-database-integration/ is the actual
    //    plugin code db.php requires. Both were stashed in /tmp
    //    before /wordpress got wiped.
    function _rcopy_act(string $src, string $dst): void {
      if (is_link($src)) {
        $t = @readlink($src);
        if ($t !== false) { @unlink($dst); @symlink($t, $dst); }
        return;
      }
      if (is_file($src)) { @copy($src, $dst); return; }
      if (is_dir($src)) {
        @mkdir($dst, 0777, true);
        foreach (scandir($src) ?: [] as $e) {
          if ($e === '.' || $e === '..') continue;
          _rcopy_act($src . '/' . $e, $dst . '/' . $e);
        }
      }
    }
    if (is_file('/tmp/saved-sqlite/db.php')) {
      @copy('/tmp/saved-sqlite/db.php', '/wordpress/wp-content/db.php');
    }
    if (is_dir('/tmp/saved-sqlite/sqlite-database-integration')) {
      @mkdir('/wordpress/wp-content/plugins', 0777, true);
      _rcopy_act(
        '/tmp/saved-sqlite/sqlite-database-integration',
        '/wordpress/wp-content/plugins/sqlite-database-integration'
      );
    }
    if (is_dir('/tmp/saved-sqlite/mu-plugin-sqlite-database-integration')) {
      @mkdir('/wordpress/wp-content/mu-plugins', 0777, true);
      _rcopy_act(
        '/tmp/saved-sqlite/mu-plugin-sqlite-database-integration',
        '/wordpress/wp-content/mu-plugins/sqlite-database-integration'
      );
    }

    // 2. Move the SQLite database the phar populated into place.
    @mkdir('/wordpress/wp-content/database', 0777, true);
    if (file_exists('/tmp/imported.sqlite')) {
      if (!@rename('/tmp/imported.sqlite', '/wordpress/wp-content/database/.ht.sqlite')) {
        @copy('/tmp/imported.sqlite', '/wordpress/wp-content/database/.ht.sqlite');
        @unlink('/tmp/imported.sqlite');
      }
    }

    // 3. Drop the uploads-proxy mu-plugin. Base64-encoded so the
    //    $_SERVER / $req / $rel references in its source survive
    //    PHP's outer double-quoted string parsing.
    @mkdir('/wordpress/wp-content/mu-plugins', 0777, true);
    file_put_contents(
      '/wordpress/wp-content/mu-plugins/0-reprint-uploads-proxy.php',
      base64_decode(${JSON.stringify(btoa(unescape(encodeURIComponent(proxyMuPlugin))))})
    );

    echo json_encode(['ok' => true]) . "\n";
  `;
  let actResult;
  try { actResult = await client.run({ code: activatePhp }); } catch (e) { actResult = { text: '', errors: e?.message || String(e), exitCode: -1 }; }
  if (actResult.exitCode !== 0) {
    setPhase('apply_runtime', 'error', 'activation failed');
    logLine('Activation stdout: ' + (actResult.text || '(empty)'), 'err');
    logLine('Activation stderr: ' + (actResult.errors || '(empty)'), 'err');
    showImportError('Could not mount imported wp-content into Playground.');
    return;
  }
  logLine(actResult.text, 'info');

  setPhase('apply_runtime', 'done', 'ok');
  $('#overall-bar').style.width = '100%';
  $('#playground-desc').textContent = 'Cloned site is live below. Uploads stream from the source site until you download them locally.';

  // Show the "Download uploads" button now that the site is live.
  // It runs files-pull --filter=skipped-earlier inside Playground,
  // pulling in the uploads we deferred during the initial import.
  const dl = $('#download-uploads');
  if (dl) {
    dl.classList.remove('hidden');
    dl.onclick = async () => {
      dl.disabled = true;
      dl.textContent = 'Downloading uploads…';
      setPhase('apply_runtime', 'active', 'downloading uploads…');
      const uploadsCode = '<' + "?php\n" + `
        @ini_set('display_errors', '0');
        if (!defined('IMPORTER_WEB_ENTRY')) define('IMPORTER_WEB_ENTRY', true);
        if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'w'));
        if (!defined('STDERR')) define('STDERR', fopen('php://output', 'w'));
        if (!defined('STDIN'))  define('STDIN',  fopen('php://memory', 'r'));
        ob_start(function ($c) {
          if ($c !== '' && function_exists('post_message_to_js')) post_message_to_js($c);
          return '';
        }, 1);
        $argv = [
          '/internal/shared/reprint.phar',
          'files-pull',
          ${JSON.stringify(api_url)},
          '--secret=' . ${JSON.stringify(secret)},
          '--state-dir=/internal/shared/reprint-state',
          '--fs-root=/internal/shared/reprint-site',
          // fs-root has the essential-files import; preserve-local is
          // OK here because skipped-earlier only walks the (much
          // smaller) skip list, not the full 30k+ tree.
          '--on-fs-root-nonempty=preserve-local',
          '--no-adaptive',
          '--filter=skipped-earlier',
        ];
        $argc = count($argv);
        @ob_implicit_flush(true);
        include '/internal/shared/reprint.phar';
      `;
      // Reset the live counters so the file row tracks the new run.
      lastFilePath = '';
      try { await client.run({ code: uploadsCode }); }
      catch (e) { logLine('uploads pull failed: ' + e.message, 'err'); }
      setPhase('apply_runtime', 'done', 'uploads downloaded');
      dl.textContent = 'Uploads downloaded ✓';
      dl.disabled = true;
      // Reload the iframe so WP picks up the now-local images.
      try { await client.goTo('/'); } catch {}
    };
  }

  // Navigate the iframe to the cloned site's home page.
  try {
    await client.goTo('/');
  } catch {}
}

function showImportError(msg) {
  const banner = $('#error-banner');
  banner.textContent = msg;
  banner.classList.remove('hidden');
  $('#retry-btn').classList.remove('hidden');
}

$('#retry-btn')?.addEventListener('click', () => {
  $('#error-banner').classList.add('hidden');
  $('#retry-btn').classList.add('hidden');
  $('#overall-bar').style.width = '0%';
  $('#overall-bar').style.background = '';
  ['provision','preflight','database','files','apply_runtime'].forEach(p => setPhase(p, '', '—'));
  setStep(2);
});

</script>
</body>
</html>
    <?php
}
