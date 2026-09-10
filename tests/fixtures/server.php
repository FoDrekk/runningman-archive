<?php
// ============================================================
// tests/fixtures/server.php — a deliberately badly-behaved HTTP
// server, used to exercise the failure paths that fixtures alone
// cannot reach: real status codes, real timeouts, real redirect
// loops, real truncated responses.
//
//   php -S 127.0.0.1:8901 tests/fixtures/server.php
//
// Query parameters:
//   code=NNN     respond with this HTTP status
//   body=NAME    one of: ok | empty | nav | cloudflare | html | badjson
//                | json | truncated | huge | tiny_image | html_image
//                | real_image | zero
//   sleep=N      stall N seconds before responding (timeout tests)
//   redirect=N   bounce through N redirects (loop tests when large)
//   type=MIME    override Content-Type
//   retry_after=N  send a Retry-After header
// ============================================================

$q     = $_GET;
$code  = isset($q['code']) ? (int)$q['code'] : 200;
$sleep = isset($q['sleep']) ? (float)$q['sleep'] : 0;
$body  = $q['body'] ?? 'ok';

if (isset($q['redirect'])) {
    $n = (int)$q['redirect'];
    header('Location: /?redirect=' . ($n + 1) . '&body=' . urlencode($body), true, 302);
    exit;
}

if ($sleep > 0) usleep((int)($sleep * 1_000_000));

// A counter so a test can assert how many times the client actually
// retried, rather than inferring it from timing.
$counterFile = sys_get_temp_dir() . '/rm_test_hits_' . preg_replace('/\W/', '', (string)($q['tag'] ?? 'default'));
@file_put_contents($counterFile, ((int)@file_get_contents($counterFile)) + 1);

$payloads = [
    'ok'         => '<html><head><meta property="og:title" content="Episode #810 - Fixture Title">'
                  . '<meta property="og:description" content="A perfectly ordinary episode description that is long enough to pass validation checks.">'
                  . '</head><body><div>Location: Seoul</div><div>Broadcast Date: 2026-08-23</div></body></html>',
    'empty'      => '',
    'zero'       => '',
    'nav'        => '<html><body><nav>Home | Episodes | Guests | Sign in</nav></body></html>',
    'cloudflare' => '<html><head><title>Just a moment...</title></head><body>'
                  . '<div class="cf-browser-verification">Checking your browser before accessing…</div></body></html>',
    'html'       => '<html><body><h1>Not JSON at all</h1><p>This is an error page served with a 200.</p></body></html>',
    'badjson'    => '{"parse": {"text": {"*": "unterminated',
    'json'       => '{"parse":{"text":{"*":"<table class=\"wikitable\"><tr><th>Ep.</th></tr></table>"}}}',
    'truncated'  => '<html><head><meta property="og:title" content="Episode #810 - Trunc',
    'huge'       => str_repeat('<div>menu item</div>', 40000),
    'tiny_image' => base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), // 1x1 gif
    'html_image' => '<!DOCTYPE html><html><body>404 Not Found</body></html>',
];

$out = $payloads[$body] ?? $payloads['ok'];

if ($body === 'real_image') {
    // A genuine 640x360 JPEG, big enough to pass every dimension check.
    $im = imagecreatetruecolor(640, 360);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 160));
    imagestring($im, 5, 40, 170, 'RM FIXTURE', imagecolorallocate($im, 255, 255, 255));
    ob_start(); imagejpeg($im, null, 90); $out = ob_get_clean(); imagedestroy($im);
    header('Content-Type: image/jpeg');
} elseif ($body === 'small_image') {
    // PR14: a genuine, decodable image right at the bare minimum
    // dimensions (200x112) — passes RmValidator::imageBytes() (byte
    // validity is not in question) but scores poorly in
    // RmThumbnailEngine::scoreCandidate() against the 1280x720 target,
    // for testing the QUALITY threshold rather than raw validity. A
    // gradient, not a solid fill: a solid colour JPEG-compresses down to
    // a couple hundred bytes and would be rejected as "too small to be a
    // real thumbnail" before ever reaching the quality score this
    // fixture exists to test.
    $im = imagecreatetruecolor(200, 112);
    for ($x = 0; $x < 200; $x++) {
        imagefilledrectangle($im, $x, 0, $x, 111, imagecolorallocate($im, $x % 256, (2 * $x) % 256, (3 * $x) % 256));
    }
    ob_start(); imagejpeg($im, null, 90); $out = ob_get_clean(); imagedestroy($im);
    header('Content-Type: image/jpeg');
} elseif (in_array($body, ['tiny_image', 'html_image'], true)) {
    header('Content-Type: image/jpeg');   // lying on purpose
} elseif (in_array($body, ['json', 'badjson'], true)) {
    header('Content-Type: application/json');
} else {
    header('Content-Type: text/html; charset=utf-8');
}

if (!empty($q['type'])) header('Content-Type: ' . $q['type']);
if (!empty($q['retry_after'])) header('Retry-After: ' . (int)$q['retry_after']);

// Pad short bodies so they clear min_bytes checks where the test wants
// the PARSER to reject the content rather than the transport.
if (!empty($q['pad']) && strlen($out) < (int)$q['pad']) {
    $out .= str_repeat(' ', (int)$q['pad'] - strlen($out));
}

http_response_code($code);
echo $out;
