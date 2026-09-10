<?php
// ============================================================
// tests/pr13.php — Automatic Latest Episode Detection + Research Queue.
//
// PR13 did NOT rebuild latest-episode detection or the research queue —
// RmLatestEpisode::detectDetailed() (PR9/PR10) and RmResearchState::
// buildQueue()/priority()/queueReasons() (PR11) already did almost all
// of it: aired vs upcoming vs disagreement vs insufficient evidence,
// bounded/prioritised/cooldown-respecting queueing, dry-run, safe write.
// tests/pr10.php, tests/pr11.php and tests/research.php already cover
// that ground and are not repeated here.
//
// This file covers the TWO real, narrow gaps PR13 found and fixed:
//
//   1. RmResearchState::buildQueue('new', ...) used to queue a bare
//      "$dbMax+1..$latest" RANGE — every number treated as existing
//      just because it was numerically next. It now requires the
//      caller's 'missing_aired' list: SPECIFIC episode numbers
//      RmLatestEpisode::detectDetailed() already independently verified
//      (a real air_date resolved through the full evidence pipeline).
//      Section below proves non-sequential evidence is respected (a gap
//      in the CONFIRMED numbers is never silently filled in) and that
//      omitting evidence entirely queues nothing beyond the archive.
//
//   2. RmScrapingEngine::plan() could reach insertEpisode() — which
//      falls back to a placeholder title and NULL air_date — for a
//      brand-new episode candidate whose only evidence was a field
//      OTHER than air_date (e.g. a lone location match). It now refuses
//      to treat such a candidate as real. Section below proves both
//      directions: no evidence for air_date -> refused, nothing
//      inserted; real air_date evidence -> proceeds normally, exactly
//      as it always did.
//
// Plus the priority() bands PR13 added for FAILED/REVIEW (previously
// falling through into low-confidence/stale undifferentiated).
//
// Offline. RM_SCRAPE_OFFLINE=1 refuses every non-loopback request, so
// the "no evidence" adapters in this file fail for a real reason (the
// network guard), not because nobody asked them — exactly what happens
// in production when a source is genuinely unreachable. DB-backed
// sections skip cleanly without MySQL/MariaDB.
//
//   php tests/pr13.php
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

// Dedicated range, untouched by any other suite (pr11.php: 1970-1989,
// integration.php: 1950-1965, research.php: 1900-1919).
const Q_LO = 1800, Q_HI = 1819;

$db = getDBSafe();

// ============================================================
section("buildQueue('new') — evidence required, never a bare range");

$state  = new RmResearchState();
$dbMax  = (new RmMissingData())->maxEpisode();

// No evidence supplied at all: must not invent anything past the archive.
$q0 = $state->buildQueue('new', ['missing_aired' => []]);
$beyond = array_filter(array_column($q0, 'episode'), fn($e) => $e > $dbMax);
check('no missing_aired given -> nothing beyond the archive max is queued', $beyond, []);

// Non-sequential evidence: EP dbMax+1 and dbMax+3 confirmed, dbMax+2 is
// NOT — the exact "do not assume every number between two values exists"
// case from the PR13 brief. dbMax+2 must never be silently filled in.
$q1 = $state->buildQueue('new', ['missing_aired' => [
    ['episode' => $dbMax + 1, 'air_date' => '2026-01-01', 'sources' => ['sbs', 'wikipedia']],
    ['episode' => $dbMax + 3, 'air_date' => '2026-01-15', 'sources' => ['fandom', 'tvmaze']],
]]);
$eps1 = array_column($q1, 'episode');
sort($eps1);
check('only the specifically-confirmed episodes are queued', $eps1, [$dbMax + 1, $dbMax + 3]);
check('the UN-confirmed number in between is never interpolated',
    in_array($dbMax + 2, $eps1, true), false);
check('a confirmed-missing-aired episode is priority 1',
    (array_values(array_filter($q1, fn($r) => $r['episode'] === $dbMax + 1)))[0]['priority'], 1);
check('its reason cites the confirming evidence, not a guess',
    str_contains((array_values(array_filter($q1, fn($r) => $r['episode'] === $dbMax + 1)))[0]['reasons'][0] ?? '', 'sbs'), true);

// Plain integers are accepted too (not every caller carries full evidence
// metadata), but still only the numbers actually given.
$q2 = $state->buildQueue('new', ['missing_aired' => [$dbMax + 1, $dbMax + 2]]);
check('plain-int missing_aired entries work the same way',
    (function($r){ sort($r); return $r; })(array_column($q2, 'episode')), [$dbMax + 1, $dbMax + 2]);

// Duplicate prevention: the same episode named twice collapses to one entry.
$q3 = $state->buildQueue('new', ['missing_aired' => [$dbMax + 1, $dbMax + 1, ['episode' => $dbMax + 1]]]);
check('duplicate entries in missing_aired collapse to one queue item', count($q3), 1);

// An entry at or below the archive max is not "new" — already exists,
// never re-added through this path. (Boundary value only — $dbMax-1
// isn't meaningful to assert against when the archive is near-empty.)
$q4 = $state->buildQueue('new', ['missing_aired' => [$dbMax > 0 ? $dbMax : 0]]);
check('entries at or below the archive max are ignored here', $q4, []);

// ============================================================
section('priority() — FAILED and REVIEW get explicit bands (PR13 §5)');

if ($db === null) {
    echo "SKIP: no database reachable — this section needs MySQL/MariaDB.\n";
} else {
    foreach (['episode_guests', 'episode_tags', 'thumbnails', 'episodes', 'research_state'] as $t) {
        try { $db->exec("DELETE FROM `$t` WHERE episode_number BETWEEN " . Q_LO . " AND " . Q_HI); } catch (Throwable $e) {}
    }
    $failEp = Q_LO; $reviewEp = Q_LO + 1;
    foreach ([$failEp, $reviewEp] as $ep) {
        $db->prepare("INSERT INTO episodes (episode_number, title, air_date, synopsis, verification_required)
                      VALUES (?, ?, '2026-01-01', 'Placeholder — has enough to not be a bare gap', 1)")
           ->execute([$ep, "Episode #$ep - Fixture"]);
    }
    $rState = new RmResearchState($db);
    $rState->record($failEp,   RmResearchState::FAILED,  ['reason' => 'Transport failure in the fixture']);
    $rState->record($reviewEp, RmResearchState::REVIEW,  ['reason' => 'Held for a human decision']);
    // record() sets a cool-down; clear it so isEligible()/priority() see
    // the state as it would look once that cool-down has genuinely passed.
    $rState->clearCoolDown($failEp);
    $rState->clearCoolDown($reviewEp);

    check('a research_failed episode (post cool-down) is P4', $rState->priority($failEp), 4);
    check('a researched_needs_review episode is P5',          $rState->priority($reviewEp), 5);
    // REVIEW is deliberately excluded from the auto-built queue —
    // isEligible() holds it for a human, whatever its priority band says.
    check('REVIEW is not auto-eligible even once "due"', $rState->isEligible($reviewEp), false);
    // FAILED, once its cool-down has passed, IS eligible again.
    check('FAILED becomes eligible again once its cool-down has passed', $rState->isEligible($failEp), true);

    foreach ([$failEp, $reviewEp] as $ep) {
        $db->exec("DELETE FROM episodes WHERE episode_number = $ep");
        $db->exec("DELETE FROM research_state WHERE episode_number = $ep");
    }
}

// ============================================================
section("ScrapingEngine::plan() — a new episode needs a real air_date, never a placeholder");

if ($db === null) {
    echo "SKIP: no database reachable — this section needs MySQL/MariaDB.\n";
} else {
    $cache = RmCache::instance();
    $noDateEp = Q_LO + 5;
    $db->exec("DELETE FROM episodes WHERE episode_number = $noDateEp");
    $cache->flush();

    // ONE source answers with a field that is NOT air_date — everything
    // else fails for a genuine reason (RM_SCRAPE_OFFLINE's network guard),
    // exactly like an unreachable source in production.
    $cache->set("http:mrm:ep:$noDateEp",
        '<html><body><div>Location: Namsan Tower, Seoul</div></body></html>' . str_repeat(' ', 700),
        300, 'page');

    $plan = rmEngine()->plan($noDateEp, ['fields' => ['location', 'air_date', 'title']]);
    check('no source resolved an air_date -> plan is marked failed, not inserted', $plan['failed'] ?? null, true);
    check('  → is_new is still true (it genuinely is a new number)', $plan['is_new'] ?? null, true);
    check('  → nothing is staged to apply', $plan['apply'] ?? null, []);
    check('  → the reason names the actual gap', str_contains((string)($plan['reason'] ?? ''), 'air date'), true);

    $applied = rmEngine()->apply($plan);
    check('apply() on a refused plan writes nothing', $applied['failed'] ?? null, true);
    $count = (int)$db->query("SELECT COUNT(*) FROM episodes WHERE episode_number = $noDateEp")->fetchColumn();
    check('no placeholder row exists in the database', $count, 0);

    // Complementary positive case: the SAME shape of episode, but this
    // time a source resolves a real air_date. The guard must not block
    // a genuine new episode with real evidence.
    $withDateEp = Q_LO + 6;
    $db->exec("DELETE FROM episodes WHERE episode_number = $withDateEp");
    $cache->flush();
    $year = rmYear($withDateEp);
    $table = '<table class="wikitable"><tr><th>Ep.</th><th>Airdate</th><th>Title</th></tr>'
           . '<tr><td>' . $withDateEp . '</td><td>2026-01-01</td><td>A Confirmed New Episode</td></tr></table>'
           . str_repeat('<!-- pad -->', 220);
    $cache->set('http:wiki:year:' . $year . ':' . md5("List of Running Man episodes ($year)"),
        json_encode(['parse' => ['text' => ['*' => $table]]]), 900, 'api');

    $plan2 = rmEngine()->plan($withDateEp, ['fields' => ['air_date', 'title']]);
    check('real air_date evidence -> plan is NOT refused', empty($plan2['failed']), true);
    check('  → air_date is staged to apply', $plan2['apply']['air_date'] ?? null, '2026-01-01');

    $applied2 = rmEngine()->apply($plan2);
    check('apply() writes the genuinely-confirmed new episode', empty($applied2['failed']), true);
    $count2 = (int)$db->query("SELECT COUNT(*) FROM episodes WHERE episode_number = $withDateEp")->fetchColumn();
    check('the confirmed episode now exists exactly once', $count2, 1);

    // Clean up only what this file created.
    $db->exec("DELETE FROM episodes WHERE episode_number IN ($noDateEp, $withDateEp)");
    $db->exec("DELETE FROM research_state WHERE episode_number IN ($noDateEp, $withDateEp)");
    $cache->flush();
}

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'PR13 VERIFIED' : 'PR13 FAILURES', $pass, $fail);
exit($fail === 0 ? 0 : 1);
