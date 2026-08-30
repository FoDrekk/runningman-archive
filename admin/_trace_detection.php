<?php
// ============================================================
// Trace Detection — calls the REAL rmGetLatestEpNumber() function
// step-by-step, dumping internal state at every stage. This uses
// the EXACT SAME code path cron.php uses — no parallel/separate
// test logic — so whatever it shows IS what's actually happening.
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';

// ── Optional: clear cache files first, in case a stale/bad cache
// from an earlier broken attempt is masking the real behaviour ──
$cleared = [];
if (isset($_GET['clearcache'])) {
    $thisYear = (int)date('Y');
    foreach ([$thisYear, $thisYear-1] as $y) {
        foreach (["rm_wiki_page_$y.html", "rm_wiki_episodes_$y.json"] as $fn) {
            $p = sys_get_temp_dir().'/'.$fn;
            if (file_exists($p)) { unlink($p); $cleared[] = $p; }
        }
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Trace Detection</title>
<style>
body{font-family:'Consolas',monospace;background:#0a0e14;color:#e0e6ed;padding:2rem;font-size:.85rem;line-height:1.7}
h2{color:#FFD700}
.step{background:#141c2c;border:1px solid #2a3850;border-radius:8px;padding:1.2rem;margin-bottom:1rem}
.ok{color:#4ade80;font-weight:bold}.fail{color:#f87171;font-weight:bold}.warn{color:#fbbf24}
.label{color:#7dd3f0}
pre{background:#06090f;padding:.8rem;border-radius:6px;overflow-x:auto;font-size:.72rem;color:#94a3b8;max-height:250px;white-space:pre-wrap}
a.btn{display:inline-block;background:#29ABE2;color:#fff;padding:.5rem 1rem;border-radius:6px;text-decoration:none;margin-top:.5rem}
</style></head><body>
<h2>🔍 Trace: rmGetLatestEpNumber() — real execution, step by step</h2>

<div class="step">
  <strong>File identity check</strong> — confirms PHP is actually loading the file you think it is.
  <table style="margin-top:.5rem">
    <tr><td class="label" style="width:220px">scraper.php path</td><td><?= h(realpath(__DIR__.'/../includes/scraper.php')) ?></td></tr>
    <tr><td class="label">Last modified</td><td><?= h(date('Y-m-d H:i:s', filemtime(__DIR__.'/../includes/scraper.php'))) ?></td></tr>
    <tr><td class="label">Has rmEpisodeExistsOnMRM?</td><td><?= function_exists('rmEpisodeExistsOnMRM') ? '<span class="ok">yes — latest version loaded</span>' : '<span class="fail">NO — OLD FILE IS LOADED, your fix did not take effect</span>' ?></td></tr>
    <tr><td class="label">opcache enabled?</td><td><?= (function_exists('opcache_get_status') && opcache_get_status() !== false) ? '<span class="warn">yes — stale bytecode is possible after file replace</span>' : 'no / unavailable' ?></td></tr>
    <tr><td class="label">PHP temp dir</td><td><?= h(sys_get_temp_dir()) ?></td></tr>
  </table>
</div>

<?php if (!function_exists('rmEpisodeExistsOnMRM')): ?>
<div class="step" style="border-color:#ef4444">
  <strong class="fail">STOP — the old scraper.php is still being loaded.</strong><br><br>
  This means the file on disk at the path above either wasn't actually replaced, or PHP's opcache
  is serving a cached compiled version from before the replacement. The rest of this trace will
  use OLD broken logic, so its results below don't reflect the real fix.<br><br>
  <strong>Fix:</strong>
  <ol>
    <li>Re-copy <code>includes/scraper.php</code> to the exact path shown above</li>
    <li>In XAMPP Control Panel: <strong>Stop</strong> Apache, wait 3 seconds, <strong>Start</strong> Apache again</li>
    <li>Reload this page</li>
  </ol>
</div>
<?php else: ?>

<?php if ($cleared): ?>
<div class="step"><strong class="ok">Cache cleared:</strong><br><?php foreach($cleared as $c) echo h($c).'<br>'; ?></div>
<?php else: ?>
<div class="step"><a class="btn" href="?clearcache=1">🗑️ Clear Wikipedia cache files and re-test</a> (use this if results below look stale)</div>
<?php endif; ?>

<?php
$thisYear = (int)date('Y');
$dbMax = (int)getDB()->query("SELECT MAX(episode_number) FROM episodes")->fetchColumn();
?>
<div class="step">
  <strong>Step 1 — DB current max</strong><br>
  Database max episode: <strong style="color:#FFD700">EP<?= $dbMax ?></strong> · Current year: <strong><?= $thisYear ?></strong>
</div>

<?php
$t0 = microtime(true);
$html2026 = rmWikiFetchYearPage($thisYear);
$t1 = microtime(true);
?>
<div class="step">
  <strong>Step 2 — rmWikiFetchYearPage(<?= $thisYear ?>)</strong> — the actual function call cron.php depends on
  <table style="margin-top:.5rem">
    <tr><td class="label" style="width:220px">Result</td><td><?= $html2026 ? '<span class="ok">✓ got HTML — '.number_format(strlen($html2026)).' chars</span>' : '<span class="fail">✗ returned null</span>' ?></td></tr>
    <tr><td class="label">Time taken</td><td><?= round(($t1-$t0)*1000) ?>ms</td></tr>
    <?php if (!$html2026 && function_exists('rmLastFetchError')): ?>
    <tr><td class="label">Last fetch error</td><td class="fail"><?= h(rmLastFetchError() ?: '(none captured)') ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<?php
$t2 = microtime(true);
$parsed2026 = rmWikiParseYear($thisYear);
$t3 = microtime(true);
?>
<div class="step">
  <strong>Step 3 — rmWikiParseYear(<?= $thisYear ?>)</strong> — parses the HTML into episode rows
  <table style="margin-top:.5rem">
    <tr><td class="label" style="width:220px">Episodes found</td><td><?= count($parsed2026) ?></td></tr>
    <tr><td class="label">Max episode number</td><td><?= $parsed2026 ? max(array_keys($parsed2026)) : '(none)' ?></td></tr>
    <tr><td class="label">Time taken</td><td><?= round(($t3-$t2)*1000) ?>ms</td></tr>
  </table>
  <?php if ($parsed2026): $top = array_slice($parsed2026, -3, 3, true); ?>
  <details><summary style="cursor:pointer;color:#7dd3f0">Show last 3 parsed episodes</summary>
    <pre><?= h(json_encode($top, JSON_PRETTY_PRINT)) ?></pre>
  </details>
  <?php endif; ?>
</div>

<?php
// Direct call, completely bypassing rmWikiParseYear's caching wrapper —
// isolates whether the bug is in rmWikiParseHtml() itself or in the
// cache/wrapper logic around it. Debug flag ON so the function itself
// echoes its internal step-by-step trace — zero risk of any mismatch
// between "what the debug code does" and "what the real code does",
// since this literally runs the real code with tracing switched on.
ob_start();
$GLOBALS['__rm_debug_parse'] = true;
$t2b = microtime(true);
$parsedDirect = rmWikiParseHtml($html2026);
$t3b = microtime(true);
$GLOBALS['__rm_debug_parse'] = false;
$internalTrace = ob_get_clean();
?>
<div class="step" style="border-color:<?= count($parsedDirect)>0 ? '#22c55e' : '#ef4444' ?>">
  <strong>Step 3-direct — rmWikiParseHtml($html2026) called DIRECTLY, no cache wrapper at all</strong>
  <table style="margin-top:.5rem">
    <tr><td class="label" style="width:280px">Episodes found (direct call)</td><td><?= count($parsedDirect) ?></td></tr>
    <tr><td class="label">Time taken</td><td><?= round(($t3b-$t2b)*1000) ?>ms</td></tr>
  </table>
  <?php if ($internalTrace): ?>
  <p style="margin-top:.6rem;color:#a855f7">Internal step-by-step trace from inside the real function:</p>
  <pre style="max-height:400px"><?= h($internalTrace) ?></pre>
  <?php endif; ?>
  <p style="margin-top:.6rem">
    <?php if (count($parsedDirect) > 0 && count($parsed2026) === 0): ?>
      <span class="ok">→ Direct call WORKS, wrapped call (Step 3) does NOT.</span>
      The bug is in <code>rmWikiParseYear()</code>'s caching/wrapper logic, not in the parser itself.
    <?php elseif (count($parsedDirect) === 0): ?>
      <span class="fail">→ Direct call ALSO returns 0.</span>
      The bug is genuinely inside <code>rmWikiParseHtml()</code> itself — not caching, not the wrapper.
    <?php else: ?>
      <span class="ok">→ Both return the same non-zero result. No discrepancy.</span>
    <?php endif; ?>
  </p>
</div>

<?php if (count($parsed2026) === 0 && $html2026): ?>
<div class="step" style="border-color:#a855f7">
  <strong style="color:#a855f7">Step 3a — DOMDocument micro-trace</strong><br>
  Bypassing the full function — running the exact same DOMDocument/XPath
  calls inline, one at a time, so we see precisely which step returns
  nothing instead of guessing again.
  <?php
    $dbgDom = new DOMDocument();
    libxml_use_internal_errors(true);
    $loadOk = $dbgDom->loadHTML('<?xml encoding="utf-8" ?>' . $html2026);
    $libxmlErrors = libxml_get_errors();
    libxml_clear_errors();

    $dbgXpath = new DOMXPath($dbgDom);
    $dbgTables = $dbgXpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' wikitable ')]");

    // Also try the simplest possible XPath as a sanity check —
    // ANY table at all, no class filtering.
    $anyTables = $dbgXpath->query("//table");
  ?>
  <table style="margin-top:.5rem">
    <tr><td class="label" style="width:280px">$dom->loadHTML() return value</td>
        <td><?= $loadOk ? '<span class="ok">true</span>' : '<span class="fail">FALSE — parsing failed entirely</span>' ?></td></tr>
    <tr><td class="label">libxml errors captured</td><td><?= count($libxmlErrors) ?></td></tr>
    <tr><td class="label">XPath "//table" (no filter)</td><td><?= $anyTables === false ? '<span class="fail">query failed</span>' : $anyTables->length ?></td></tr>
    <tr><td class="label">XPath "//table[wikitable class]"</td><td><?= $dbgTables === false ? '<span class="fail">query failed</span>' : $dbgTables->length ?></td></tr>
  </table>

  <?php if ($libxmlErrors): ?>
  <p style="margin-top:.6rem;color:#f87171">First 5 libxml parse errors/warnings:</p>
  <pre><?php foreach (array_slice($libxmlErrors,0,5) as $e) echo h(trim($e->message))." (line {$e->line})\n"; ?></pre>
  <?php endif; ?>

  <?php if ($anyTables && $anyTables->length > 0): ?>
    <p style="margin-top:.6rem;color:#7dd3f0">Class attribute of each &lt;table&gt; DOMDocument actually sees:</p>
    <pre><?php foreach ($anyTables as $idx => $t) { echo '['.$idx.'] class="'.h($t->getAttribute('class')).'"'."\n"; if ($idx>=9) { echo "...\n"; break; } } ?></pre>
  <?php endif; ?>

  <?php if ($dbgTables && $dbgTables->length > 0):
    $t0 = $dbgTables->item(0);
    $rowsDirect = $dbgXpath->query("./tr", $t0);
    $rowsViaBody = $dbgXpath->query("./tbody/tr", $t0);
    $rowsAny = $dbgXpath->query(".//tr", $t0);
  ?>
  <p style="margin-top:.6rem;color:#7dd3f0">Row-finding on the first matched wikitable (3 different XPath strategies):</p>
  <table>
    <tr><td class="label" style="width:280px">./tr (direct children)</td><td><?= $rowsDirect->length ?></td></tr>
    <tr><td class="label">./tbody/tr (inside explicit tbody)</td><td><?= $rowsViaBody->length ?></td></tr>
    <tr><td class="label">.//tr (any descendant — includes nested)</td><td><?= $rowsAny->length ?></td></tr>
  </table>
  <?php
    $useRows = $rowsDirect->length > 0 ? $rowsDirect : ($rowsViaBody->length > 0 ? $rowsViaBody : $rowsAny);
    if ($useRows->length > 0) {
        $hRow = $useRows->item(0);
        $thDirect = $dbgXpath->query("./th", $hRow);
        $thAny    = $dbgXpath->query(".//th", $hRow);
  ?>
  <p style="margin-top:.6rem;color:#7dd3f0">Header cell (&lt;th&gt;) count on first row found:</p>
  <table>
    <tr><td class="label" style="width:280px">./th (direct)</td><td><?= $thDirect->length ?></td></tr>
    <tr><td class="label">.//th (any descendant)</td><td><?= $thAny->length ?></td></tr>
  </table>
  <?php if ($thAny->length > 0): ?>
  <p style="margin-top:.6rem;color:#7dd3f0">Text content of each header cell DOMDocument actually extracted:</p>
  <pre><?php foreach ($thAny as $idx => $th) echo '['.$idx.'] "'.h(trim(preg_replace('/\s+/',' ',$th->textContent))).'"'."\n"; ?></pre>
  <?php endif; ?>
  <?php } ?>
  <?php endif; ?>
</div>

<div class="step" style="border-color:#f59e0b">
  <strong class="warn">Step 3b — Raw HTML structure dump (since parsing found 0 episodes)</strong><br>
  Stop guessing at the regex — this shows EXACTLY what's in the real HTML so the fix can target the actual structure.
  <?php
    $rawHtml = $html2026;
    preg_match_all('/<table[^>]*>/i', $rawHtml, $allTables);
    preg_match_all('/<table[^>]*class="[^"]*wikitable[^"]*"[^>]*>/i', $rawHtml, $dqTables);   // double-quote class
    preg_match_all("/<table[^>]*class='[^']*wikitable[^']*'[^>]*>/i", $rawHtml, $sqTables);   // single-quote class
    preg_match_all('/class="[^"]*"/i', $rawHtml, $allClasses);
    $wikitableClassHits = array_values(array_unique(array_filter($allClasses[0], fn($c)=>stripos($c,'wikitable')!==false)));
  ?>
  <table style="margin-top:.5rem">
    <tr><td class="label" style="width:260px">Total &lt;table&gt; tags found</td><td><?= count($allTables[0]) ?></td></tr>
    <tr><td class="label">Tables matching wikitable (double-quote)</td><td><?= count($dqTables[0]) ?></td></tr>
    <tr><td class="label">Tables matching wikitable (single-quote)</td><td><?= count($sqTables[0]) ?></td></tr>
    <tr><td class="label">Distinct class="..." values containing "wikitable"</td><td><?= count($wikitableClassHits) ?></td></tr>
  </table>

  <?php if ($wikitableClassHits): ?>
  <p style="margin-top:.6rem;color:#7dd3f0">Actual wikitable class attribute(s) found:</p>
  <pre><?= h(implode("\n", $wikitableClassHits)) ?></pre>
  <?php endif; ?>

  <?php if ($allTables[0]): ?>
  <p style="margin-top:.6rem;color:#7dd3f0">First 3 &lt;table&gt; opening tags (raw, exact text):</p>
  <pre><?= h(implode("\n\n", array_slice($allTables[0], 0, 3))) ?></pre>
  <?php endif; ?>

  <?php
    // If we found at least one wikitable by either quote style, show its header row raw content
    $sample = $dqTables[0][0] ?? ($sqTables[0][0] ?? null);
    if ($sample) {
        $startPos = strpos($rawHtml, $sample);
        $chunk = substr($rawHtml, $startPos, 3000);
        preg_match('/<tr[^>]*>(.*?)<\/tr>/is', $chunk, $firstRow);
    }
  ?>
  <?php if (!empty($firstRow[1])): ?>
  <p style="margin-top:.6rem;color:#7dd3f0">First &lt;tr&gt; content of the first wikitable found (raw HTML, this is the header row):</p>
  <pre><?= h($firstRow[1]) ?></pre>
  <?php elseif ($wikitableClassHits || $dqTables[0] || $sqTables[0]): ?>
  <p style="margin-top:.6rem;color:#f87171">A wikitable was found but no &lt;tr&gt; could be extracted from the first 3000 chars after it — table may be larger/nested than expected.</p>
  <?php else: ?>
  <p style="margin-top:.6rem;color:#f87171">No wikitable found by either quote style. The episode data on this page may use a completely different table class, or may not be in a &lt;table&gt; at all (e.g. a list, or transcluded from a template that the API parse didn't expand).</p>
  <?php
    // Last-ditch: show a 1500-char slice from where "806" or "807" literally appears in the raw HTML,
    // since episode numbers as plain text should be findable even if table structure assumptions are wrong.
    $needle = (string)$dbMax;
    $pos = strpos($rawHtml, $needle);
    if ($pos !== false) {
        echo '<p style="margin-top:.6rem;color:#7dd3f0">Raw HTML context around the literal text "'.h($needle).'" (searching for where episode numbers actually live):</p>';
        echo '<pre>'.h(substr($rawHtml, max(0,$pos-300), 800)).'</pre>';
    }
  ?>
  <?php endif; ?>
</div>
<?php endif; ?>


<?php
$wikiMax = $parsed2026 ? max(array_keys($parsed2026)) : 0;
if ($wikiMax >= 1):
    $probeResults = [];
    for ($n = $wikiMax + 1; $n <= $wikiMax + 5; $n++) {
        $t4 = microtime(true);
        $exists = rmEpisodeExistsOnMRM($n);
        $probeResults[] = ['ep'=>$n,'exists'=>$exists,'ms'=>round((microtime(true)-$t4)*1000)];
        if (!$exists) break;
    }
?>
<div class="step">
  <strong>Step 4 — Forward-probe past Wikipedia's max (EP<?= $wikiMax ?>) via myrunningman.com</strong>
  <table style="margin-top:.5rem">
    <tr><th class="label">Episode</th><th class="label">Exists?</th><th class="label">Time</th></tr>
    <?php foreach ($probeResults as $p): ?>
    <tr>
      <td>EP<?= $p['ep'] ?></td>
      <td><?= $p['exists'] ? '<span class="ok">✓ yes</span>' : '<span class="fail">✗ no</span>' ?></td>
      <td><?= $p['ms'] ?>ms</td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php
$t5 = microtime(true);
$finalResult = rmGetLatestEpNumber();
$t6 = microtime(true);
?>
<div class="step" style="border-color:<?= $finalResult ? '#22c55e' : '#ef4444' ?>">
  <strong>Step 5 — rmGetLatestEpNumber() — FINAL RESULT (this is exactly what cron.php gets)</strong>
  <table style="margin-top:.5rem">
    <tr><td class="label" style="width:220px">Returned value</td><td><?= $finalResult ? '<span class="ok">EP'.$finalResult.'</span>' : '<span class="fail">NULL</span>' ?></td></tr>
    <tr><td class="label">DB currently at</td><td>EP<?= $dbMax ?></td></tr>
    <tr><td class="label">Would import</td><td><?= ($finalResult && $finalResult > $dbMax) ? '<span class="ok">EP'.($dbMax+1).' through EP'.min($finalResult,$dbMax+5).'</span>' : 'nothing — already up to date or detection failed' ?></td></tr>
    <tr><td class="label">Time taken (this call)</td><td><?= round(($t6-$t5)*1000) ?>ms</td></tr>
  </table>
</div>

<?php endif; ?>
</body></html>
