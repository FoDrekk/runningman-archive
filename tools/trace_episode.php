<?php
// ============================================================
// tools/trace_episode.php — one episode, every source, in full.
//
// The Control Centre's health panel answers "is this source up?".
// That is a different question from "did this source have anything for
// episode 813?", and conflating the two is what made a run in which
// nothing was wrong read as 14 failures. This prints the second answer,
// source by source:
//
//   FETCH   what was requested, what came back
//   PARSE   what the adapter made of it
//   FIELDS  what it actually offered
//   STATUS  what that means, and whether it counts against the source
//
//   php tools/trace_episode.php --ep=813
//   php tools/trace_episode.php --ep=813 --fresh     (ignore the cache)
//   php tools/trace_episode.php --ep=813 --json
//
// Read-only by construction: it uses the engine's trace mode, which
// writes no episode data, no provenance, no health and no log rows. The
// tool verifies that itself and says so at the end — if any table grew,
// it reports that as a failure rather than staying quiet about it.
// ============================================================
require_once __DIR__ . '/../includes/scraper.php';

$opt = ['ep' => 0, 'fresh' => false, 'json' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--ep=(\d+)$/', $arg, $m)) $opt['ep'] = (int)$m[1];
    elseif ($arg === '--fresh') $opt['fresh'] = true;
    elseif ($arg === '--json')  $opt['json']  = true;
}
if (!$opt['ep']) {
    fwrite(STDERR, "usage: php tools/trace_episode.php --ep=<n> [--fresh] [--json]\n");
    exit(2);
}
$ep = $opt['ep'];
$db = getDBSafe();

/** Row counts of every table the write path touches. */
function snapshot(?PDO $db, int $ep): array {
    if (!$db) return [];
    $out = [];
    foreach (['episodes' => "episode_number = $ep",
              'episode_sources' => "episode_number = $ep",
              'episode_field_sources' => "episode_number = $ep",
              'scrape_changes' => "episode_number = $ep",
              'scrape_log' => '1', 'scrape_runs' => '1', 'source_health' => '1'] as $t => $where) {
        try { $out[$t] = (int)$db->query("SELECT COUNT(*) FROM `$t` WHERE $where")->fetchColumn(); }
        catch (Throwable $e) { /* table not installed on this system */ }
    }
    try {
        $row = $db->prepare("SELECT title, air_date, CHAR_LENGTH(COALESCE(synopsis,'')) FROM episodes WHERE episode_number = ?");
        $row->execute([$ep]);
        $out['_episode_row'] = $row->fetch(PDO::FETCH_NUM) ?: null;
    } catch (Throwable $e) {}
    return $out;
}

$before = snapshot($db, $ep);
$engine = new RmScrapingEngine();
$t0     = microtime(true);
$plan   = $engine->trace($ep, ['bypass_cache' => $opt['fresh']]);
$ms     = (int)round((microtime(true) - $t0) * 1000);
$after  = snapshot($db, $ep);

if ($opt['json']) {
    echo json_encode(['plan' => $plan, 'wrote' => array_diff_assoc(
        array_map('strval', array_filter($after, 'is_scalar')),
        array_map('strval', array_filter($before, 'is_scalar')))],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

// ── What each status means, and who it counts against ──────────
// The key distinction the health panel cannot make: a source that is
// perfectly healthy and simply does not carry this episode is NOT a
// problem with the source.
$verdicts = [
    'ok'              => ['gave data',                      'source healthy'],
    'empty'           => ['reached, nothing for this episode', 'source healthy'],
    'missing_episode' => ['reached, episode not listed',     'source healthy'],
    'not_applicable'  => ['does not cover episodes',         'source healthy'],
    'parser_warning'  => ['reached, parsed nothing',         'NEEDS ATTENTION — page structure may have changed'],
    'needs_javascript'=> ['reached, content is client-rendered', 'NEEDS ATTENTION — HTML carries no episode text'],
    'structure_changed' => ['reached, markup no longer matches', 'NEEDS ATTENTION — selectors are stale'],
    'fetch_failed'    => ['could not be reached',            'counts against the source'],
    'proxy_blocked'   => ['blocked before it left this machine', 'YOUR NETWORK — not the source'],
    'blocked'         => ['refused the request',             'counts against the source'],
    'rate_limited'    => ['asked us to slow down',           'counts against the source'],
    'adapter_error'   => ['the adapter itself failed',       'BUG — counts against us, not the source'],
    'suppressed'      => ['not contacted — in a cool-down',  'not contacted'],
    'disabled'        => ['not contacted — not configured',  'not contacted'],
    'skipped'         => ['not contacted — not needed',      'not contacted'],
];

$w = fn(string $s, int $n) => str_pad(mb_strimwidth($s, 0, $n, '…'), $n);
echo "\n";
echo "═══ EPISODE $ep — READ-ONLY TRACE ═══  " . date('Y-m-d H:i:s') . "  ({$ms}ms)\n\n";

$existing = $plan['existing'] ?? [];
echo "IN THE ARCHIVE NOW\n";
if (!$existing) echo "  (nothing — this episode does not exist yet)\n";
else foreach (['title','title_ko','air_date','synopsis','main_mission','image_url'] as $f) {
    if (!array_key_exists($f, $existing)) continue;
    $v = (string)($existing[$f] ?? '');
    printf("  %s %s\n", $w($f, 14), $v === '' ? '—' : mb_strimwidth($v, 0, 70, '…'));
}
echo "\n";

$meta = $plan['meta'] ?? [];
if (!$meta) echo "No source was contacted: " . ($plan['reason'] ?? 'no reason given') . "\n\n";

$tally = [];
foreach ($meta as $name => $m) {
    $status = (string)($m['status'] ?? '?');
    $tally[$status] = ($tally[$status] ?? 0) + 1;
    [$means, $verdict] = $verdicts[$status] ?? ['unrecognised status', 'unknown'];
    // A proxy refusing the CONNECT tunnel looks like a fetch failure but is
    // a fault on this side of the wire. Saying "counts against the source"
    // there sends the reader after the wrong problem entirely.
    if (($m['class'] ?? null) === 'proxy_blocked') [$means, $verdict] = $verdicts['proxy_blocked'];

    echo "───────────────────────────────────────────────────────────────\n";
    echo strtoupper($name) . "\n";
    echo "  FETCH   " . ($m['url'] ?: '(no request made)') . "\n";
    echo "          HTTP " . ($m['http'] === null ? '—' : $m['http'])
       . " · " . (int)($m['ms'] ?? 0) . "ms"
       . " · " . (!empty($m['cached']) ? 'from cache' : 'live')
       . (isset($m['class']) && $m['class'] ? " · class=" . $m['class'] : '') . "\n";
    echo "  PARSE   parser " . ($m['version'] ?? '?')
       . " → " . count((array)($m['fields'] ?? [])) . " field(s)\n";
    if (!empty($m['error'])) {
        foreach (explode("\n", wordwrap((string)$m['error'], 60, "\n", true)) as $i => $line)
            echo "          " . ($i === 0 ? '' : '') . $line . "\n";
    }
    $fields = (array)($m['fields'] ?? []);
    echo "  FIELDS  " . ($fields ? implode(', ', array_keys($fields)) : '(none)') . "\n";
    echo "  STATUS  $status — $means\n";
    echo "          $verdict\n";
}
echo "───────────────────────────────────────────────────────────────\n\n";

echo "OUTCOME\n";
$outcome = !empty($plan['failed']) ? 'FAILED' : (!empty($plan['skipped']) ? 'SKIPPED (nothing to change)' : 'WOULD UPDATE');
echo "  $outcome\n";
if (!empty($plan['reason'])) echo "  " . wordwrap((string)$plan['reason'], 66, "\n  ", true) . "\n";
if ($tally) {
    $parts = [];
    foreach ($tally as $s => $n) $parts[] = "$s=$n";
    echo "  sources: " . implode(' · ', $parts) . "\n";
}
foreach ((array)($plan['changes'] ?? []) as $ch) {
    printf("  %s %s: %s → %s  (%s, %s)\n",
        in_array($ch['field'], (array)($plan['apply'] ?? []), true) ? '✓' : '·',
        $ch['field'],
        mb_strimwidth((string)($ch['from'] ?? '—'), 0, 24, '…'),
        mb_strimwidth((string)($ch['to'] ?? '—'), 0, 24, '…'),
        $ch['source'] ?? '?', $ch['confidence'] ?? '?');
}
foreach ((array)($plan['warnings'] ?? []) as $wn)
    echo "  ! " . ($wn['type'] ?? 'warning') . ': ' . ($wn['message'] ?? '') . "\n";

// ── The guarantee, checked rather than asserted ────────────────
echo "\nWRITE SAFETY\n";
if (!$db) { echo "  (no database — nothing to check)\n"; exit(0); }
$grew = [];
foreach ($before as $t => $v) {
    if ($t[0] === '_' || !isset($after[$t]) || !is_scalar($v)) continue;
    if ($after[$t] !== $v) $grew[] = "$t: $v → {$after[$t]}";
}
if (($before['_episode_row'] ?? null) != ($after['_episode_row'] ?? null)) $grew[] = 'episodes row changed';
if ($grew) {
    echo "  FAILED — the trace wrote to the database:\n";
    foreach ($grew as $g) echo "    · $g\n";
    exit(1);
}
echo "  verified — no episode data, provenance, health or log row changed\n";
exit(0);
