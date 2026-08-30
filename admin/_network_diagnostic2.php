<?php
// ============================================================
// Network Diagnostic #2 — tests the EXACT calls rmGetLatestEpNumber()
// actually makes (not generic homepage pings). This will tell us
// definitively whether it's a real connectivity issue or something
// specific to these heavier requests (size, timeout, response format).
// ============================================================
require_once __DIR__ . '/../config/db.php';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function testReal(string $name, string $url, int $timeout): array {
    $start = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/json,*/*;q=0.8','Accept-Language: en-US,en;q=0.9'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => 'gzip, deflate',
    ]);
    $body   = curl_exec($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno  = curl_errno($ch);
    $errMsg = curl_error($ch);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    $ms = round((microtime(true) - $start) * 1000);

    return [
        'name' => $name, 'url' => $url, 'timeout_setting' => $timeout,
        'http_code' => $code, 'curl_errno' => $errno, 'curl_error' => $errMsg,
        'ms' => $ms, 'curl_total_time' => round($totalTime*1000),
        'body_len' => $body ? strlen($body) : 0,
        'body_preview' => $body ? substr($body, 0, 300) : null,
        'json_valid' => null,
    ];
}

// Test 1: the FIXED call — year-specific Wikipedia page (the real source of data)
$curYear = (int)date('Y');
$wikiUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
    'action'=>'parse','page'=>"List of Running Man episodes ($curYear)",
    'prop'=>'text','format'=>'json','disablelimitreport'=>1,'disableeditsection'=>1,
]);
$t1 = testReal("Wikipedia $curYear page (FIXED — year-specific, this is where real data lives)", $wikiUrl, 20);

// Test 1b: the OLD broken call, kept here to show why it was failing
$wikiUrlOld = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
    'action'=>'parse','page'=>'List_of_Running_Man_episodes',
    'prop'=>'text','format'=>'json','disablelimitreport'=>1,'disableeditsection'=>1,
]);
$t1b = testReal('Wikipedia hub page (OLD/BROKEN call — kept for comparison, expect tiny response)', $wikiUrlOld, 20);
// Re-fetch body separately to check JSON validity without holding huge string twice in display
$rawCheck = null;
if ($t1['http_code'] === 200) {
    $ch2 = curl_init($wikiUrl);
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $body2 = curl_exec($ch2);
    curl_close($ch2);
    $decoded = json_decode($body2, true);
    $t1['json_valid'] = $decoded !== null;
    $t1['has_parse_text'] = isset($decoded['parse']['text']['*']);
    $t1['html_length'] = isset($decoded['parse']['text']['*']) ? strlen($decoded['parse']['text']['*']) : 0;
}

// Test 2: the EXACT myrm.tv listing page rmGetLatestEpNumber() falls back to
$t2 = testReal('myrm.tv/episodes (real fallback call)', 'https://myrm.tv/episodes', 10);

// Test 3: a single myrm.tv episode page (used per-episode during sync)
$t3 = testReal('myrm.tv/ep/700 (single episode page, used during sync)', 'https://myrm.tv/ep/700', 12);

// Test 4: myrunningman.com single episode (used for synopsis). BUGFIX:
// was /episodes/700 — that's the index/pagination route, not a real
// episode page, on either domain.
$t4 = testReal('myrunningman.com/ep/700 (synopsis source)', 'https://www.myrunningman.com/ep/700', 12);

$tests = [$t1, $t1b, $t2, $t3, $t4];
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Real Call Diagnostic</title>
<style>
body{font-family:'Consolas',monospace;background:#0a0e14;color:#e0e6ed;padding:2rem;font-size:.85rem;line-height:1.6}
h2{color:#FFD700}
.test{background:#141c2c;border:1px solid #2a3850;border-radius:8px;padding:1.2rem;margin-bottom:1rem}
.ok{color:#4ade80;font-weight:bold}.fail{color:#f87171;font-weight:bold}
.label{color:#7dd3f0;width:160px;display:inline-block}
pre{background:#06090f;padding:.8rem;border-radius:6px;overflow-x:auto;font-size:.72rem;color:#94a3b8;max-height:150px;white-space:pre-wrap}
</style></head><body>
<h2>🔬 Real Call Diagnostic — exact requests the app makes</h2>
<p>This bypasses generic pings and calls the SAME endpoints with the SAME parameters the app uses, so we can see exactly where it breaks.</p>

<?php foreach ($tests as $t): ?>
<div class="test">
  <strong><?= h($t['name']) ?></strong><br><span style="color:#5e7088;font-size:.75rem"><?= h($t['url']) ?></span>
  <div style="margin-top:.7rem">
    <div><span class="label">Result</span>
      <?php if ($t['http_code']>=200 && $t['http_code']<400): ?>
        <span class="ok">✓ SUCCESS</span> HTTP <?= $t['http_code'] ?>, <?= $t['ms'] ?>ms, <?= number_format($t['body_len']) ?> bytes
      <?php else: ?>
        <span class="fail">✗ FAILED</span> after <?= $t['ms'] ?>ms (timeout setting was <?= $t['timeout_setting'] ?>s)
      <?php endif; ?>
    </div>
    <?php if ($t['curl_errno']!==0): ?>
    <div><span class="label">curl error</span><span style="color:#f87171">[<?= $t['curl_errno'] ?>] <?= h($t['curl_error']) ?></span></div>
    <?php endif; ?>
    <?php if (isset($t['json_valid'])): ?>
    <div><span class="label">Valid JSON?</span><?= $t['json_valid'] ? '<span class="ok">yes</span>' : '<span class="fail">NO — response was not valid JSON</span>' ?></div>
    <div><span class="label">Has parse.text.*?</span><?= ($t['has_parse_text']??false) ? '<span class="ok">yes ('.number_format($t['html_length']).' chars of HTML)</span>' : '<span class="fail">NO — unexpected response shape</span>' ?></div>
    <?php endif; ?>
  </div>
  <?php if ($t['body_preview'] && empty($t['json_valid'])): ?>
  <details style="margin-top:.6rem"><summary style="cursor:pointer;color:#7dd3f0">Show response preview (first 300 chars)</summary>
    <pre><?= h($t['body_preview']) ?></pre>
  </details>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="test">
  <strong>What this tells us:</strong>
  <ul>
    <li>If <strong>Test 1 (Wikipedia full parse) is slow but succeeds</strong> — the 25s timeout is fine, this was likely a transient blip.</li>
    <li>If <strong>Test 1 times out or fails</strong> while the basic Wikipedia ping worked earlier — the specific "parse this huge page" request is too heavy/slow for this connection, and we should raise the timeout further or switch to a lighter data source.</li>
    <li>If <strong>HTTP 200 but "Valid JSON? NO"</strong> — Wikipedia is returning something unexpected (rate-limit page, maintenance notice) instead of the actual API response.</li>
    <li>If <strong>Test 2/3/4 (myrm.tv, myrunningman.com) fail</strong> while Test 1 succeeds — those specific endpoints/paths have an issue distinct from general connectivity.</li>
  </ul>
</div>
</body></html>
