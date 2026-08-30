<?php
// ============================================================
// Running Man Archive — Batch Synopsis Fetcher
// Source: myrunningman.com (has real per-episode synopses)
// Place: C:\xampp\htdocs\runningman_archive\batch_synopsis.php
// Run:   http://localhost/runningman_archive/batch_synopsis.php
// ============================================================
define('BASE_PATH', '');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/scraper.php'; // reuse rmFetch + real Wikipedia lookup (rmWikiEpisode/rmYear)
set_time_limit(0);
ini_set('max_execution_time', 0);

$isCLI = PHP_SAPI === 'cli';

function fetchUrl(string $url, int $timeout = 12): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,*/*', 'Accept-Language: en-US,en;q=0.9'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => 'gzip, deflate',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : null;
}

// Generic/useless description patterns to reject
function isGenericSynopsis(string $text): bool {
    return (bool) preg_match('/
        stream\s+and\s+watch |
        watch\s+running\s+man |
        watch\s+and\s+stream |
        episode\s+list |
        myrunningman\.com |
        myrm\.tv |
        ^running\s+man\s+episode\s*#?\d+\s*$ |
        ^watch\s+episode |
        ^stream\s+episode
    /ix', $text);
}

// ── Source 1: myrunningman.com (best — real synopses) ────────
function synopsisFromMyRM(int $n): ?string {
    // BUGFIX: was "/episodes/$n" — that's the paginated episode INDEX
    // route on this site, not a per-episode page. Both myrunningman.com
    // and myrm.tv (same backend, two domain aliases) serve individual
    // episodes at /ep/{n}. Confirmed live: /episodes/300 returns
    // "Episodes - Page 300" (just a nav list, no synopsis/location/tags);
    // /ep/300 returns the real episode page.
    $html = fetchUrl("https://www.myrunningman.com/ep/$n", 12);
    if (!$html || strlen($html) < 500) return null;

    // og:description has real synopsis on myrunningman.com
    foreach ([
        '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']{20,})["\']/',
        '/<meta[^>]+content=["\']([^"\']{20,})["\'][^>]+property=["\']og:description["\']/',
        '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']{20,})["\']/',
    ] as $p) {
        if (preg_match($p, $html, $m)) {
            $syn = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!isGenericSynopsis($syn) && mb_strlen($syn) > 20) return $syn;
        }
    }

    // Fallback: look for description text block
    if (preg_match('/[Dd]escription["\s:>]+([^<]{30,300})/s', $html, $m)) {
        $syn = trim(strip_tags($m[1]));
        if (!isGenericSynopsis($syn) && mb_strlen($syn) > 20) return $syn;
    }
    return null;
}

// ── Source 2: Wikipedia (year-specific list page, via scraper.php) ──
// BUGFIX: this used to check a flat cache file "rm_wiki_episodes.json"
// that nothing in the whole codebase ever writes — scraper.php's real
// Wikipedia cache is always PER YEAR: "rm_wiki_episodes_$year.json"
// (see rmWikiParseYear() in includes/scraper.php). So this fallback
// was silently dead code; every single call returned null no matter
// what. Now it calls rmWikiEpisode() directly, which resolves the
// correct year via rmYear($n) and fetches+parses+caches on demand if
// no cache exists yet — so it actually works as a real fallback.
function synopsisFromWiki(int $n): ?string {
    $ep = rmWikiEpisode($n);
    return !empty($ep['synopsis']) ? $ep['synopsis'] : null;
}

// ── Main: try myrunningman first, wiki fallback ───────────────
function getSynopsis(int $n): ?string {
    // Source 1: myrunningman.com
    $syn = synopsisFromMyRM($n);
    if ($syn) return $syn;
    // Source 2: Wikipedia cached data
    $syn = synopsisFromWiki($n);
    if ($syn) return $syn;
    return null;
}

// ── Get range from URL/CLI ────────────────────────────────────
$startFrom = (int)($_GET['from'] ?? (isset($argv[1]) ? $argv[1] : 1));
$limitTo   = (int)($_GET['to']   ?? (isset($argv[2]) ? $argv[2] : 806));
$force     = isset($_GET['force']); // force re-fetch even if synopsis exists

$db = getDB();
$where = $force
    ? "episode_number BETWEEN ? AND ?"
    : "(synopsis IS NULL OR synopsis = '') AND episode_number BETWEEN ? AND ?";

$stmt = $db->prepare("SELECT episode_number, title FROM episodes WHERE $where ORDER BY episode_number ASC");
$stmt->execute([$startFrom, $limitTo]);
$toSync = $stmt->fetchAll();
$total  = count($toSync);
$done   = 0; $updated = 0; $failed = 0;

// ── Browser output setup ──────────────────────────────────────
if (!$isCLI) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');
    ob_start();
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Batch Synopsis — RM Archive</title>
    <style>
      body{font-family:monospace;background:#080c12;color:#eef2f8;padding:2rem;font-size:.84rem;line-height:1.75}
      h2{color:#FFD700;margin-bottom:.5rem}.info{color:#29ABE2}.ok{color:#86efac}
      .skip{color:rgba(255,255,255,.3)}.err{color:#fca5a5}.prog{color:#FFD700;margin:1rem 0}
      a{color:#29ABE2}
    </style></head><body>';
    echo '<h2>🏃 Running Man — Batch Synopsis Fetcher</h2>';
    echo "<div class='info'>Range: EP$startFrom – EP$limitTo</div>";
    echo "<div class='info'>Episodes needing synopsis: <strong>$total</strong></div>";
    if ($total > 0)
        echo "<div class='info'>Estimated time: ~".max(1,round($total*1.3/60))." minutes</div>";
    echo "<br>";
    ob_end_flush(); flush();
}

if ($total === 0) {
    $msg = "✅ All episodes in this range already have synopsis!";
    echo $isCLI ? "$msg\n" : "<div class='ok'>$msg<br><a href='batch_synopsis.php?force=1&from=$startFrom&to=$limitTo'>Force re-fetch all?</a></div>";
    if (!$isCLI) echo '</body></html>';
    exit;
}

// ── Main loop ─────────────────────────────────────────────────
$upd = $db->prepare("UPDATE episodes SET synopsis=?, verification_required=0 WHERE episode_number=?");

foreach ($toSync as $ep) {
    $n      = (int)$ep['episode_number'];
    $padded = str_pad($n, 3, '0', STR_PAD_LEFT);
    $done++;

    $syn = getSynopsis($n);

    if ($syn) {
        $upd->execute([$syn, $n]);
        $updated++;
        $preview = mb_substr($syn, 0, 70);
        $line    = "[{$done}/{$total}] ✓ EP{$padded} — {$preview}…";
        if ($isCLI) echo "$line\n";
        else { echo "<div class='ok'>".htmlspecialchars($line)."</div>"; ob_flush(); flush(); }
    } else {
        $failed++;
        $line = "[{$done}/{$total}] – EP{$padded} — no synopsis";
        if ($isCLI) echo "$line\n";
        else { echo "<div class='skip'>$line</div>"; ob_flush(); flush(); }
    }

    if ($done % 50 === 0) {
        $pct = round($done/$total*100);
        $msg = "─── Progress: {$done}/{$total} ({$pct}%) | ✓ {$updated} updated | – {$failed} no data ───";
        if ($isCLI) echo "\n$msg\n\n";
        else { echo "<div class='prog'>$msg</div>"; ob_flush(); flush(); }
    }

    usleep(1300000); // 1.3s delay
}

@unlink(sys_get_temp_dir().'/rm_stats.json');

$summary = "=== DONE: {$updated} synopses saved, {$failed} not available, {$total} total ===";
if ($isCLI) {
    echo "\n$summary\n";
} else {
    echo "<br><div class='prog'>$summary</div>";
    if ($failed > 0)
        echo "<div class='info'>Tip: Some episodes may not have synopsis on myrunningman.com. That's okay.</div>";
    echo "<br><div><a href='/runningman_archive/'>← Back to site</a> &nbsp; <a href='batch_synopsis.php?from=".($startFrom)."&to=".($limitTo)."'>Run again (skip existing)</a></div>";
    echo '</body></html>';
}
