<?php
// ============================================================
// Network Diagnostic — visit this directly:
// http://localhost/runningman_archive/admin/_network_diagnostic.php
//
// Tests raw curl connectivity to every external source the app
// depends on, and shows the REAL failure reason (DNS / SSL /
// timeout / refused) instead of a generic "could not reach".
// Delete this file once the issue is resolved — it's a debug tool.
// ============================================================
require_once __DIR__ . '/../config/db.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function testUrl(string $name, string $url): array {
    $start = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_VERBOSE        => true,
    ]);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $body   = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno  = curl_errno($ch);
    $errMsg = curl_error($ch);
    $ms     = round((microtime(true) - $start) * 1000);

    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    fclose($verbose);
    curl_close($ch);

    return [
        'name' => $name, 'url' => $url, 'http_code' => $code,
        'curl_errno' => $errno, 'curl_error' => $errMsg,
        'ms' => $ms, 'body_len' => $body ? strlen($body) : 0,
        'verbose' => $verboseLog,
    ];
}

$tests = [
    testUrl('Wikipedia API',    'https://en.wikipedia.org/w/api.php?action=query&format=json'),
    testUrl('myrm.tv',          'https://myrm.tv/'),
    testUrl('myrunningman.com', 'https://www.myrunningman.com/'),
    testUrl('Google (sanity check — if THIS fails too, it is a system-wide network issue)', 'https://www.google.com/'),
];

$curlVersion = curl_version();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Network Diagnostic</title>
<style>
body{font-family:'Consolas',monospace;background:#0a0e14;color:#e0e6ed;padding:2rem;font-size:.86rem;line-height:1.6}
h2{color:#FFD700}
.test{background:#141c2c;border:1px solid #2a3850;border-radius:8px;padding:1.2rem;margin-bottom:1rem}
.ok{color:#4ade80;font-weight:bold}
.fail{color:#f87171;font-weight:bold}
.label{color:#7dd3f0}
pre{background:#06090f;padding:.8rem;border-radius:6px;overflow-x:auto;font-size:.75rem;color:#94a3b8;max-height:200px}
table{width:100%;border-collapse:collapse;margin-top:1rem}
td{padding:.3rem .6rem;border-bottom:1px solid #2a3850}
</style></head><body>

<h2>🔌 Network Diagnostic — Running Man Archive</h2>
<p>curl version: <?= h($curlVersion['version']) ?> · SSL: <?= h($curlVersion['ssl_version'] ?? 'none') ?> · PHP: <?= phpversion() ?></p>

<?php foreach ($tests as $t): ?>
<div class="test">
  <strong><?= h($t['name']) ?></strong> — <span style="color:#5e7088"><?= h($t['url']) ?></span>
  <table>
    <tr><td class="label">Result</td><td>
      <?php if ($t['http_code'] >= 200 && $t['http_code'] < 400): ?>
        <span class="ok">✓ SUCCESS</span> (HTTP <?= $t['http_code'] ?>, <?= $t['ms'] ?>ms, <?= $t['body_len'] ?> bytes)
      <?php else: ?>
        <span class="fail">✗ FAILED</span>
      <?php endif; ?>
    </td></tr>
    <?php if ($t['curl_errno'] !== 0): ?>
    <tr><td class="label">curl errno</td><td><?= $t['curl_errno'] ?></td></tr>
    <tr><td class="label">curl error</td><td style="color:#f87171"><?= h($t['curl_error']) ?></td></tr>
    <?php endif; ?>
    <tr><td class="label">HTTP code</td><td><?= $t['http_code'] ?: '(none — connection never completed)' ?></td></tr>
    <tr><td class="label">Time</td><td><?= $t['ms'] ?>ms</td></tr>
  </table>
  <?php if ($t['curl_errno'] !== 0): ?>
  <details style="margin-top:.6rem">
    <summary style="cursor:pointer;color:#7dd3f0">Show verbose curl trace</summary>
    <pre><?= h($t['verbose']) ?></pre>
  </details>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="test">
  <strong>How to read this:</strong>
  <ul>
    <li><strong>curl errno 6</strong> (DNS) — your DNS server can't resolve the domain. Try changing DNS to 8.8.8.8 (Google) in Windows network settings.</li>
    <li><strong>curl errno 7</strong> (Connection refused) — a firewall or antivirus is actively blocking PHP/Apache from making outbound connections.</li>
    <li><strong>curl errno 28</strong> (Timeout) — request reached the network but got no response in time. Could be a slow/unstable connection.</li>
    <li><strong>curl errno 35/51/60</strong> (SSL) — TLS/certificate issue, often Windows-specific CA bundle problems.</li>
    <li><strong>If Google also fails</strong> — this is not specific to our app. Apache/PHP on this machine has no internet access at all (check Windows Firewall rules for <code>httpd.exe</code> / <code>php.exe</code>, or antivirus "web protection" blocking PHP's curl).</li>
    <li><strong>If only Wikipedia/myrm.tv fail but Google works</strong> — those specific domains may be blocked by ISP/network admin, or temporarily down.</li>
  </ul>
</div>
