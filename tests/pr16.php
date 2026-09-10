<?php
// ============================================================
// tests/pr16.php — Archive Verification & Health.
//
// PR16 did NOT rebuild integrity checking: RmDuplicateDetector already
// swept for duplicate/invalid episode numbers, invalid/duplicate air
// dates, orphaned guests/thumbnails and broken references (tested
// already in tests/resolution.php); RmResearchState already computed
// archive coverage and per-bucket research-state counts (tests/pr11.php,
// tests/pr13.php); RmThumbnailEngine already classified six thumbnail
// states (tests/pr14.php); RmLatestEpisode already detected the latest
// episode from independent evidence (tests/pr13.php). None of that is
// repeated here.
//
// This file covers what PR16 actually added: RmArchiveHealth, the thin
// aggregator that turns all of the above into one explainable report —
// per-domain status, a structured issue list with severity, and the one
// genuinely new check (Domain G: does AI/decision provenance actually
// hold the invariants the write-gates are supposed to guarantee?).
//
//   php tests/pr16.php
// ============================================================
require_once __DIR__ . '/../includes/scraping/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

function issuesOf(array $report, string $id): array {
    return array_values(array_filter($report['issues'], fn($i) => $i['id'] === $id));
}

const T_LO = 999500;
const T_HI = 999529;

// ============================================================
// RmArchiveHealth (like every class in this codebase — RmResearchState,
// RmMissingData, RmDuplicateDetector, ...) treats a null constructor
// argument as "use the default connection", not "force no database":
// `$db ?: getDBSafe()`. A genuinely absent PDO is already handled by
// each domain's own internal `$this->db === null` guard (verified by
// reading ThumbnailEngine/DuplicateDetector/etc.'s own null-safety);
// what is actually testable end-to-end here is the archive being
// EMPTY (reachable, zero rows) — Section 19's real scenario. This
// sandbox's archive is expected to be empty throughout this project's
// PR sessions; if that's ever not true, this section skips rather than
// asserting against unknown data.
section('empty archive — never reported "100% healthy"');
$emptyCheckDb = getDBSafe();
$archiveIsEmpty = $emptyCheckDb !== null && (int)$emptyCheckDb->query('SELECT COUNT(*) FROM episodes')->fetchColumn() === 0;
if (!$archiveIsEmpty) {
    echo "  SKIP: the archive is not currently empty — this section only runs against a genuinely empty archive.\n";
}
$h0 = new RmArchiveHealth($emptyCheckDb);
$r0 = $h0->scan();
check('scan() never throws', is_array($r0), true);
check('  → read_only is always true', $r0['read_only'], true);
if ($archiveIsEmpty) {
    check('  → episode coverage reports EMPTY, not a false 100%', $r0['domains']['episode_coverage']['status'], 'EMPTY');
    check('  → metadata reports EMPTY', $r0['domains']['metadata']['status'], 'EMPTY');
    check('  → research reports EMPTY', $r0['domains']['research']['status'], 'EMPTY');
    check('  → thumbnails reports EMPTY', $r0['domains']['thumbnails']['status'], 'EMPTY');
    check('  → an empty archive is never reported "100% healthy"',
          (bool)array_filter($r0['domains']['episode_coverage']['issues'], fn($i) => $i['id'] === 'ARCHIVE_EMPTY'), true);
}
check('  → latest verification is honestly UNKNOWN/NOT_CHECKED-derived, never invented when nothing has been verified',
      in_array($r0['domains']['latest_verification']['status'], ['UNKNOWN', 'VERIFIED'], true), true);

section('no database reachable — every domain degrades, none crashes or fabricates GOOD');
$h1 = new class(null) extends RmArchiveHealth {
    // Force a genuine "no PDO" path through every domain, bypassing the
    // getDBSafe() fallback every class in this codebase otherwise takes —
    // this is what "no database configured at all" actually looks like.
    public function __construct($db) { parent::__construct($db); $ref = new ReflectionProperty(RmArchiveHealth::class, 'db'); $ref->setAccessible(true); $ref->setValue($this, null); }
};
$r1 = $h1->scan();
check('scan() never throws with truly no database', is_array($r1), true);
check('  → thumbnails degrades to UNKNOWN rather than guessing', $r1['domains']['thumbnails']['status'], 'UNKNOWN');
check('  → provenance degrades to NOT_INSTALLED rather than GOOD', $r1['domains']['provenance']['status'], 'NOT_INSTALLED');
check('  → episode coverage degrades to EMPTY, never CRITICAL/GOOD from missing data', $r1['domains']['episode_coverage']['status'], 'EMPTY');

// ============================================================
section('severity vocabulary — every issue uses the documented set');
$validSeverities = ['INFO', 'WARNING', 'ERROR', 'CRITICAL'];
$allValid = true;
foreach ($r0['issues'] as $i) if (!in_array($i['severity'], $validSeverities, true)) $allValid = false;
check('every issue severity is one of INFO/WARNING/ERROR/CRITICAL', $allValid, true);
$issueFields = ['id', 'domain', 'severity', 'episode', 'description', 'evidence', 'recommended_action', 'safe_to_auto_repair', 'detected_at'];
check('every issue carries the Section 12 issue model fields (recommended_action/episode may legitimately be null)',
      (bool)array_filter($r0['issues'], fn($i) => count(array_intersect_key($i, array_flip($issueFields))) !== count($issueFields)), false);
check('no health score is invented — only an explainable per-domain breakdown', isset($r0['score']), false);

// ============================================================
section('determinism — scanning unchanged data twice gives the same classification');
$rA = (new RmArchiveHealth(null))->scan();
$rB = (new RmArchiveHealth(null))->scan();
$strip = function (array $r) {
    unset($r['generated_at']);
    foreach ($r['issues'] as &$i) unset($i['detected_at']);
    return $r;
};
check('two scans of the same (unchanged) data produce identical issues/domains', $strip($rA) == $strip($rB), true);

// ============================================================
section('filtering — a pure view over an already-computed issue list');
$sample = [
    ['id' => 'A', 'severity' => 'CRITICAL', 'domain' => 'metadata', 'episode' => 5],
    ['id' => 'B', 'severity' => 'WARNING', 'domain' => 'thumbnails', 'episode' => null],
    ['id' => 'A', 'severity' => 'INFO', 'domain' => 'metadata', 'episode' => 7],
];
check('filtering by severity keeps only matching issues',
      array_column(RmArchiveHealth::filterIssues($sample, ['severity' => 'CRITICAL']), 'id'), ['A']);
check('filtering by domain keeps only matching issues',
      count(RmArchiveHealth::filterIssues($sample, ['domain' => 'metadata'])), 2);
check('filtering by episode keeps only that episode',
      array_column(RmArchiveHealth::filterIssues($sample, ['episode' => 7]), 'id'), ['A']);
check('filtering by issue id/type works', count(RmArchiveHealth::filterIssues($sample, ['id' => 'A'])), 2);
check('no filters returns everything unchanged', count(RmArchiveHealth::filterIssues($sample, [])), 3);
check('combined filters intersect', count(RmArchiveHealth::filterIssues($sample, ['domain' => 'metadata', 'severity' => 'INFO'])), 1);

// ============================================================
section('latest episode verification — VERIFIED / CONFLICT / MISSING / UNKNOWN, never guessed');
$h = new RmArchiveHealth(null);
$scanWith = function (array $detection) use ($h) { return $h->scan(['detection' => $detection])['domains']['latest_verification']; };

$verified = $scanWith(['decision' => 'ALREADY_SYNCED', 'decision_note' => 'matches', 'latest_aired' => 810, 'upcoming' => []]);
check('ALREADY_SYNCED maps to VERIFIED', $verified['status'], 'VERIFIED');
check('  → and produces no issue', $verified['issues'], []);

$missing = $scanWith(['decision' => 'MISSING', 'decision_note' => '1 confirmed missing', 'latest_aired' => 811, 'upcoming' => []]);
check('MISSING maps to MISSING_AIRED_EPISODES', $missing['status'], 'MISSING_AIRED_EPISODES');
check('  → and is reported as an ERROR (evidence-confirmed, not a guess)', $missing['issues'][0]['severity'], 'ERROR');

$conflict = $scanWith(['decision' => 'SOURCE_DISAGREEMENT', 'decision_note' => 'sources disagree', 'latest_aired' => null, 'upcoming' => []]);
check('SOURCE_DISAGREEMENT maps to CONFLICT', $conflict['status'], 'CONFLICT');
check('  → and is a WARNING, not silently trusted', $conflict['issues'][0]['severity'], 'WARNING');

$unknown = $scanWith(['decision' => 'SOURCE_UNAVAILABLE', 'decision_note' => 'nothing responded', 'latest_aired' => null, 'upcoming' => []]);
check('SOURCE_UNAVAILABLE maps to UNKNOWN, never a false answer', $unknown['status'], 'UNKNOWN');
check('  → UNKNOWN is INFO, not an error — it is honesty, not a failure', $unknown['issues'][0]['severity'], 'INFO');
check('  → and never claims a specific missing episode', $unknown['issues'][0]['id'], 'LATEST_VERIFICATION_UNKNOWN');

$insufficient = $scanWith(['decision' => 'INSUFFICIENT_EVIDENCE', 'decision_note' => 'no confirmed air date', 'latest_aired' => null, 'upcoming' => []]);
check('INSUFFICIENT_EVIDENCE also reports UNKNOWN rather than inventing a result', $insufficient['status'], 'UNKNOWN');

// ============================================================
section('source health — never claims confidence from an unconfirmed source');
$srcReport = (new RmArchiveHealth(getDBSafe()))->scan()['domains']['sources'];
check('domain status is one of the documented values', in_array($srcReport['status'], ['GOOD', 'INFO', 'WARNING', 'ERROR', 'UNKNOWN'], true), true);
$claimsConfidenceFromUnconfirmed = (bool)array_filter($srcReport['issues'], fn($i) =>
    $i['id'] === 'SOURCE_PARTIALLY_UNAVAILABLE' && ($i['evidence']['online'] ?? []) === []);
check('a "confidence not reduced" claim never fires with zero confirmed-online sources',
      $claimsConfidenceFromUnconfirmed, false);
if (($srcReport['counts']['online'] ?? 0) === 0 && (($srcReport['counts']['unavailable'] ?? 0) + ($srcReport['counts']['degraded'] ?? 0)) > 0) {
    check('with no confirmed-online source, the honest SOURCE_HEALTH_UNCERTAIN (or ALL_SOURCES_UNAVAILABLE) issue fires instead',
          (bool)array_filter($srcReport['issues'], fn($i) => in_array($i['id'], ['SOURCE_HEALTH_UNCERTAIN', 'ALL_SOURCES_UNAVAILABLE'], true)), true);
}

// ============================================================
$db = getDBSafe();
if ($db === null) {
    echo "\nSKIP: no database reachable — the fixture-backed domain sections need MySQL/MariaDB.\n";
} else {
    $db->exec('DELETE FROM episodes WHERE episode_number BETWEEN ' . T_LO . ' AND ' . T_HI);

    // ── Episode coverage / metadata: invalid episode number, invalid/duplicate air dates ──
    section('episode coverage & metadata — invalid episode number, invalid & duplicate air dates');
    // A real duplicate episode_number cannot be produced through the
    // schema's own UNIQUE constraint (confirmed: INSERT fails with
    // "Duplicate entry" even under UNIQUE_CHECKS=0, since InnoDB still
    // enforces it) — RmDuplicateDetector's own comment says as much: it
    // is a defence against an import that ran with checks disabled, not
    // a state reachable through normal writes. Tested instead: a healthy
    // schema reports zero, which is the invariant that actually matters.
    $dupRows = (new RmDuplicateDetector($db))->fullCheck(50)['duplicate_episode_numbers']['rows'];
    check('a database with an intact UNIQUE constraint reports no duplicate episode numbers', $dupRows, []);

    // episode_number <= 0 is the out-of-range check; -1 is safely outside the T_LO..T_HI fixture range.
    $db->exec('DELETE FROM episodes WHERE episode_number = -1');
    $db->prepare('INSERT INTO episodes (episode_number, title, verification_required) VALUES (-1, ?, 1)')
       ->execute(['Invalid Fixture']);

    $futureEp = T_LO;
    $db->prepare('INSERT INTO episodes (episode_number, title, air_date, verification_required) VALUES (?,?,?,1)')
       ->execute([$futureEp, "Episode #$futureEp - Future", '2099-01-01']);

    $dateA = T_LO + 1; $dateB = T_LO + 2;
    $db->prepare('INSERT INTO episodes (episode_number, title, air_date, verification_required) VALUES (?,?,?,1)')
       ->execute([$dateA, "Episode #$dateA - Shared", '2026-05-01']);
    $db->prepare('INSERT INTO episodes (episode_number, title, air_date, verification_required) VALUES (?,?,?,1)')
       ->execute([$dateB, "Episode #$dateB - Shared", '2026-05-01']);

    $report = (new RmArchiveHealth($db))->scan();
    check('an out-of-range episode number is caught', (bool)issuesOf($report, 'INVALID_EPISODE_NUMBER'), true);
    check('  → and reported CRITICAL', issuesOf($report, 'INVALID_EPISODE_NUMBER')[0]['severity'] ?? null, 'CRITICAL');
    check('an implausible future air date is caught', (bool)issuesOf($report, 'INVALID_AIR_DATE'), true);
    check('  → and reported ERROR', issuesOf($report, 'INVALID_AIR_DATE')[0]['severity'] ?? null, 'ERROR');
    check('two real episodes sharing an air date is a WARNING, not an ERROR (can be a legitimate back-to-back special)',
          (bool)array_filter($report['issues'], fn($i) => $i['id'] === 'DUPLICATE_AIR_DATE' && $i['severity'] === 'WARNING'), true);
    check('metadata domain status escalates to at least ERROR with an invalid date present',
          in_array($report['domains']['metadata']['status'], ['ERROR', 'CRITICAL'], true), true);

    $db->exec('DELETE FROM episodes WHERE episode_number = -1');

    // ── Metadata: core vs optional ──
    section('metadata — core (title/air_date) is distinguished from optional enrichment');
    $coreMissingEp = T_LO + 3;
    $db->exec("DELETE FROM episodes WHERE episode_number = $coreMissingEp");
    $db->prepare('INSERT INTO episodes (episode_number, verification_required) VALUES (?, 1)')->execute([$coreMissingEp]);
    // No title, no air_date: core is missing. Synopsis/mission/etc missing too, but that must not be conflated with core.
    $report2 = (new RmArchiveHealth($db))->scan();
    check('a core-incomplete episode raises CORE_METADATA_MISSING', (bool)issuesOf($report2, 'CORE_METADATA_MISSING'), true);
    check('  → optional gaps (synopsis, mission, ...) are reported separately, at INFO, never escalating metadata status on their own',
          (bool)array_filter($report2['domains']['metadata']['issues'], fn($i) => $i['id'] === 'OPTIONAL_METADATA_MISSING' && $i['severity'] !== 'INFO'), false);

    // ── Research state — conflict, needs review, failed, stale, insufficient evidence ──
    section('research integrity — insufficient evidence is reported, never treated as failure');
    if (!rmResearchTablesExist()) {
        echo "  SKIP: research engine tables not installed — run database/research_engine.sql.\n";
    } else {
        $rsEp1 = T_LO + 10; $rsEp2 = T_LO + 11; $rsEp3 = T_LO + 12;
        foreach ([$rsEp1, $rsEp2, $rsEp3] as $ep) {
            $db->exec("DELETE FROM episodes WHERE episode_number = $ep");
            $db->exec("DELETE FROM research_state WHERE episode_number = $ep");
            $db->prepare('INSERT INTO episodes (episode_number, verification_required) VALUES (?, 1)')->execute([$ep]);
        }
        (new RmResearchState($db))->record($rsEp1, RmResearchState::CONFLICT, ['reason' => 'sources disagree']);
        (new RmResearchState($db))->record($rsEp2, RmResearchState::FAILED, ['reason' => 'no source reachable']);
        (new RmResearchState($db))->record($rsEp3, RmResearchState::NO_NEW, ['reason' => 'researched, nothing published']);

        $report3 = (new RmArchiveHealth($db))->scan();
        check('a conflicted episode raises RESEARCH_CONFLICTS (WARNING)', (bool)issuesOf($report3, 'RESEARCH_CONFLICTS'), true);
        check('a failed-research episode raises RESEARCH_FAILED (WARNING, not ERROR — usually transient)',
              issuesOf($report3, 'RESEARCH_FAILED')[0]['severity'] ?? null, 'WARNING');
        check('an insufficient-evidence episode (researched, no new data) is counted in the domain',
              $report3['domains']['research']['counts']['insufficient_evidence'] >= 1, true);
        check('  → but insufficient evidence never becomes an issue of its own (Section 5: not automatically an error)',
              (bool)array_filter($report3['issues'], fn($i) => str_contains(strtoupper($i['id']), 'INSUFFICIENT')), false);

        $db->exec('DELETE FROM research_state WHERE episode_number IN (' . $rsEp1 . ',' . $rsEp2 . ',' . $rsEp3 . ')');
    }

    // ── Thumbnails — VALID / MISSING / DUPLICATE / SUSPECT via classify() reuse ──
    section('thumbnail integrity — reuses PR14 classify(), duplicates are reported not deleted');
    $tEp1 = T_LO + 15;
    $db->exec("DELETE FROM episodes WHERE episode_number = $tEp1");
    $db->prepare('INSERT INTO episodes (episode_number, verification_required) VALUES (?, 1)')->execute([$tEp1]);
    // No thumbnail row at all — counts toward MISSING.
    $report4 = (new RmArchiveHealth($db))->scan();
    check('an episode with no thumbnail contributes to the MISSING count', $report4['domains']['thumbnails']['counts']['MISSING'] >= 1, true);
    check('a missing thumbnail is a WARNING, not an ERROR', (bool)array_filter($report4['issues'], fn($i) => $i['id'] === 'THUMBNAIL_MISSING' && $i['severity'] === 'WARNING'), true);
    check('thumbnail domain never deletes or modifies anything — scan is read-only',
          (int)$db->query("SELECT COUNT(*) FROM episodes WHERE episode_number = $tEp1")->fetchColumn(), 1);

    // ── Provenance / AI integrity ──
    section('provenance integrity — structural invariants the write-gates are supposed to guarantee');
    $hasAiLog = true;
    try { $db->query('SELECT 1 FROM ai_generation_log LIMIT 1'); } catch (Throwable $e) { $hasAiLog = false; }
    $hasDecisions = true;
    try { $db->query('SELECT 1 FROM research_decisions LIMIT 1'); } catch (Throwable $e) { $hasDecisions = false; }

    if (!$hasAiLog || !$hasDecisions) {
        echo "  SKIP: ai_generation_log / research_decisions not installed — run database/pr4_ai_diagnostics.sql.\n";
    } else {
        $pEp1 = T_LO + 20; $pEp2 = T_LO + 21; $pEp3 = T_LO + 22;
        foreach ([$pEp1, $pEp2, $pEp3] as $ep) {
            $db->exec("DELETE FROM episodes WHERE episode_number = $ep");
            $db->exec("DELETE FROM ai_generation_log WHERE episode_number = $ep");
            $db->exec("DELETE FROM research_decisions WHERE episode_number = $ep");
        }
        $db->prepare('INSERT INTO episodes (episode_number, synopsis, verification_required) VALUES (?,?,1)')
           ->execute([$pEp1, 'A perfectly normal synopsis.']);
        $db->prepare('INSERT INTO episodes (episode_number, verification_required) VALUES (?,1)')->execute([$pEp2]);
        $db->prepare('INSERT INTO episodes (episode_number, verification_required) VALUES (?,1)')->execute([$pEp3]);

        // Invariant violation: applied=1 despite REJECTED grounding — should be structurally impossible.
        $db->prepare(
            "INSERT INTO ai_generation_log (episode_number, field_name, decision, confidence, grounding_status, applied)
             VALUES (?, 'synopsis', 'GENERATE', 95, 'rejected', 1)"
        )->execute([$pEp1]);

        // Invariant violation: applied=1 on a decision that was never GENERATE.
        $db->prepare(
            "INSERT INTO ai_generation_log (episode_number, field_name, decision, confidence, grounding_status, applied)
             VALUES (?, 'synopsis', 'REQUEST_REVIEW', 80, 'passed', 1)"
        )->execute([$pEp2]);

        // Invariant violation: applied=1 on a decision that isn't UPDATE/FILL.
        $db->prepare(
            "INSERT INTO research_decisions (episode_number, field_name, decision, chosen_value, confidence, reason, applied)
             VALUES (?, 'title', 'REVIEW', 'Some Title', 70, 'weak evidence', 1)"
        )->execute([$pEp3]);

        // Invariant violation: applied=1 for synopsis, but the field is empty.
        $db->prepare(
            "INSERT INTO ai_generation_log (episode_number, field_name, decision, confidence, grounding_status, applied)
             VALUES (?, 'synopsis', 'GENERATE', 92, 'passed', 1)"
        )->execute([$pEp2]);

        $report5 = (new RmArchiveHealth($db))->scan();
        check('AI applied despite rejected grounding is caught, and CRITICAL',
              (issuesOf($report5, 'AI_APPLIED_DESPITE_REJECTED_GROUNDING')[0]['severity'] ?? null), 'CRITICAL');
        check('AI applied on a non-GENERATE decision is caught, and CRITICAL',
              (issuesOf($report5, 'AI_APPLIED_INVALID_DECISION')[0]['severity'] ?? null), 'CRITICAL');
        check('a decision applied despite being unsafe to auto-write (not UPDATE/FILL) is caught, and CRITICAL',
              (issuesOf($report5, 'DECISION_APPLIED_UNSAFE')[0]['severity'] ?? null), 'CRITICAL');
        check('AI applied but the field is empty now is caught, and ERROR (not CRITICAL — may be a later legitimate edit)',
              (issuesOf($report5, 'AI_APPLIED_BUT_FIELD_EMPTY')[0]['severity'] ?? null), 'ERROR');
        check('provenance domain escalates to CRITICAL when any invariant is violated',
              $report5['domains']['provenance']['status'], 'CRITICAL');
        check('nothing here was auto-repaired — the bad rows are still exactly as inserted',
              (int)$db->query("SELECT applied FROM ai_generation_log WHERE episode_number = $pEp1")->fetchColumn(), 1);

        $db->exec('DELETE FROM ai_generation_log WHERE episode_number IN (' . $pEp1 . ',' . $pEp2 . ')');
        $db->exec('DELETE FROM research_decisions WHERE episode_number = ' . $pEp3);

        // A clean database reports GOOD, not silently skipping the check.
        $db->exec("DELETE FROM ai_generation_log WHERE episode_number IN ($pEp1,$pEp2,$pEp3)");
        $db->exec("DELETE FROM research_decisions WHERE episode_number IN ($pEp1,$pEp2,$pEp3)");
        $cleanProvenance = (new RmArchiveHealth($db))->scan()['domains']['provenance'];
        check('a clean provenance state reports GOOD, not merely absent', $cleanProvenance['status'], 'GOOD');
    }

    // ── Read-only guarantee, exercised against real fixture data ──
    section('read-only guarantee — scanning never modifies the archive');
    $before = [
        (int)$db->query('SELECT COUNT(*) FROM episodes')->fetchColumn(),
        (int)$db->query('SELECT COUNT(*) FROM episodes WHERE episode_number BETWEEN ' . T_LO . ' AND ' . T_HI)->fetchColumn(),
    ];
    (new RmArchiveHealth($db))->scan();
    (new RmArchiveHealth($db))->scan(['fresh' => false]);
    $after = [
        (int)$db->query('SELECT COUNT(*) FROM episodes')->fetchColumn(),
        (int)$db->query('SELECT COUNT(*) FROM episodes WHERE episode_number BETWEEN ' . T_LO . ' AND ' . T_HI)->fetchColumn(),
    ];
    check('running the scan twice never changes the episode count', $before, $after);

    $db->exec('DELETE FROM episodes WHERE episode_number BETWEEN ' . T_LO . ' AND ' . T_HI);
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'PR16 VERIFIED' : 'PR16 PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
