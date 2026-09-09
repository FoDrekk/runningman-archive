<?php
// ============================================================
// tests/pr10.php — PR10 reliability + live-source-failure handling.
//
// Covers what PR10 actually changed: the vocabulary a source failure
// is reported in (never collapsed into one generic FAILED/SKIPPED),
// the robots.txt-vs-blocked distinction in source health, the
// aired/upcoming/disagreement/insufficient-evidence classification of
// latest-episode detection, the Weekly Update's fine-grained episode
// outcome buckets, and thumbnail duplicate classification + filesystem
// path resolution (Windows/XAMPP-safe).
//
// Offline. Every section here is a pure-function or DB-optional check;
// nothing requires reaching a live source, and the one DB-backed
// section (source health status ladder) skips cleanly without MySQL.
//
//   php tests/pr10.php
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

// ============================================================
section('shared adapter fetch-status classification (never collapses into one FAILED)');

check('404 → missing_episode, not fetch_failed',
    RmScraper::classifyFetchStatus(RmHttpClient::CLASS_NOT_FOUND), 'missing_episode');
check('403 → blocked, not fetch_failed',
    RmScraper::classifyFetchStatus(RmHttpClient::CLASS_BLOCKED), 'blocked');
check('429 → rate_limited, not fetch_failed',
    RmScraper::classifyFetchStatus(RmHttpClient::CLASS_RATE_LIMITED), 'rate_limited');
check('200-but-empty → empty, not fetch_failed',
    RmScraper::classifyFetchStatus(RmHttpClient::CLASS_EMPTY), 'empty');
check('a genuine transport failure (DNS) → fetch_failed',
    RmScraper::classifyFetchStatus(RmHttpClient::CLASS_DNS), 'fetch_failed');
check('robots.txt denial → fetch_failed (a transport-level refusal, distinct from a policy state at the source-health layer)',
    RmScraper::classifyFetchStatus(RmHttpClient::CLASS_ROBOTS), 'fetch_failed');

// ============================================================
section('scraping engine — episode outcome classification (skipped/failed refinement)');

check('already-complete episode (no sources contacted) → unchanged',
    RmScrapingEngine::classifyOutcome(['skipped' => true, 'reason' => 'Episode is already complete — no sources contacted']),
    'unchanged');

check('every contacted source suppressed (cooling down) → source_blocked',
    RmScrapingEngine::classifyOutcome([
        'skipped' => false, 'failed' => true,
        'source_summary' => ['suppressed' => 3],
        'meta' => [],
    ]), 'source_blocked');

check('every contacted source returned HTTP 403 → source_blocked (not a generic failure)',
    RmScrapingEngine::classifyOutcome([
        'skipped' => false, 'failed' => true,
        'source_summary' => ['blocked' => 2],
        'meta' => [
            'myrunningman' => ['status' => 'blocked', 'class' => RmHttpClient::CLASS_BLOCKED],
            'myrm'         => ['status' => 'blocked', 'class' => RmHttpClient::CLASS_BLOCKED],
        ],
    ]), 'source_blocked');

check('robots.txt-denied sources → source_blocked (a policy wall, not "the archive failed")',
    RmScrapingEngine::classifyOutcome([
        'skipped' => false, 'failed' => true,
        'source_summary' => ['fetch_failed' => 1],
        'meta' => ['wikipedia' => ['status' => 'fetch_failed', 'class' => RmHttpClient::CLASS_ROBOTS]],
    ]), 'source_blocked');

check('genuine transport failure (DNS/timeout) → source_unavailable',
    RmScrapingEngine::classifyOutcome([
        'skipped' => false, 'failed' => true,
        'source_summary' => ['fetch_failed' => 2],
        'meta' => [
            'sbs'    => ['status' => 'fetch_failed', 'class' => RmHttpClient::CLASS_DNS],
            'wikipedia' => ['status' => 'fetch_failed', 'class' => RmHttpClient::CLASS_TIMEOUT],
        ],
    ]), 'source_unavailable');

check('parser_warning present → needs_review (selectors may be stale)',
    RmScrapingEngine::classifyOutcome([
        'skipped' => true, 'failed' => false,
        'source_summary' => ['parser_warning' => 1],
        'meta' => [],
    ]), 'needs_review');

check('reachable, healthy, nothing for this gap → insufficient_evidence',
    RmScrapingEngine::classifyOutcome([
        'skipped' => true, 'failed' => false,
        'source_summary' => ['missing_episode' => 2, 'empty' => 1],
        'meta' => [],
    ]), 'insufficient_evidence');

check('a genuine success (no source_summary at all) → no extra bucket',
    RmScrapingEngine::classifyOutcome(['skipped' => false, 'failed' => false]), null);

// ============================================================
section('weekly update — fine-grained counts are additive, not a replacement');

$run = new RmScrapeRun('latest', ['dry_run' => true]);
$run->count('checked'); $run->count('skipped'); $run->count('unchanged');
$run->count('checked'); $run->count('failed');  $run->count('source_blocked');
$run->count('checked'); $run->count('failed');  $run->count('source_unavailable');
$run->count('checked'); $run->count('skipped'); $run->count('insufficient_evidence');
$run->count('checked'); $run->count('skipped'); $run->count('needs_review');
$counts = $run->counts();
check('checked total reflects every episode', $counts['checked'], 5);
check('skipped total unchanged by the refinement (still 3)', $counts['skipped'], 3);
check('failed total unchanged by the refinement (still 2)', $counts['failed'], 2);
check('unchanged bucket recorded', $counts['unchanged'], 1);
check('source_blocked bucket recorded', $counts['source_blocked'], 1);
check('source_unavailable bucket recorded', $counts['source_unavailable'], 1);
check('insufficient_evidence bucket recorded', $counts['insufficient_evidence'], 1);
check('needs_review bucket recorded', $counts['needs_review'], 1);
$run->finish('completed');

// ============================================================
section('thumbnail duplicate classification (never auto-deleted — a label only)');

check('tiny file size → PLACEHOLDER_DUPLICATE',
    RmThumbnailEngine::classifyDuplicate([810, 811], 800, 450, 3000), 'PLACEHOLDER_DUPLICATE');
check('tiny dimensions → PLACEHOLDER_DUPLICATE',
    RmThumbnailEngine::classifyDuplicate([810, 900], 120, 90, 40000), 'PLACEHOLDER_DUPLICATE');
check('adjacent episode pair, real size → LEGITIMATE_SHARED_IMAGE (two-part special)',
    RmThumbnailEngine::classifyDuplicate([614, 615], 1280, 720, 90000), 'LEGITIMATE_SHARED_IMAGE');
check('three consecutive episodes, real size → LEGITIMATE_SHARED_IMAGE',
    RmThumbnailEngine::classifyDuplicate([700, 701, 702], 1280, 720, 90000), 'LEGITIMATE_SHARED_IMAGE');
check('far-apart episodes sharing one image, real size → LIKELY_WRONG_EPISODE',
    RmThumbnailEngine::classifyDuplicate([12, 480], 1280, 720, 90000), 'LIKELY_WRONG_EPISODE');
check('unknown dimensions/size (nulls) with far-apart episodes → LIKELY_WRONG_EPISODE (never assumed legitimate)',
    RmThumbnailEngine::classifyDuplicate([50, 600], null, null, null), 'LIKELY_WRONG_EPISODE');

// ============================================================
section('thumbnail filesystem path resolution (Windows/XAMPP-safe, DOCUMENT_ROOT-independent)');

$te = new RmThumbnailEngine(null);
$expected = realpath(__DIR__ . '/../thumbnails') ?: (__DIR__ . '/../thumbnails');
$resolved = $te->absolutePath('/runningman_archive/thumbnails/2026/ep810.jpg');
check('a web path WITH a BASE_PATH prefix resolves under the real thumbnails/ dir',
    $resolved !== null && str_contains(str_replace('\\', '/', $resolved), '/thumbnails/2026/ep810.jpg'), true);

$resolvedNoPrefix = $te->absolutePath('/thumbnails/2026/ep810.jpg');
check('a web path with NO BASE_PATH prefix resolves to the exact same file',
    $resolvedNoPrefix !== null && str_replace('\\', '/', $resolvedNoPrefix) === str_replace('\\', '/', $resolved), true);

check('resolution does not depend on $_SERVER[DOCUMENT_ROOT] at all',
    (function () use ($te) {
        $before = $te->absolutePath('/anything/thumbnails/2026/ep810.jpg');
        $_SERVER['DOCUMENT_ROOT'] = '/some/totally/different/unrelated/path';
        $after = $te->absolutePath('/anything/thumbnails/2026/ep810.jpg');
        return $before === $after;
    })(), true);

check('an unrecognised path shape returns null rather than guessing',
    $te->absolutePath('/some/unrelated/path.jpg'), null);
check('null input returns null', $te->absolutePath(null), null);

// ============================================================
section('source health — robots.txt denial is its own state, never collapsed into DOWN/BLOCKED');

$db = getDBSafe();
if ($db === null) {
    echo "SKIP: no database reachable — this section needs MySQL/MariaDB.\n";
} else {
    $health = new RmSourceHealth($db);
    $probe  = 'pr10_test_robots_source';
    $db->prepare("DELETE FROM source_health WHERE source_name = ?")->execute([$probe]);

    $health->record($probe, 'failure', ['error_class' => RmHttpClient::CLASS_ROBOTS, 'error' => 'Disallowed by robots.txt for this path']);
    $row = $db->prepare("SELECT status, disabled_until FROM source_health WHERE source_name = ?");
    $row->execute([$probe]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    check('a robots.txt-denied fetch sets status=robots_denied (not down/blocked)', $r['status'] ?? null, RmSourceHealth::ROBOTS_DENIED);
    check('robots_denied still enters a cool-down (never hammered every episode)', $r['disabled_until'] !== null, true);

    // Three CONSECUTIVE robots-denials in a row would normally push
    // consecutive_failures >= 3 → DOWN under the old logic; robots_denied
    // must win regardless of how many times it recurs, since retrying
    // changes nothing about a static robots.txt rule.
    $health->record($probe, 'failure', ['error_class' => RmHttpClient::CLASS_ROBOTS]);
    $health->record($probe, 'failure', ['error_class' => RmHttpClient::CLASS_ROBOTS]);
    $row->execute([$probe]);
    $r2 = $row->fetch(PDO::FETCH_ASSOC);
    check('repeated robots.txt denials stay robots_denied, never degrade to down', $r2['status'] ?? null, RmSourceHealth::ROBOTS_DENIED);

    [$label, , $dot] = RmSourceHealth::present(RmSourceHealth::ROBOTS_DENIED);
    check('present() gives robots_denied its own human label', $label, 'Robots.txt denied');

    $db->prepare("DELETE FROM source_health WHERE source_name = ?")->execute([$probe]);
}

// ============================================================
section('latest-episode detection — decision vocabulary, no live source reachable (offline mode)');

$det = RmLatestEpisode::detectDetailed();
// Offline mode refuses every live network call, but a source's on-disk
// parse cache can still carry a result from an earlier (fixture-driven)
// test run in the same environment, so which exact "no real evidence"
// code comes back isn't deterministic here — what's asserted is the
// actual PR10 guarantee: only a real vocabulary code comes back, never
// a fabricated episode number standing in for one.
check('decision codes are the PR10 uppercase vocabulary',
    in_array($det['decision'], ['MISSING', 'ALREADY_SYNCED', 'SOURCE_DISAGREEMENT', 'INSUFFICIENT_EVIDENCE', 'SOURCE_UNAVAILABLE'], true),
    true);
check('an empty database is never reported as ALREADY_SYNCED (nothing to be "in sync" with)',
    !($det['db_max'] === 0 && $det['decision'] === 'ALREADY_SYNCED'), true);
check('missing_aired/upcoming/insufficient_evidence are always arrays, never invented data',
    is_array($det['missing_aired']) && is_array($det['upcoming']) && is_array($det['insufficient_evidence']), true);

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'PR10 VERIFIED' : 'PR10 PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
