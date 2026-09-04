<?php
// ============================================================
// tests/ajax_contract.php — every admin AJAX endpoint must return
// valid JSON, on success AND on failure.
//
// The bug this exists to catch: a PHP warning printed before the
// body turns the response into
//     <br /><b>Warning</b>: ...{"ok":true}
// and the browser reports `Unexpected token '<'`. The endpoint
// "works" server-side, the UI just silently stops functioning. It is
// invisible in unit tests because it depends on php.ini's
// display_errors/html_errors, which differ between a dev box and
// XAMPP.
//
// So this drives the endpoints over real HTTP against a server
// started with display_errors=1 and html_errors=1 — the XAMPP
// default — and asserts the body parses as JSON.
//
//   php tests/ajax_contract.php [--base=http://127.0.0.1:8899]
//
// Needs a running web server and, for most endpoints, a database.
// Skips cleanly when the server is unreachable.
// ============================================================
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';

$base = 'http://127.0.0.1:8899';
foreach ($argv as $a) if (preg_match('/^--base=(.+)$/', $a, $m)) $base = rtrim($m[1], '/');

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

function fetchRaw(string $url, int $timeout = 90): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HEADER => true,
    ]);
    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'error' => $err, 'body' => '', 'status' => 0, 'type' => ''];
    $hdrSize = (int)($info['header_size'] ?? 0);
    return [
        'ok' => true, 'error' => null,
        'status' => (int)($info['http_code'] ?? 0),
        'type'   => (string)($info['content_type'] ?? ''),
        'body'   => substr($raw, $hdrSize),
    ];
}

// Reachability first — a dead server would fail every case identically.
$probe = fetchRaw($base . '/index.php', 10);
if (!$probe['ok'] || $probe['status'] !== 200) {
    echo "SKIP: no web server at $base (start: php -S 127.0.0.1:8899 -t .)\n";
    exit(0);
}

// Endpoints that are read-only or trivially safe to call repeatedly.
// Deliberately excludes anything that would scrape a range, rescan, or
// mutate episode data — those are driven from the control centre, not
// from a test.
$endpoints = [
    // scraper control centre
    'scraper.php?a=health'                      => 'source health (no probe)',
    'scraper.php?a=health&probe=1'              => 'source health (live probe)',
    'scraper.php?a=preview&mode=missing&limit=5'=> 'preview missing',
    'scraper.php?a=preview&mode=latest'         => 'preview latest',
    'scraper.php?a=preview&mode=failed'         => 'preview failed',
    'scraper.php?a=preview&mode=unstable'       => 'preview unstable',
    'scraper.php?a=preview&mode=thumbnails'     => 'preview thumbnails',
    'scraper.php?a=log&limit=5'                 => 'activity log',
    'scraper.php?a=selftest'                    => 'engine self-test',
    'scraper.php?a=detect'                      => 'latest-episode detection',
    'scraper.php?a=integrity'                   => 'integrity scan',
    'scraper.php?a=install'                     => 'install tables (idempotent)',
    'scraper.php?a=flush_cache'                 => 'flush cache',
    'scraper.php?a=episode&ep=813&dry=1&all=1'  => 'single episode DRY RUN',
    'scraper.php?a=verify_thumbs&limit=2'       => 'verify thumbnails',
    'scraper.php?a=enrich_guests&limit=2'       => 'enrich guests',
    'scraper.php?a=flag&id=999999&status=resolved' => 'resolve a nonexistent flag',
    // diagnostics
    'diagnostics.php?a=netcheck'                => 'network check',
    'diagnostics.php?a=srchealth'               => 'source health snapshot',
    'diagnostics.php?a=selftest'                => 'diagnostics self-test',
    'diagnostics.php?a=cachelist'               => 'cache listing',
    'diagnostics.php?a=eptrace&ep=813'          => 'episode trace',
    'diagnostics.php?a=wikitrace&year=2026'     => 'wikipedia year trace',
    'diagnostics.php?a=testurl&url=http%3A%2F%2F127.0.0.1%3A8899%2Findex.php' => 'test any URL',
    'diagnostics.php?a=mrminspect&ep=813'       => 'myrunningman inspect',
    // auto sync (read-only actions only)
    'auto_sync.php?a=lock_status'               => 'sync lock status',
    'auto_sync.php?a=progress&done=0&ep=0'      => 'sync progress ping',
    // health / fetch / thumbnails
    'health.php?a=check'                        => 'system health check',
    'fetch.php?a=check'                         => 'fetch: check for new',
    'thumbnails.php?a=reverify'                 => 'thumbnail re-verify',
];

// Error paths matter more than happy paths: this is where HTML leaks.
$errorCases = [
    'scraper.php?a=episode&ep=0'                => 'episode with an invalid number',
    'scraper.php?a=episode&ep=abc'              => 'episode with a non-numeric id',
    'scraper.php?a=preview&mode=nonsense'       => 'preview with an unknown mode',
    'scraper.php?a=flag&id=notanumber'          => 'flag with a non-numeric id',
    'scraper.php?a=log&limit=notanumber'        => 'log with a non-numeric limit',
    'diagnostics.php?a=eptrace&ep=0'            => 'trace with an invalid episode',
    'diagnostics.php?a=eptrace&ep=999999'       => 'trace far outside the valid range',
    'diagnostics.php?a=testurl&url=notaurl'     => 'test URL with a malformed URL',
    'diagnostics.php?a=wikitrace&year=0'        => 'wikitrace with an invalid year',
    'diagnostics.php?a=mrminspect&ep=0'         => 'inspect with an invalid episode',
    'fetch.php?a=scrape&ep=0'                   => 'fetch scrape with an invalid episode',
    'thumbnails.php?a=grab&ep=0'                => 'thumbnail grab with an invalid episode',
    'auto_sync.php?a=sync&ep=0&dry=1'           => 'auto-sync with an invalid episode',
];

function assertJson(string $label, string $base, string $path): void {
    $r = fetchRaw($base . '/admin/' . $path);
    if (!$r['ok']) { check("$label — reachable", $r['error'], null); return; }

    $body = $r['body'];
    // The specific failure being hunted: HTML before the JSON.
    $leadsWithHtml = (bool)preg_match('/^\s*(<br\s*\/?>|<b>|<!DOCTYPE|<html|<div)/i', $body);
    check("$label — no HTML before the body", $leadsWithHtml, false);

    $decoded = json_decode($body, true);
    $isJson  = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    check("$label — parses as JSON", $isJson, true);
    if (!$isJson) {
        $snippet = trim(preg_replace('/\s+/', ' ', substr($body, 0, 220)));
        echo "       body: $snippet\n";
        echo "       json_last_error: " . json_last_error_msg() . "\n";
        return;
    }
    check("$label — declares JSON content type", (bool)preg_match('~application/json~i', $r['type']), true);
    // Shape is the endpoint's business; what matters here is that the
    // body is JSON and free of leaked markup.
    check("$label — body is a JSON structure", is_array($decoded), true);
    // Stray output captured server-side must be reported, not swallowed.
    if (!empty($decoded['_stray_output'])) {
        echo "       NOTE stray server output was captured and reported: "
           . trim(preg_replace('/\s+/', ' ', substr((string)$decoded['_stray_output'], 0, 160))) . "\n";
    }
}

section('normal actions');
foreach ($endpoints as $path => $label) assertJson($label, $base, $path);

section('error and edge-case inputs');
foreach ($errorCases as $path => $label) assertJson($label, $base, $path);

section('the guard itself, against each way a handler can corrupt its body');
// Proves the fix is the fix: the same handler with the guard disabled
// reproduces the reported `Unexpected token '<', "<br /><b>"...`.
$modes = [
    'clean'   => ['ok' => true,  'diag' => null],
    'warning' => ['ok' => true,  'diag' => '_warnings'],      // notice before the body
    'stray'   => ['ok' => false, 'diag' => '_stray_output'],  // echo before the body
    'fatal'   => ['ok' => false, 'diag' => null],             // undefined function
    'throw'   => ['ok' => false, 'diag' => null],             // uncaught exception
    'both'    => ['ok' => true,  'diag' => '_warnings'],
    'via_out' => ['ok' => true,  'diag' => '_warnings'],
];
foreach ($modes as $mode => $want) {
    $r = fetchRaw($base . '/tests/fixtures/json_probe.php?mode=' . $mode, 20);
    $d = json_decode($r['body'], true);
    check("guard/$mode — response is valid JSON", json_last_error() === JSON_ERROR_NONE && is_array($d), true);
    if (!is_array($d)) { echo '       body: ' . substr($r['body'], 0, 160) . "\n"; continue; }
    check("guard/$mode — reports ok=" . var_export($want['ok'], true), $d['ok'] ?? null, $want['ok']);
    if ($want['diag'] !== null) {
        check("guard/$mode — names the problem in {$want['diag']}", !empty($d[$want['diag']]), true);
    }
}
foreach (['fatal', 'throw'] as $mode) {
    $r = fetchRaw($base . '/tests/fixtures/json_probe.php?mode=' . $mode, 20);
    $d = json_decode($r['body'], true);
    check("guard/$mode — flagged as fatal", !empty($d['fatal']), true);
    check("guard/$mode — names where it died", !empty($d['where']) || !empty($d['error']), true);
}

// And the control: without the guard the body really is corrupt, so
// this test would catch a regression that removed it.
foreach (['warning', 'stray'] as $mode) {
    $r = fetchRaw($base . '/tests/fixtures/json_probe.php?mode=' . $mode . '&guard=0', 20);
    $broken = json_decode($r['body'], true) === null;
    check("unguarded/$mode — genuinely corrupts the body (control)", $broken, true);
    check("unguarded/$mode — and it starts with HTML (the reported symptom)",
          (bool)preg_match('/^\s*<br\s*\/?>/i', $r['body']), true);
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'AJAX CONTRACT HELD' : 'AJAX CONTRACT VIOLATIONS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
