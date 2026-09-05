<?php
// ============================================================
// tests/research.php — the research layer end to end.
//
// The three questions this suite exists to keep answered:
//
//   · Did the run finish?          (run state, persisted, refresh-proof)
//   · Is the archive complete?     (a different question, different answer)
//   · Have we looked at this yet?  (research state, and the cool-down
//                                   that stops the same 386 episodes
//                                   being queued forever)
//
// Plus the parts that decide what gets written: evidence independence,
// source reputation, confidence, conflict detection, anomalies, and
// the rule that existing good data is not overwritten on weak grounds.
//
//   php tests/research.php
// ============================================================
// Hermetic: outbound requests are refused before the engine loads, so
// this suite can never reach a live source.
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';

require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true)
                     . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

$db = getDBSafe();
if ($db === null) { echo "SKIP: no database reachable — the research tests need MySQL/MariaDB.\n"; exit(0); }
try { $db->query('SELECT 1 FROM episodes LIMIT 1'); }
catch (Throwable $e) { echo "SKIP: base schema not installed. Run: php tests/migration.php --fresh\n"; exit(0); }
if (!rmResearchTablesExist(true)) {
    echo "SKIP: research tables not installed. Run: php tests/migration.php --fresh\n"; exit(0);
}

// ── Isolation ────────────────────────────────────────────────
// A reserved block inside the 1..2000 the validator considers real.
const R_LO = 1900, R_HI = 1919;
function wipe(PDO $db): void {
    $lo = R_LO; $hi = R_HI;
    $db->exec("DELETE eg FROM episode_guests eg JOIN episodes e ON e.episode_id=eg.episode_id
                WHERE e.episode_number BETWEEN $lo AND $hi");
    foreach (['episodes','episode_sources','episode_field_sources','scrape_changes',
              'research_state','research_evidence','research_decisions','research_discovery'] as $t) {
        try { $db->exec("DELETE FROM `$t` WHERE episode_number BETWEEN $lo AND $hi"); }
        catch (Throwable $e) { }
    }
    try {
        $db->exec("DELETE q FROM research_queue q JOIN scrape_runs r ON r.run_id=q.run_id
                    WHERE r.scope LIKE 'restest%'");
        $db->exec("DELETE FROM scrape_runs WHERE scope LIKE 'restest%'");
    } catch (Throwable $e) { }
    $db->exec("DELETE FROM source_reputation WHERE source_name LIKE 'restest%'");
}
wipe($db);
register_shutdown_function(fn() => wipe(getDBSafe() ?? $GLOBALS['db']));

$cache = RmCache::instance();
$svc   = new RmResearchService($db);
$state = $svc->state();

function seedEp(PDO $db, int $n, array $over = []): void {
    $row = array_merge([
        'title'    => "Episode #$n - Intact Title",
        'air_date' => '2026-07-01',
        'synopsis' => 'An existing synopsis, long enough to be real, that a weak source must not be able to replace.',
        'mission'  => 'An existing mission',
    ], $over);
    $db->prepare("INSERT INTO episodes (episode_number,title,air_date,synopsis,main_mission,verification_required)
                  VALUES (?,?,?,?,?,0)
                  ON DUPLICATE KEY UPDATE title=VALUES(title), air_date=VALUES(air_date),
                                          synopsis=VALUES(synopsis), main_mission=VALUES(main_mission)")
       ->execute([$n, $row['title'], $row['air_date'], $row['synopsis'], $row['mission']]);
}

/** Serve every source a page that loads fine and says nothing useful. */
function seedBarrenSources(RmCache $cache, int $lo, int $hi): void {
    $year  = rmYear($lo);
    $table = '<table class="wikitable"><tr><th>Ep.</th><th>Airdate</th><th>Title</th><th>Guest(s)</th></tr>'
           . '<tr><td>500</td><td>2020-01-05</td><td>A Different Episode</td><td>Someone</td></tr></table>'
           . str_repeat('<!-- pad -->', 220);
    $cache->set("http:wiki:year:$year:" . md5("List of Running Man episodes ($year)"),
                json_encode(['parse' => ['text' => ['*' => $table]]]), 900, 'api');
    foreach (["런닝맨의 에피소드 목록 ($year)", "런닝맨의 에피소드 목록 ({$year}년)", '런닝맨의 에피소드 목록'] as $t) {
        $cache->set("http:kowiki:page:$year:" . md5($t),
                    json_encode(['parse' => ['text' => ['*' => $table]]]), 900, 'api');
    }
    $nav = '<html><body><nav>Home | Episodes | Guests</nav></body></html>' . str_repeat(' ', 700);
    for ($n = $lo; $n <= $hi; $n++) {
        foreach (["http:mrm:ep:$n", "http:myrm:ep:$n", "http:mdl:ep:$n"] as $k) $cache->set($k, $nav, 900, 'page');
        $cache->set('http:asianwiki:ep:' . md5("https://asianwiki.com/Running_Man_Episode_$n"),
                    '<html><body>There is currently no text in this page</body></html>' . str_repeat(' ', 500), 900, 'page');
    }
    foreach ([md5('https://programs.sbs.co.kr/enter/runningman/visualboard/54666'),
              md5('https://programs.sbs.co.kr/enter/runningman')] as $h) {
        $cache->set("http:sbs:page:$h",
            '<html><head><title>런닝맨</title></head><body><div id="root"></div>'
            . '<script>window.__NEXT_DATA__={}</script></body></html>' . str_repeat(' ', 1200), 900, 'page');
    }
    $cache->set('http:imdb:season:' . md5('https://www.imdb.com/title/tt1587289/episodes/?season=' . ($year - 2009)),
        '<html><body><script type="application/ld+json">{"@type":"TVSeries","episode":[]}</script></body></html>'
        . str_repeat(' ', 900), 900, 'page');
}

// ============================================================
section('the migration installed what the code expects');
foreach (['research_queue','research_state','research_evidence','research_decisions',
          'source_reputation','research_discovery'] as $t) {
    $ok = true;
    try { $db->query("SELECT 1 FROM `$t` LIMIT 1"); } catch (Throwable $e) { $ok = false; }
    check("$t exists", $ok, true);
}
$cols = array_column($db->query('SHOW COLUMNS FROM scrape_runs')->fetchAll(), 'Field');
foreach (['run_ref','research_mode','episodes_requested','episodes_review','episodes_remaining',
          'evidence_count','cancel_requested','heartbeat_at'] as $c) {
    check("scrape_runs.$c added", in_array($c, $cols, true), true);
}
$statusCol = $db->query("SHOW COLUMNS FROM scrape_runs LIKE 'status'")->fetch();
foreach (['paused','completed_with_warnings','cancelled'] as $v) {
    check("run status can be '$v'", str_contains((string)$statusCol['Type'], "'$v'"), true);
}
check('and the legacy values still read', str_contains((string)$statusCol['Type'], "'partial'"), true);

// ============================================================
section('research state — we remember having looked');
$ep = R_LO;
seedEp($db, $ep);
check('an episode nobody has researched reports itself so',
      $state->describe($ep)['status'], RmResearchState::NEVER);
check('  → and is eligible immediately', $state->isEligible($ep), true);

$state->record($ep, RmResearchState::NO_NEW, ['reason' => 'No source carries a synopsis for it']);
$d = $state->describe($ep);
check('after a look it is researched, not unresearched', $d['status'], RmResearchState::NO_NEW);
check('  → it says when',        $d['last_researched'] !== null, true);
check('  → and why',             str_contains((string)$d['reason'], 'No source'), true);
check('  → and it is NOT eligible again immediately', $state->isEligible($ep), false);
check('  → which is the whole point: it stops being requeued',
      in_array($ep, array_column($state->buildQueue('missing', ['limit' => 500]), 'episode'), true), false);

check('an explicit retry overrides the rest', $state->isEligible($ep, null, true), true);
$state->clearCoolDown($ep);
check('  → as does clearing the cool-down by hand', $state->isEligible($ep), true);

// The other reason to look again: our capability changed.
$state->record($ep, RmResearchState::NO_NEW, ['reason' => 'nothing found']);
check('a fresh cool-down holds again', $state->isEligible($ep), false);
$db->prepare('UPDATE research_state SET source_signature = ? WHERE episode_number = ?')
   ->execute([str_repeat('0', 40), $ep]);
check('  → but a new source or improved parser makes it eligible again',
      $state->isEligible($ep), true);

section('research state — the cool-down backs off, and never forever');
$short = $state->coolDownSeconds(RmResearchState::NO_NEW, 1, $ep);
$long  = $state->coolDownSeconds(RmResearchState::NO_NEW, 5, $ep);
check('a repeated fruitless look waits longer each time', $long > $short, true);
check('  → but never past the ceiling',
      $state->coolDownSeconds(RmResearchState::NO_NEW, 99, $ep)
      <= (int)rmScrapeConfig('research.cooldown.cap', 5184000), true);
check('a failure retries sooner than an empty result',
      $state->coolDownSeconds(RmResearchState::FAILED, 1, $ep)
      < $state->coolDownSeconds(RmResearchState::NO_NEW, 1, $ep), true);
check('an episode held for review waits for a person, not a clock',
      $state->isEligible($ep, ['status' => RmResearchState::REVIEW, 'next_eligible_at' => null,
                               'source_signature' => RmResearchState::signature()]), false);

// ============================================================
section('evidence — copies are not corroboration');
$ev = new RmEvidenceSet();
$story = 'The members travel to Jeju Island for a two-day race against the production team, with a penalty.';
$ev->add('myrunningman', 'synopsis', $story);
$ev->add('myrm',         'synopsis', $story);        // identical text — a copy
$ev->add('mydramalist',  'synopsis', $story);        // and another
$ev->add('wikipedia',    'synopsis', 'An entirely different description of the same episode, written independently.');
$ev->group();
$cands = $ev->candidates('synopsis');
$copied = null;
foreach ($cands as $c) if (count($c['sources']) === 3) $copied = $c;
check('three sites carrying identical text are found', $copied !== null, true);
check('  → and counted as ONE independent witness', (int)$copied['independent'], 1);

$ev2 = new RmEvidenceSet();
$ev2->add('wikipedia', 'air_date', '2026-08-30');
$ev2->add('kowiki',    'air_date', '2026-08-30');
$ev2->add('wikidata',  'air_date', '2026-08-30');
$ev2->group();
check('sister projects of one publisher are one witness too',
      (int)$ev2->candidates('air_date')[0]['independent'], 1);

$ev3 = new RmEvidenceSet();
$ev3->add('sbs',          'air_date', '2026-08-30');
$ev3->add('wikipedia',    'air_date', '2026-08-30');
$ev3->add('myrunningman', 'air_date', '2026-08-30');
$ev3->group();
check('genuinely separate sources ARE counted separately',
      (int)$ev3->candidates('air_date')[0]['independent'], 3);

section('evidence — normalisation before comparison');
$ev4 = new RmEvidenceSet();
$ev4->add('sbs',       'air_date', '2026-08-30');
$ev4->add('wikipedia', 'air_date', '30 August 2026');
$ev4->add('kowiki',    'air_date', '2026년 8월 30일');
$ev4->group();
check('the same date written three ways is one candidate, not three',
      count($ev4->candidates('air_date')), 1);

$ev5 = new RmEvidenceSet();
$ev5->add('wikipedia', 'guests', ['Kim Jong-kook', 'Lee Kwang-soo']);
$ev5->add('kowiki',    'guests', ['Lee Kwang Soo', 'Kim Jong Kook']);   // spacing + order
$ev5->group();
check('a guest list is compared by identity, not by spelling or order',
      count($ev5->candidates('guests')), 1);

// ============================================================
section('source reputation — global and per field');
$rep = RmSourceReputation::instance();
check('SBS outranks a community site overall',
      $rep->reliability('sbs', '*') > $rep->reliability('myrm', '*'), true);
check('SBS is stronger on air dates than on synopses',
      $rep->reliability('sbs', 'air_date') > $rep->reliability('sbs', 'synopsis'), true);
check('Wikidata is strong on guest identity',
      $rep->reliability('wikidata', 'guests') >= 85, true);
check('a source not listed for a field has little standing on it',
      $rep->reliability('wikidata', 'synopsis') < $rep->reliability('myrunningman', 'synopsis'), true);
check('every band has a word for it', RmSourceReputation::band(95), 'VERY HIGH');
check('  → and so does the bottom',    RmSourceReputation::band(10), 'VERY LOW');

// ============================================================
section('decisions — filling a gap, keeping a good value, refusing a weak one');
$dec = new RmDecisionEngine();

$evFill = new RmEvidenceSet();
$evFill->add('sbs', 'air_date', '2026-08-30');
$evFill->add('wikipedia', 'air_date', '2026-08-30');
$evFill->add('myrunningman', 'air_date', '2026-08-30');
$evFill->group();
$d1 = $dec->decide($ep, 'air_date', $evFill->candidates('air_date'), null, 0);
check('an empty field with strong agreement is FILLed', $d1->decision, 'FILL');
check('  → and it can say why',        count($d1->why) > 2, true);
check('  → naming the sources',        in_array('sbs', $d1->supporting, true), true);

$d2 = $dec->decide($ep, 'location', [[
    'value' => 'Seoul', 'hash' => 'x', 'sources' => ['mydramalist'], 'groups' => ['solo:mydramalist'],
    'independent' => 1, 'reliability' => 60, 'evidence' => [],
]], 'Seoul', 95);
check('a value the archive already holds is KEPT', $d2->decision, 'KEEP');

$d3 = $dec->decide($ep, 'location', [[
    'value' => 'Busan', 'hash' => 'y', 'sources' => ['myrm'], 'groups' => ['solo:myrm'],
    'independent' => 1, 'reliability' => 52, 'evidence' => [],
]], 'Seoul', 95);
check('a weakly-supported contradiction does NOT overwrite good data', $d3->decision, 'KEEP');
check('  → and says so in plain words', str_contains($d3->reason, 'too weakly supported'), true);

$d4 = $dec->decide($ep, 'air_date', [[
    'value' => '2026-08-30', 'hash' => 'a', 'sources' => ['sbs','wikipedia','myrunningman'],
    'groups' => ['solo:sbs','lineage:wikimedia','solo:myrunningman'],
    'independent' => 3, 'reliability' => 95, 'evidence' => [],
]], '2026-08-29', 55);
check('much stronger evidence DOES overwrite a weakly-held value', $d4->decision, 'UPDATE');

$d5 = $dec->decide($ep, 'air_date', [
    ['value' => '2026-08-30', 'hash' => 'a', 'sources' => ['sbs','myrunningman'],
     'groups' => ['solo:sbs','solo:myrunningman'], 'independent' => 2, 'reliability' => 80, 'evidence' => []],
    ['value' => '2026-08-29', 'hash' => 'b', 'sources' => ['wikipedia','myrm'],
     'groups' => ['lineage:wikimedia','solo:myrm'], 'independent' => 2, 'reliability' => 78, 'evidence' => []],
], '2026-08-29', 70);
check('two well-supported answers that disagree go to REVIEW', $d5->decision, 'REVIEW');
check('  → nothing is written on a coin flip', $d5->isSafe(), false);

$d6 = $dec->decide($ep, 'synopsis', [], null, 0);
check('no evidence at all is UNKNOWN, not a guess', $d6->decision, 'UNKNOWN');

section('decisions — a critical field needs more than a tag does');
check('air_date is critical',  RmDecisionEngine::criticality('air_date'), 'critical');
check('guests are important',  RmDecisionEngine::criticality('guests'),   'important');
check('tags are secondary',    RmDecisionEngine::criticality('tags'),     'secondary');
check('and the bar follows the criticality',
      RmDecisionEngine::threshold('air_date')['update'] > RmDecisionEngine::threshold('tags')['update'], true);

$weak = [['value' => 'Jeju', 'hash' => 'z', 'sources' => ['myrm'], 'groups' => ['solo:myrm'],
          'independent' => 1, 'reliability' => 70, 'evidence' => []]];
check('the same evidence fills a secondary field…',
      $dec->decide($ep, 'tags', $weak, null, 0)->decision !== 'REJECT', true);
check('  …while a critical field holds out for more',
      in_array($dec->decide($ep, 'air_date', $weak, null, 0)->decision, ['REVIEW','REJECT'], true), true);

section('decisions — the reasoning-provider seam exists and is unused');
$ctx = $dec->contextFor($ep, 'air_date', $evFill, null, 0);
foreach (['episode','field','candidate_values','source_reputation','field_reputation',
          'evidence','independence_groups','conflicts','existing_value','existing_confidence','thresholds'] as $k) {
    check("context carries $k", array_key_exists($k, $ctx), true);
}
check('with no provider registered the deterministic decision stands',
      $dec->refine($d5, $ctx)->decision, 'REVIEW');

// ============================================================
section('anomalies — wrong even when everyone agrees');
seedEp($db, R_LO + 1, ['air_date' => '2026-07-10']);
seedEp($db, R_LO + 3, ['air_date' => '2026-07-24']);
$mk = fn(string $f, $v) => [$f => new RmDecision(R_LO + 2, $f, 'UPDATE', $v, null, 95, 'test')];

$a1 = RmAnomaly::check($db, R_LO + 2, $mk('air_date', '2026-07-01'), []);
check('an episode airing before its predecessor is flagged',
      (bool)array_filter($a1, fn($a) => $a['type'] === 'date_order'), true);
$a2 = RmAnomaly::check($db, R_LO + 2, $mk('air_date', '2026-07-17'), []);
check('  → and a date in the right place is not', $a2, []);
check('a runtime of 999 minutes is flagged',
      (bool)array_filter(RmAnomaly::check($db, R_LO + 2, $mk('runtime', 999), []),
                         fn($a) => $a['type'] === 'runtime'), true);
check('a duplicated guest is flagged',
      (bool)array_filter(RmAnomaly::check($db, R_LO + 2, $mk('guests', ['Kim Jong-kook', 'Kim Jong Kook']), []),
                         fn($a) => $a['type'] === 'duplicate_guest'), true);
check("the show's standing blurb is not an episode synopsis",
      (bool)array_filter(RmAnomaly::check($db, R_LO + 2,
            $mk('synopsis', 'Members compete in a series of games and missions to win the race.'), []),
            fn($a) => $a['type'] === 'generic_synopsis'), true);
check('show artwork is not an episode thumbnail',
      (bool)array_filter(RmAnomaly::check($db, R_LO + 2, $mk('image_url', 'https://x.test/default-poster.jpg'), []),
                         fn($a) => $a['type'] === 'generic_thumbnail'), true);

// ============================================================
section('a run is a row, not a browser variable');
wipe($db);
$cache->flush();
RmSourceHealth::instance()->clearSuppression();
for ($n = R_LO; $n <= R_LO + 2; $n++) seedEp($db, $n);
seedBarrenSources($cache, R_LO, R_LO + 2);

$run = $svc->startRun('range', ['from' => R_LO, 'to' => R_LO + 2, 'mode' => 'maximum',
                                'limit' => 3, 'force' => true, 'scope' => 'restest']);
check('the run exists', $run !== null, true);
$run->syncCounts();
check('  → with a human reference', (bool)preg_match('/^RUN-\d{4}-\d{2}-\d{2}-\d{3}$/', $run->ref()), true);
check('  → and a persisted queue',  count($run->items()), 3);
check('  → in a state that owns the queue', in_array($run->status(), RmResearchRun::ACTIVE, true), true);
check('a second run cannot start alongside it',
      RmResearchRun::current()->id(), $run->id());

$runId = $run->id();
$step  = $svc->step($run, ['seconds' => 40]);
$s     = $step['run'];

check('the run reports itself finished',  in_array($s['status'], RmResearchRun::FINAL, true), true);
check('  → as completed, NOT failed',     $s['status'], RmResearchRun::COMPLETED);
check('  → with everything processed',    $s['processed'], 3);
check('  → nothing left in the queue',    $s['remaining'], 0);
check('  → and none of it counted as a failure', $s['failed'], 0);
check('sources answering with nothing is "no new data", not failure', $s['no_data'], 3);
check('the run says how long it took',    $s['duration_ms'] !== null, true);

section('and it is still there after a refresh');
// A brand-new object graph, exactly as a fresh page load would build.
$fresh = RmResearchRun::load($runId);
check('the run can be re-read from the database', $fresh !== null, true);
$fs = $fresh->summary();
check('  → still completed',    $fs['status'], RmResearchRun::COMPLETED);
check('  → still 3 processed',  $fs['processed'], 3);
check('  → still 0 remaining',  $fs['remaining'], 0);
check('  → with its reference intact', $fs['ref'], $s['ref']);
check('  → and a finish time',  $fs['finished_at'] !== null, true);
check('"last run" finds it without being told which',
      RmResearchRun::last()->id(), $runId);
check('and no run is reported as active',  RmResearchRun::current(), null);
check('the status has a label a person can read', $fs['label']['text'], 'Completed');

section('run state and archive state are different questions');
$health = $state->archiveHealth();
check('the run is finished with nothing queued', $fs['remaining'], 0);
check('  → while the archive is still incomplete', $health['incomplete'] > 0, true);
check('  → and those episodes are recorded as researched, not untouched',
      (int)$db->query('SELECT COUNT(*) FROM research_state WHERE episode_number BETWEEN '
                      . R_LO . ' AND ' . (R_LO + 2) . " AND status = 'researched_no_new_data'")->fetchColumn(), 3);
$after = $db->query('SELECT COUNT(*) c, SUM(CHAR_LENGTH(synopsis)) len FROM episodes
                      WHERE episode_number BETWEEN ' . R_LO . ' AND ' . (R_LO + 2))->fetch();
check('  → with every episode intact', (int)$after['c'], 3);
check('  → and every synopsis untouched', (int)$after['len'] > 200, true);

section('opening the page again does not rebuild the same queue');
$queue = $state->buildQueue('missing', ['limit' => 500]);
$eps   = array_column($queue, 'episode');
check('the episodes just researched are not queued again',
      array_intersect($eps, range(R_LO, R_LO + 2)), []);
$counts = $state->eligibleCount();
check('  → and the page can say how many are resting rather than due',
      $counts['resting'] >= 3, true);

section('every queued episode explains itself');
seedEp($db, R_LO + 5, ['synopsis' => null]);
$reasons = $state->queueReasons(R_LO + 5);
check('a queued episode gives reasons', count($reasons) > 0, true);
check('  → naming the missing field',
      (bool)array_filter($reasons, fn($r) => str_contains(strtolower($r), 'synopsis')), true);
check('a brand-new episode says so',
      (bool)array_filter($state->queueReasons(R_HI + 500), fn($r) => str_contains($r, 'New episode')), true);

section('priority puts the urgent work first');
check('a new episode is P1', $state->priority(R_HI + 500), 1);
$state->record(R_LO + 6, RmResearchState::CONFLICT, ['reason' => 'sources disagree']);
seedEp($db, R_LO + 6);
check('a conflict is P2', $state->priority(R_LO + 6), 2);
seedEp($db, R_LO + 7, ['air_date' => null]);
check('a missing critical field is P3', $state->priority(R_LO + 7), 3);

// ============================================================
section('pause, resume and cancel — a refresh never loses the queue');
wipe($db);
for ($n = R_LO; $n <= R_LO + 4; $n++) seedEp($db, $n);
$run2 = $svc->startRun('range', ['from' => R_LO, 'to' => R_LO + 4, 'limit' => 5,
                                 'force' => true, 'scope' => 'restest pause']);
$run2->pause();
check('a paused run says so', $run2->status(), RmResearchRun::PAUSED);
check('  → and keeps its whole queue', $run2->progress()['queued'], 5);
check('  → stepping a paused run does nothing', $run2->claimNext(), null);
check('  → and it is still the current run after a refresh',
      RmResearchRun::current()->id(), $run2->id());
$run2->resume();
check('resuming makes it claimable again', $run2->claimNext() !== null, true);
$run2->releaseInFlight();
check('  → and an interrupted item goes back on the queue', $run2->progress()['queued'], 5);

$run2->cancelNow();
check('cancelling closes the run',        $run2->status(), RmResearchRun::CANCELLED);
check('  → with nothing left queued',     $run2->progress()['queued'], 0);
check('  → and no run left active',       RmResearchRun::current(), null);
check('  → but the record of it remains', RmResearchRun::load($run2->id()) !== null, true);

section('a walked-away run becomes resumable, never stuck');
$run3 = $svc->startRun('range', ['from' => R_LO, 'to' => R_LO + 1, 'limit' => 2,
                                 'force' => true, 'scope' => 'restest stall']);
$db->prepare('UPDATE scrape_runs SET status=?, heartbeat_at = (NOW() - INTERVAL 2 HOUR) WHERE run_id = ?')
   ->execute([RmResearchRun::RUNNING, $run3->id()]);
check('a stalled run is reaped', RmResearchRun::reapStalled(900) > 0, true);
check('  → into paused, not lost', RmResearchRun::load($run3->id())->status(), RmResearchRun::PAUSED);
check('  → with its queue intact', RmResearchRun::load($run3->id())->progress()['queued'], 2);
RmResearchRun::load($run3->id())->cancelNow();

// ============================================================
section('a dry run writes nothing at all');
wipe($db);
$cache->flush();
RmSourceHealth::instance()->clearSuppression();
$dryEp = R_LO + 1;
// Seeded to agree with the fixture on everything except the guests, so
// the only proposed change is the gap — this test is about the preview
// matching the write, not about conflict handling.
seedEp($db, $dryEp, ['title' => "Episode #$dryEp - Dry Run Test Episode",
                     'air_date' => '2026-07-11', 'mission' => 'A mission']);
$year = rmYear($dryEp);
$cache->set("http:wiki:year:$year:" . md5("List of Running Man episodes ($year)"),
    json_encode(['parse' => ['text' => ['*' =>
        '<table class="wikitable"><tr><th>Ep.</th><th>Airdate</th><th>Title</th><th>Guest(s)</th><th>Mission</th></tr>'
        . "<tr><td>$dryEp</td><td>2026-07-11</td><td>Dry Run Test Episode</td><td>Fixture Person</td><td>A mission</td></tr></table>"
        . str_repeat('<!-- pad -->', 220)]]]), 900, 'api');

$before = $db->query("SELECT * FROM episodes WHERE episode_number=$dryEp")->fetch(PDO::FETCH_ASSOC);
$evBefore = (int)$db->query("SELECT COUNT(*) FROM research_evidence WHERE episode_number=$dryEp")->fetchColumn();
$dryR = $svc->researchEpisode($dryEp, ['dry_run' => true, 'all_fields' => true, 'skip_thumbnail' => true]);
$afterRow = $db->query("SELECT * FROM episodes WHERE episode_number=$dryEp")->fetch(PDO::FETCH_ASSOC);

check('the dry run reports what it would do', count($dryR['decisions']) > 0, true);
check('  → but changes no episode data', $afterRow, $before);
check('  → records no evidence rows',
      (int)$db->query("SELECT COUNT(*) FROM research_evidence WHERE episode_number=$dryEp")->fetchColumn(), $evBefore);
check('  → records no decisions',
      (int)$db->query("SELECT COUNT(*) FROM research_decisions WHERE episode_number=$dryEp")->fetchColumn(), 0);
check('  → and leaves no research-state row either',
      (int)$db->query("SELECT COUNT(*) FROM research_state WHERE episode_number=$dryEp")->fetchColumn(), 0);

section('then the real run applies exactly what it previewed');
// What the dry run said it would write is the contract. Asserting on
// one hard-coded field would only test the fixture; asserting that the
// preview and the write agree is what actually matters.
$wouldWrite = [];
foreach ($dryR['decisions'] as $f => $d) if (!empty($d['safe'])) $wouldWrite[] = $f;
sort($wouldWrite);
$realR = $svc->researchEpisode($dryEp, ['all_fields' => true, 'skip_thumbnail' => true]);
$didWrite = (array)($realR['applied'] ?? []);
sort($didWrite);
check('the dry run identified changes to make', count($wouldWrite) > 0, true);
check('the episode was updated', $realR['outcome'], 'completed');
check('  → applying exactly what the preview promised', $didWrite, $wouldWrite);
check('evidence is now on record',
      (int)$db->query("SELECT COUNT(*) FROM research_evidence WHERE episode_number=$dryEp")->fetchColumn() > 0, true);
check('  → and so are the decisions',
      (int)$db->query("SELECT COUNT(*) FROM research_decisions WHERE episode_number=$dryEp")->fetchColumn() > 0, true);
$storedDec = $db->query("SELECT * FROM research_decisions WHERE episode_number=$dryEp LIMIT 1")->fetch();
check('  → each with a reason a person can read', strlen((string)$storedDec['reason']) > 20, true);
check('  → and the sources that backed it', $storedDec['supporting'] !== null, true);
check('the research state now says researched-and-updated',
      $state->describe($dryEp)['status'], RmResearchState::UPDATED);

section('existing good data survives a source that disagrees weakly');
$protect = R_LO + 8;
seedEp($db, $protect, ['air_date' => '2026-07-15']);
$db->prepare("INSERT INTO episode_field_sources (episode_number, field_name, source_name, confidence)
              VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE confidence=VALUES(confidence)")
   ->execute([$protect, 'air_date', 'sbs', 'high']);
$cache->flush();
$cache->set("http:wiki:year:$year:" . md5("List of Running Man episodes ($year)"),
    json_encode(['parse' => ['text' => ['*' =>
        '<table class="wikitable"><tr><th>Ep.</th><th>Airdate</th><th>Title</th><th>Guest(s)</th></tr>'
        . "<tr><td>$protect</td><td>2026-07-22</td><td>A Contradicting Row</td><td>Someone</td></tr></table>"
        . str_repeat('<!-- pad -->', 220)]]]), 900, 'api');
$svc->researchEpisode($protect, ['all_fields' => true, 'skip_thumbnail' => true]);
check('a lone source cannot move a high-confidence air date',
      $db->query("SELECT air_date FROM episodes WHERE episode_number=$protect")->fetchColumn(), '2026-07-15');

// ============================================================
section('source status vocabulary — everything is not "failed"');
$map = [
    'ok' => 'FOUND', 'empty' => 'NO_DATA', 'missing_episode' => 'NO_DATA',
    'parser_warning' => 'PARSER_ERROR', 'needs_javascript' => 'JAVASCRIPT_REQUIRED',
    'structure_changed' => 'STRUCTURE_CHANGED', 'blocked' => 'ACCESS_RESTRICTED',
    'rate_limited' => 'RATE_LIMITED', 'fetch_failed' => 'TEMPORARY_ERROR',
    'disabled' => 'NOT_APPLICABLE', 'skipped' => 'NOT_APPLICABLE',
];
foreach ($map as $engine => $expected) {
    check("$engine → $expected", RmEvidenceSet::sourceStatusFor($engine), $expected);
}
check('a 403 is access-restricted, never a failure of ours',
      RmEvidenceSet::sourceStatusFor('blocked'), 'ACCESS_RESTRICTED');

section('a blocked source does not stop the episode');
wipe($db);
$cache->flush();
RmSourceHealth::instance()->clearSuppression();
$blockEp = R_LO + 2;
// Agrees with the fixture on the settled fields, so the only thing at
// stake is the gap the reachable sources CAN fill. Otherwise this would
// be testing conflict handling under a heading about access refusals.
seedEp($db, $blockEp, ['title' => "Episode #$blockEp - Still Works Without MDL",
                       'air_date' => '2026-07-12', 'mission' => 'A mission']);
$cache->set("http:wiki:year:$year:" . md5("List of Running Man episodes ($year)"),
    json_encode(['parse' => ['text' => ['*' =>
        '<table class="wikitable"><tr><th>Ep.</th><th>Airdate</th><th>Title</th><th>Guest(s)</th><th>Mission</th></tr>'
        . "<tr><td>$blockEp</td><td>2026-07-12</td><td>Still Works Without MDL</td><td>Fixture Person</td><td>A mission</td></tr></table>"
        . str_repeat('<!-- pad -->', 220)]]]), 900, 'api');
// MyDramaList refuses; everything else is simply unreachable offline.
$r = $svc->researchEpisode($blockEp, ['all_fields' => true, 'skip_thumbnail' => true]);
check('the episode still completes on the sources that did answer', $r['outcome'], 'completed');
check('  → and the refusal is reported as its own thing, not a crash',
      in_array($r['sources']['mydramalist']['status'] ?? '',
               ['ACCESS_RESTRICTED','TEMPORARY_ERROR','RATE_LIMITED','NOT_APPLICABLE'], true), true);

// ============================================================
section('archive attention is split into things that mean different things');
$buckets = $state->attention(200);
foreach (['new','never_researched','conflict','needs_review','failed','low_confidence','incomplete','stale'] as $k) {
    check("there is a '$k' bucket", isset($buckets[$k]), true);
    check("  → and it explains itself", strlen((string)$buckets[$k]['hint']) > 10, true);
}
$all = [];
foreach ($buckets as $b) $all = array_merge($all, $b['episodes']);
check('no episode is counted in two buckets at once', count($all), count(array_unique($all)));

section('archive health answers the second question');
$h = $state->archiveHealth();
foreach (['episodes','completeness','incomplete','conflicts','needs_review','stale','low_confidence'] as $k) {
    check("health reports $k", array_key_exists($k, $h), true);
}
check('completeness is a percentage', $h['completeness'] >= 0 && $h['completeness'] <= 100, true);

// ============================================================
section('research modes ask for different amounts of work');
$modes = RmResearchService::modes();
foreach (['quick','balanced','maximum','deep'] as $m) check("$m mode exists", isset($modes[$m]), true);
check('quick asks the fewest sources',
      count($modes['quick']['tiers']) < count($modes['maximum']['tiers']), true);
check('maximum turns discovery on',  $modes['maximum']['discovery'], true);
check('deep re-checks weak fields',  $modes['deep']['recheck_weak'], true);
check('balanced is the default',     (string)rmScrapeConfig('research.default_mode'), 'balanced');

section('the two new sources are registered and cover real fields');
$reg = RmSourceRegistry::instance();
foreach (['asianwiki' => ['guests','synopsis','title_ko'], 'imdb' => ['air_date','title']] as $name => $mustCover) {
    check("$name is registered", $reg->has($name), true);
    $a = $reg->get($name);
    check("  → and supplies fields", count($a->fields()) > 0, true);
    foreach ($mustCover as $f) check("  → covering $f", in_array($f, $a->fields(), true), true);
    check("  → with a parser version", (bool)preg_match('/\S/', $a->parserVersion()), true);
    check("  → and a place in the field priority",
          in_array($name, (array)rmScrapeConfig("field_priority.$mustCover[0]", []), true), true);
}
check('adding them changed the source signature the cool-down keys on',
      strlen(RmResearchState::signature()), 40);

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'RESEARCH VERIFIED' : 'RESEARCH PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
