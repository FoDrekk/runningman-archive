<?php
// ============================================================
// tools/run_report.php — what actually happened in a run.
//
// The control centre's summary counters answer "how much", not "why".
// When a run reports 14 checked / 14 failed while the health panel says
// every source is Online, the answer is in the per-episode, per-source
// rows the engine already records — this prints them.
//
//   php tools/run_report.php                 # the most recent run
//   php tools/run_report.php --run=2         # a specific run
//   php tools/run_report.php --ep=813        # one episode, latest state
//   php tools/run_report.php --run=2 --ep=813
//
// Read-only. It queries scrape_runs, scrape_log, episode_sources and
// episode_field_sources; it writes nothing.
// ============================================================
require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$opt = ['run' => null, 'ep' => null, 'limit' => 40];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--run=(\d+)$/', $arg, $m))       $opt['run'] = (int)$m[1];
    elseif (preg_match('/^--ep=(\d+)$/', $arg, $m))    $opt['ep'] = (int)$m[1];
    elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) $opt['limit'] = (int)$m[1];
}

$db = getDBSafe();
if ($db === null) { fwrite(STDERR, "No database reachable.\n"); exit(1); }
if (!rmScrapingTablesExist()) { fwrite(STDERR, "Scraping tables not installed — run database/scraping_engine.sql.\n"); exit(1); }

function rule(string $t = ''): void { echo "\n" . ($t ? "── $t " : '') . str_repeat('─', max(0, 74 - strlen($t) - 4)) . "\n"; }

// ── The run ───────────────────────────────────────────────────
$run = null;
if ($opt['run']) {
    $q = $db->prepare('SELECT * FROM scrape_runs WHERE run_id=?'); $q->execute([$opt['run']]); $run = $q->fetch();
} elseif (!$opt['ep']) {
    $run = $db->query('SELECT * FROM scrape_runs ORDER BY run_id DESC LIMIT 1')->fetch();
}

if ($run) {
    rule('RUN #' . $run['run_id']);
    printf("  mode        : %s%s\n", $run['mode'], !empty($run['dry_run']) ? '  (DRY RUN)' : '');
    printf("  scope       : %s\n", $run['scope'] ?: '—');
    printf("  status      : %s\n", $run['status']);
    printf("  started     : %s   duration: %s\n", $run['started_at'],
           $run['duration_ms'] ? round((int)$run['duration_ms'] / 1000, 1) . 's' : '—');
    printf("  episodes    : %d checked · %d added · %d updated · %d skipped · %d failed\n",
        $run['episodes_checked'], $run['episodes_added'], $run['episodes_updated'],
        $run['episodes_skipped'], $run['episodes_failed']);

    $hasDetail = array_key_exists('sources_empty', $run);
    if ($hasDetail) {
        printf("  sources     : %d ok · %d empty · %d warned · %d failed · %d skipped\n",
            $run['sources_ok'], $run['sources_empty'], $run['sources_warned'],
            $run['sources_failed'], $run['sources_skipped']);
        echo  "                ok = gave data · empty = healthy, nothing for that episode\n";
        echo  "                warned = reachable but parsed nothing · failed = unreachable\n";
        echo  "                skipped = never contacted (not needed / disabled / cooling down)\n";
    } else {
        printf("  sources     : %d ok · %d failed · %d skipped\n",
            $run['sources_ok'], $run['sources_failed'], $run['sources_skipped']);
        echo  "                (this run predates the split counters; re-run\n";
        echo  "                 database/scraping_engine.sql to record empty/warned separately)\n";
    }
    if ($run['notes']) printf("  notes       : %s\n", $run['notes']);

    // Per-episode outcome, straight from the log.
    rule('EPISODES IN THIS RUN');
    $q = $db->prepare(
        "SELECT episode_number, event, message, level, duration_ms, created_at
           FROM scrape_log
          WHERE run_id = ? AND event IN ('episode.saved','episode.failed','episode.skipped')
          ORDER BY log_id"
    );
    $q->execute([$run['run_id']]);
    $rows = $q->fetchAll();
    if (!$rows) echo "  (no per-episode log rows recorded)\n";
    foreach ($rows as $r) {
        printf("  EP%-5s %-16s %s\n", $r['episode_number'],
               str_replace('episode.', '', $r['event']), mb_substr((string)$r['message'], 0, 92));
    }

    // Which sources were consulted, and how they ended.
    rule('SOURCE OUTCOMES ACROSS THIS RUN');
    $q = $db->prepare(
        "SELECT source_name, event, COUNT(*) n
           FROM scrape_log WHERE run_id = ? AND event LIKE 'source.%'
          GROUP BY source_name, event ORDER BY source_name, event"
    );
    $q->execute([$run['run_id']]);
    $byS = [];
    foreach ($q->fetchAll() as $r) $byS[$r['source_name']][str_replace('source.', '', $r['event'])] = (int)$r['n'];
    if (!$byS) echo "  (no per-source log rows recorded)\n";
    foreach ($byS as $src => $counts) {
        $parts = [];
        foreach ($counts as $k => $v) $parts[] = "$k=$v";
        printf("  %-14s %s\n", $src, implode('  ', $parts));
    }

    // The reasons, deduplicated — this is usually the whole answer.
    rule('DISTINCT REASONS REPORTED BY SOURCES');
    $q = $db->prepare(
        "SELECT source_name, level, message, COUNT(*) n
           FROM scrape_log
          WHERE run_id = ? AND event LIKE 'source.%' AND level <> 'info'
          GROUP BY source_name, level, message ORDER BY n DESC LIMIT 25"
    );
    $q->execute([$run['run_id']]);
    $reasons = $q->fetchAll();
    if (!$reasons) echo "  (none — every source reported success)\n";
    foreach ($reasons as $r) {
        printf("  %-14s %-8s ×%-3d %s\n", $r['source_name'], strtoupper((string)$r['level']),
               $r['n'], mb_substr((string)$r['message'], 0, 76));
    }
}

// ── One episode ───────────────────────────────────────────────
if ($opt['ep']) {
    $ep = $opt['ep'];
    rule("EPISODE $ep — SOURCE BY SOURCE (latest recorded state)");
    $q = $db->prepare('SELECT * FROM episode_sources WHERE episode_number=? ORDER BY source_name');
    $q->execute([$ep]);
    $rows = $q->fetchAll();
    if (!$rows) {
        echo "  No source provenance recorded for EP$ep.\n";
        echo "  Run a trace to populate it:  Admin → Diagnostics → Single Episode Scrape Trace\n";
        echo "  (or on the CLI: php tools/capture_source.php --all --ep=$ep)\n";
    }
    foreach ($rows as $r) {
        $cls = rmScrapeSourceClass((string)$r['source_name']);
        printf("\n  %s   (%s)\n", strtoupper((string)$r['source_name']), $cls);
        printf("    FETCH  : %s\n", in_array($r['status'], ['fetch_failed','blocked','rate_limited','adapter_error'], true)
                                     ? 'FAILED' : ($r['status'] === 'suppressed' ? 'NOT ATTEMPTED (cooling down)' : 'OK'
                                        . ($r['http_status'] ? ' (HTTP ' . $r['http_status'] . ')' : '')));
        printf("    PARSE  : %s\n", match ((string)$r['status']) {
            'ok'                => 'OK',
            'parser_warning'    => 'WARNING — reachable but parsed nothing',
            'needs_javascript'  => 'BLOCKED BY CLIENT RENDERING — content not in the served HTML',
            'missing_episode'   => 'n/a — source does not list this episode',
            'empty'             => 'n/a — nothing returned',
            'disabled'          => 'not run — source disabled',
            'not_applicable'    => 'not run — not an episode source',
            'suppressed'        => 'not run — health cool-down',
            default             => 'not reached',
        });
        printf("    FIELDS : %s\n", $r['fields_provided'] ?: '(none)');
        printf("    STATUS : %s\n", strtoupper(str_replace('_', ' ', (string)$r['status'])));
        printf("    url    : %s\n", $r['source_url'] ?: '—');
        printf("    when   : %s   parser %s   %sms\n", $r['fetched_at'], $r['parser_version'] ?: '?', $r['duration_ms']);
    }

    rule("EPISODE $ep — WHICH SOURCE WON EACH FIELD");
    $q = $db->prepare('SELECT * FROM episode_field_sources WHERE episode_number=? ORDER BY field_name');
    $q->execute([$ep]);
    $f = $q->fetchAll();
    if (!$f) echo "  (no field provenance recorded)\n";
    foreach ($f as $r) {
        printf("  %-14s %-14s %-9s agreed: %-24s %s\n", $r['field_name'], $r['source_name'],
               strtoupper((string)$r['confidence']), $r['agreeing_sources'] ?: '—',
               $r['conflicting'] ? 'CONFLICT: ' . mb_substr((string)$r['conflicting'], 0, 40) : '');
    }

    rule("EPISODE $ep — RECENT LOG");
    $q = $db->prepare('SELECT * FROM scrape_log WHERE episode_number=? ORDER BY log_id DESC LIMIT ?');
    $q->bindValue(1, $ep, PDO::PARAM_INT);
    $q->bindValue(2, $opt['limit'], PDO::PARAM_INT);
    $q->execute();
    foreach (array_reverse($q->fetchAll()) as $r) {
        printf("  [%s] %-9s %-22s %-14s %s\n", substr((string)$r['created_at'], 11, 8),
               strtoupper((string)$r['level']), $r['event'], $r['source_name'] ?: '',
               mb_substr((string)$r['message'], 0, 70));
    }
}

// ── Source health, for comparison with the run counters ───────
rule('SOURCE HEALTH (persistent, across all runs)');
printf("  %-14s %-16s %-8s %-22s %s\n", 'SOURCE', 'STATUS', 'RATE', 'LAST SUCCESS', 'LAST ERROR');
foreach (RmSourceHealth::instance()->all() as $name => $h) {
    printf("  %-14s %-16s %-8s %-22s %s\n",
        $name, $h['status'],
        $h['success_rate'] === null ? '—' : $h['success_rate'] . '%',
        $h['last_success_at'] ? substr((string)$h['last_success_at'], 0, 16) : 'never',
        mb_substr((string)($h['last_error'] ?? ''), 0, 60));
}
echo "\n  Health is a property of the SOURCE. A run's counters are a property\n";
echo "  of that RUN. A source can be perfectly healthy and still have no data\n";
echo "  for the episodes a given run happened to ask about.\n";
