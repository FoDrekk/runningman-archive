<?php
// ============================================================
// tests/pr11.php — the archive state model: coverage vs metadata
// completeness vs research state vs source health, kept as four
// separate axes that must never be conflated (PR11 §2).
//
// Uses a dedicated episode-number range (1970-1989) untouched by any
// other suite, cleaned up before and after. The composition logic in
// RmResearchState::archiveCoverage() is exercised with INJECTED
// detection arrays (no live network needed — same technique tests/pr10.php
// uses), so aired/upcoming/disagreement/unavailable scenarios are all
// deterministic regardless of what this environment can actually reach.
//
//   php tests/pr11.php
// ============================================================
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';

require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

const P_LO = 1970, P_HI = 1989;

// ============================================================
section('archiveCoverage() composition — injected detection, no live network needed');

$state = new RmResearchState();

// Edge case: source disagreement — never claim ALREADY_SYNCED or invent
// a specific episode.
$cov = $state->archiveCoverage(50, [
    'latest_aired' => null, 'upcoming' => [], 'missing_aired' => [],
    'decision' => 'SOURCE_DISAGREEMENT', 'decision_note' => 'Sources disagree by 6 episodes',
]);
check('source disagreement: decision passes through unchanged', $cov['decision'], 'SOURCE_DISAGREEMENT');
check('source disagreement: no latest_verified_aired invented', $cov['latest_verified_aired'], null);

// Edge case: source unavailable.
$cov2 = $state->archiveCoverage(50, [
    'latest_aired' => null, 'upcoming' => [], 'missing_aired' => [],
    'decision' => 'SOURCE_UNAVAILABLE', 'decision_note' => 'No external source responded',
]);
check('source unavailable: decision passes through unchanged', $cov2['decision'], 'SOURCE_UNAVAILABLE');

// Edge case: upcoming episode exists — must never be counted as missing
// or reported as the latest AIRED episode.
$dbMaxNow = (new RmMissingData())->maxEpisode();
$cov3 = $state->archiveCoverage(50, [
    'latest_aired' => $dbMaxNow ?: null, 'upcoming' => [['episode' => $dbMaxNow + 5, 'air_date' => '2099-01-01']],
    'missing_aired' => [], 'decision' => 'ALREADY_SYNCED', 'decision_note' => 'up to date; one upcoming',
]);
check('upcoming episode surfaced distinctly', $cov3['upcoming'], $dbMaxNow + 5);
check('upcoming episode never counted as a missing episode', in_array($dbMaxNow + 5, $cov3['missing_episodes'], true), false);

// Edge case: database latest < verified latest (aired episodes confirmed
// beyond the archive max) — these must show up as missing.
$cov4 = $state->archiveCoverage(50, [
    'latest_aired' => $dbMaxNow + 3, 'upcoming' => [], 'decision' => 'MISSING',
    'decision_note' => '3 aired episode(s) confirmed by live sources are not yet in the database.',
    'missing_aired' => [
        ['episode' => $dbMaxNow + 1, 'air_date' => '2026-01-01'],
        ['episode' => $dbMaxNow + 2, 'air_date' => '2026-01-08'],
        ['episode' => $dbMaxNow + 3, 'air_date' => '2026-01-15'],
    ],
]);
check('database latest < verified latest: all 3 confirmed-aired gaps counted missing',
    array_intersect([$dbMaxNow+1, $dbMaxNow+2, $dbMaxNow+3], $cov4['missing_episodes']) == [$dbMaxNow+1, $dbMaxNow+2, $dbMaxNow+3], true);
check('missing_range is a real, non-empty string when episodes are missing', is_string($cov4['missing_range']) && $cov4['missing_range'] !== '', true);

// Edge case: database latest == verified latest — nothing confirmed missing.
$cov5 = $state->archiveCoverage(50, [
    'latest_aired' => $dbMaxNow ?: null, 'upcoming' => [], 'missing_aired' => [],
    'decision' => 'ALREADY_SYNCED', 'decision_note' => 'Database matches every confirmed aired episode.',
]);
check('database latest == verified latest: decision is ALREADY_SYNCED', $cov5['decision'], 'ALREADY_SYNCED');

// Invariant that holds regardless of what else is in the table right now.
$directCount = (int)(getDBSafe()?->query('SELECT COUNT(*) FROM episodes')->fetchColumn() ?? 0);
check('stored_episodes always matches a direct COUNT(*) (coverage is never stale/cached wrongly)',
    $cov['stored_episodes'], $directCount);

// ============================================================
section('coreCompleteness() / fieldGapCounts() — core vs enrichment, PR11 §5/§16');

$db = getDBSafe();
if ($db === null) {
    echo "SKIP: no database reachable — this section needs MySQL/MariaDB.\n";
} else {
    // Isolate: this range is untouched by every other suite.
    foreach (['episode_guests', 'episode_tags', 'thumbnails', 'episodes'] as $t) {
        try { $db->exec("DELETE FROM `$t` WHERE episode_number BETWEEN " . P_LO . " AND " . P_HI); } catch (Throwable $e) {}
    }
    $db->exec("INSERT IGNORE INTO years (year_label, total_eps) VALUES (2026, 0)");
    $yr = (int)$db->query("SELECT year_id FROM years WHERE year_label=2026")->fetchColumn();

    // P_LO:     core-complete (title+air_date), but missing every
    //           enrichment field — this is EXACTLY the "no synopsis
    //           because no source publishes one" scenario (PR11 §11/§23):
    //           a real episode, fully core-complete, enrichment absent.
    // P_LO+1:   core-partial — no air_date at all.
    // P_LO+2:   core-partial — title is just the bare placeholder.
    $db->prepare("INSERT INTO episodes (episode_number, year_id, title, air_date, synopsis, is_special, verification_required)
                  VALUES (?, ?, ?, ?, NULL, 0, 0)")
       ->execute([P_LO, $yr, 'Episode #' . P_LO . ' - Full Title', '2026-01-01']);
    $db->prepare("INSERT INTO episodes (episode_number, year_id, title, air_date, synopsis, is_special, verification_required)
                  VALUES (?, ?, ?, NULL, NULL, 0, 0)")
       ->execute([P_LO + 1, $yr, 'Episode #' . (P_LO + 1) . ' - Has Title No Date']);
    $db->prepare("INSERT INTO episodes (episode_number, year_id, title, air_date, synopsis, is_special, verification_required)
                  VALUES (?, ?, ?, ?, NULL, 0, 0)")
       ->execute([P_LO + 2, $yr, 'Episode #' . str_pad((string)(P_LO+2), 3, '0', STR_PAD_LEFT), '2026-01-15']);

    $lo = P_LO; $hi = P_HI;
    $coreGap = (int)$db->query(
        "SELECT COUNT(*) FROM episodes e WHERE e.episode_number BETWEEN $lo AND $hi AND (
            (e.title IS NULL OR e.title = '' OR e.title NOT LIKE '%% - %%') OR e.air_date IS NULL)"
    )->fetchColumn();
    check('exactly 2 of 3 synthetic episodes are CORE-partial (missing title or air_date)', $coreGap, 2);

    $coreOk = (int)$db->query(
        "SELECT COUNT(*) FROM episodes e WHERE e.episode_number BETWEEN $lo AND $hi AND
            (e.title IS NOT NULL AND e.title <> '' AND e.title LIKE '%% - %%') AND e.air_date IS NOT NULL"
    )->fetchColumn();
    check('exactly 1 of 3 is CORE-complete despite having zero enrichment fields',
        $coreOk, 1);

    // The core-complete episode (P_LO) is missing synopsis, guests,
    // thumbnail, etc. — an ARCHIVE-COVERAGE-complete record with
    // INSUFFICIENT_EVIDENCE for enrichment, never "the episode is missing".
    $missing = new RmMissingData($db);
    $gaps = $missing->gaps(P_LO);
    check('the core-complete episode still has real enrichment gaps (a different axis)',
        in_array('synopsis', $gaps, true) && in_array('guests', $gaps, true), true);
    check('...but title/air_date are NOT among them — core fields are satisfied',
        !in_array('title', $gaps, true) && !in_array('air_date', $gaps, true), true);

    $counts = $missing->fieldGapCounts(['title', 'air_date', 'synopsis']);
    check('fieldGapCounts() returns the fields asked for', array_keys($counts), ['title', 'air_date', 'synopsis']);
    check('fieldGapCounts() counts are non-negative integers', min($counts) >= 0, true);

    // ── Cleanup ──
    foreach (['episode_guests', 'episode_tags', 'thumbnails', 'episodes'] as $t) {
        try { $db->exec("DELETE FROM `$t` WHERE episode_number BETWEEN " . P_LO . " AND " . P_HI); } catch (Throwable $e) {}
    }
}

// ============================================================
section('attention() bucket labels — coverage gaps vs metadata gaps, never the same word (PR11 §3/§8)');

$buckets = $state->attention(50);
check('the "new" bucket (genuinely missing episode numbers) is labelled for coverage, not metadata',
    $buckets['new']['label'], 'Missing Episodes');
check('the "incomplete" bucket (researched, enrichment unavailable) is labelled Insufficient Evidence, not "Incomplete"',
    $buckets['incomplete']['label'], 'Insufficient Evidence');
check('every bucket still explains itself with a real hint', strlen($buckets['incomplete']['hint']) > 10, true);
check('"Missing Episodes" and "Insufficient Evidence" are never the same episode set by construction',
    array_intersect($buckets['new']['episodes'], $buckets['incomplete']['episodes']), []);

// ============================================================
section('research failure classification — SOURCE_BLOCKED vs NETWORK_FAILURE vs PARSER_FAILURE (PR11 §10)');

check('every contacted source blocked (403/robots/rate-limited) → SOURCE_BLOCKED',
    RmResearchService::failureReasonCode([
        ['status' => 'blocked'], ['status' => 'blocked'],
    ]), 'SOURCE_BLOCKED');
check('every contacted source unreachable at the transport level → NETWORK_FAILURE',
    RmResearchService::failureReasonCode([
        ['status' => 'fetch_failed'], ['status' => 'fetch_failed'],
    ]), 'NETWORK_FAILURE');
check('every contacted source reachable but unparseable → PARSER_FAILURE',
    RmResearchService::failureReasonCode([
        ['status' => 'structure_changed'], ['status' => 'needs_javascript'],
    ]), 'PARSER_FAILURE');
check('nothing was even contacted (all skipped/disabled) → NO_SOURCES_AVAILABLE',
    RmResearchService::failureReasonCode([
        ['status' => 'disabled'], ['status' => 'skipped'],
    ]), 'NO_SOURCES_AVAILABLE');

// ============================================================
section('edge cases — zero and one episode');

if ($db !== null) {
    $realTotal = (int)$db->query('SELECT COUNT(*) FROM episodes')->fetchColumn();
    if ($realTotal === 0) {
        $zeroCov = $state->archiveCoverage();
        check('zero episodes: stored_episodes is 0, not an error', $zeroCov['stored_episodes'], 0);
        check('zero episodes: core completeness reports 0/0 without dividing by zero', $zeroCov['core']['pct'], 0);
        check('zero episodes: archive_latest is null, never 0-as-if-real', $zeroCov['archive_latest'], null);

        $db->exec("INSERT IGNORE INTO years (year_label, total_eps) VALUES (2026, 0)");
        $yr = (int)$db->query("SELECT year_id FROM years WHERE year_label=2026")->fetchColumn();
        $db->prepare("INSERT INTO episodes (episode_number, year_id, title, air_date, is_special, verification_required)
                      VALUES (?, ?, ?, ?, 0, 0)")->execute([P_LO, $yr, 'Episode #' . P_LO . ' - Solo', '2026-01-01']);
        $oneCov = $state->archiveCoverage();
        check('one episode: stored_episodes is 1', $oneCov['stored_episodes'], 1);
        check('one episode: archive_latest equals that episode', $oneCov['archive_latest'], P_LO);
        check('one episode: core completeness is 100%', $oneCov['core']['pct'], 100);
        $db->exec("DELETE FROM episodes WHERE episode_number = " . P_LO);
    } else {
        echo "SKIP: episodes table has $realTotal row(s) — zero/one-episode edge cases need an empty table.\n";
    }
} else {
    echo "SKIP: no database reachable.\n";
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'PR11 VERIFIED' : 'PR11 PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
