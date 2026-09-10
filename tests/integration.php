<?php
// ============================================================
// tests/integration.php — the engine end to end against a real
// database, with the network replaced by fixtures.
//
// Covers the write path (insert, update, provenance, change log),
// the incremental scraping modes and how much work each actually
// does, dry-run safety, and cron recovery: locking, interruption,
// partial failure and total failure.
//
// Needs MySQL/MariaDB. Skips cleanly without one, so CI can run the
// rest of the suite unattended.
//
//   php tests/integration.php [--fresh]
// ============================================================
// Hermetic by construction: outbound requests are disabled before the
// engine is loaded, so this suite can never reach a live source. Test
// fixtures are served from loopback, which stays permitted.
putenv('RM_SCRAPE_OFFLINE=1');
$_ENV['RM_SCRAPE_OFFLINE'] = '1';

require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';

$pass = 0; $fail = 0;
function check(string $name, $actual, $expected = true): void {
    global $pass, $fail;
    $ok = is_array($expected) ? ($actual == $expected) : ($actual === $expected);
    if ($ok) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n       expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n"; }
}
function section(string $s): void { echo "\n── " . strtoupper($s) . " ──\n"; }

$db = getDBSafe();
if ($db === null) { echo "SKIP: no database reachable — integration tests need MySQL/MariaDB.\n"; exit(0); }
try { $db->query('SELECT 1 FROM episodes LIMIT 1'); }
catch (Throwable $e) { echo "SKIP: base schema not installed. Run: php tests/migration.php --fresh\n"; exit(0); }
if (!rmScrapingTablesExist()) { echo "SKIP: scraping tables not installed. Run: php tests/migration.php --fresh\n"; exit(0); }

// ── Isolate: this test owns a reserved block near the top of the
// valid episode range. It must stay INSIDE 1..2000, because the
// parser and validator legitimately reject anything outside that as
// garbage — a test that ignores that bound is testing nothing.
const TEST_LO = 1950, TEST_HI = 1965;
function wipeTestData(PDO $db): void {
    $db->exec("DELETE eg FROM episode_guests eg JOIN episodes e ON e.episode_id=eg.episode_id
                WHERE e.episode_number BETWEEN " . TEST_LO . " AND " . TEST_HI);
    $db->exec("DELETE et FROM episode_tags et JOIN episodes e ON e.episode_id=et.episode_id
                WHERE e.episode_number BETWEEN " . TEST_LO . " AND " . TEST_HI);
    foreach (['episodes','thumbnails','episode_sources','episode_field_sources','scrape_changes',
              'episode_alt_titles','thumbnail_meta','review_flags'] as $t) {
        try { $db->exec("DELETE FROM `$t` WHERE episode_number BETWEEN " . TEST_LO . " AND " . TEST_HI); }
        catch (Throwable $e) { /* table may not carry episode_number */ }
    }
    $db->exec("DELETE FROM guest_aliases WHERE alias_raw LIKE 'Fixture %'");
    $db->exec("DELETE FROM guests WHERE name_romanized LIKE 'Fixture %'");
    $db->exec("DELETE FROM scrape_runs WHERE scope LIKE 'integration%'");
}
wipeTestData($db);
register_shutdown_function(fn() => wipeTestData(getDBSafe() ?? $GLOBALS['db']));

// Source health and the negative cache PERSIST between runs — that is the
// point of them in production, where a source that failed an hour ago
// should not be hammered again. A test that inherits that state is
// measuring the cool-down instead of the engine, so start from a known
// clean slate and let each phase re-establish it deliberately.
RmSourceHealth::instance()->clearSuppression();
RmCache::instance()->flush();

// ── Fixtures: the sources, without the network ────────────────
$cache = RmCache::instance();
$cache->flush();

function seedEpisode(int $ep, array $opt = []): void {
    $cache = RmCache::instance();
    $title = $opt['title'] ?? "Jeju Island Race Part " . ($ep - TEST_LO + 1);
    $date  = $opt['date']  ?? '2026-08-' . str_pad((string)(10 + ($ep - TEST_LO)), 2, '0', STR_PAD_LEFT);

    $cache->set("http:mrm:ep:$ep", '<html><head>'
        . '<meta property="og:title" content="Running Man Episode #' . $ep . ' - ' . $title . '">'
        . '<meta property="og:image" content="https://fixture.test/thumbs/ep' . $ep . '.jpg">'
        . '<meta property="og:description" content="The members travel to Jeju Island for a two-day race against the production team, with the losing team facing a penalty.">'
        . '</head><body><div>Location: Jeju, South Korea</div><div>Broadcast Date: ' . $date . '</div>'
        . '<a href="/tags/travel">Travel</a><a href="/tags/overseas">Overseas</a>'
        . '</body></html>' . str_repeat(' ', 700), 900, 'page');

    $cache->set("http:myrm:ep:$ep", '<html><head>'
        . '<meta property="og:title" content="Episode #' . $ep . ' - ' . $title . '">'
        . '<meta property="og:description" content="The members travel to Jeju Island for a two-day race against the production team.">'
        . '</head><body><div>Broadcast Date: ' . $date . '</div>'
        . '<div>Guests: Fixture Kwang-soo, Fixture So-min</div></body></html>' . str_repeat(' ', 500), 900, 'page');
}

function seedWikiYear(int $year, array $episodes): void {
    $rows = '';
    foreach ($episodes as $ep => $meta) {
        $rows .= '<tr><td>' . $ep . '</td><td>' . $meta['date'] . '</td><td>' . $meta['title'] . '</td>'
               . '<td>' . implode('<br>', $meta['guests']) . '</td>'
               . '<td>' . $meta['mission'] . '</td><td>' . $meta['teams'] . '</td><td>' . $meta['results'] . '</td></tr>';
    }
    $table = '<table class="wikitable"><tr><th>Ep.</th><th>Airdate</th><th>Title</th><th>Guest(s)</th>'
           . '<th>Mission</th><th>Teams</th><th>Results</th></tr>' . $rows . '</table>' . str_repeat('<!-- pad -->', 220);
    RmCache::instance()->set('http:wiki:year:' . $year . ':' . md5("List of Running Man episodes ($year)"),
        json_encode(['parse' => ['text' => ['*' => $table]]]), 900, 'api');
}

$wikiRows = [];
for ($ep = TEST_LO; $ep <= TEST_LO + 4; $ep++) {
    seedEpisode($ep);
    $wikiRows[$ep] = [
        'date'    => '2026-08-' . str_pad((string)(10 + ($ep - TEST_LO)), 2, '0', STR_PAD_LEFT),
        'title'   => 'Jeju Island Race Part ' . ($ep - TEST_LO + 1),
        'guests'  => ['Fixture Kwang-soo', 'Fixture So-min', 'Fixture Ji-hyo'],
        'mission' => 'Two-day Jeju survival race',
        'teams'   => 'Blue Team vs Red Team',
        'results' => 'Blue Team wins',
    ];
}
seedWikiYear(rmYear(TEST_LO), $wikiRows);

$engine = new RmScrapingEngine();
$md     = new RmMissingData($db);
$prov   = new RmProvenance($db);
$EP     = TEST_LO;

// ============================================================
section('write path — a new episode');
$r = $engine->syncEpisode($EP, ['all_fields' => true, 'skip_thumbnail' => true]);
check('sync succeeded', empty($r['failed']), true);
check('recognised as new', !empty($r['is_new']), true);
$row = $db->query("SELECT * FROM episodes WHERE episode_number=$EP")->fetch();
check('episode row created', (bool)$row, true);
check('title stored', $row['title'] ?? null, "Episode #$EP - Jeju Island Race Part 1");
check('air date stored', $row['air_date'] ?? null, '2026-08-10');
check('mission stored', $row['main_mission'] ?? null, 'Two-day Jeju survival race');
check('teams stored', $row['teams'] ?? null, 'Blue Team vs Red Team');
check('location resolved and linked', $db->query("SELECT name FROM locations WHERE location_id=" . (int)$row['location_id'])->fetchColumn(), 'Jeju');
check('guests linked', (int)$db->query("SELECT COUNT(*) FROM episode_guests WHERE episode_id=" . (int)$row['episode_id'])->fetchColumn(), 3);
check('tags linked', (int)$db->query("SELECT COUNT(*) FROM episode_tags WHERE episode_id=" . (int)$row['episode_id'])->fetchColumn(), 2);

section('provenance recorded');
check('per-source rows written', (int)$db->query("SELECT COUNT(*) FROM episode_sources WHERE episode_number=$EP")->fetchColumn() > 0, true);
check('per-field rows written',  (int)$db->query("SELECT COUNT(*) FROM episode_field_sources WHERE episode_number=$EP")->fetchColumn() > 0, true);
check('changes recorded',        (int)$db->query("SELECT COUNT(*) FROM scrape_changes WHERE episode_number=$EP")->fetchColumn() > 0, true);
$fs = $prov->fieldSources($EP);
check('field provenance is readable back', isset($fs['air_date'], $fs['title']), true);
check('the archive can answer "where did this come from?"', count($prov->forEpisode($EP)['sources']) > 0, true);

// ============================================================
section('phase 9 — a complete episode costs nothing to re-check');
$before = (int)$db->query("SELECT COUNT(*) FROM scrape_log")->fetchColumn();
$r2 = $engine->syncEpisode($EP, ['skip_thumbnail' => true]);
check('second pass changes nothing', array_keys((array)($r2['apply'] ?? [])), []);
$contacted = count(array_filter((array)$r2['meta'], fn($m) => !in_array($m['status'], ['skipped','disabled','suppressed'], true)));
check('and contacts few or no sources', $contacted <= 2, true);

// An episode with NOTHING missing must not be fetched at all.
$db->exec("UPDATE episodes SET synopsis='A complete synopsis long enough to satisfy the validator comfortably.',
           main_mission='Done', teams='A vs B', results='A wins' WHERE episode_number=$EP");
$db->prepare("INSERT INTO thumbnails (episode_number, local_path, thumbnail_url, verified) VALUES (?,?,?,1)
              ON DUPLICATE KEY UPDATE verified=1")->execute([$EP, "/thumbnails/2026/ep$EP.jpg", 'https://fixture.test/x.jpg']);
$db->exec("UPDATE episodes SET thumbnail_id=(SELECT thumbnail_id FROM thumbnails WHERE episode_number=$EP LIMIT 1) WHERE episode_number=$EP");
$gaps = $md->gaps($EP);
check('gap detector reports a complete episode as complete', $gaps, []);
$r3 = $engine->syncEpisode($EP, ['skip_thumbnail' => true]);
check('a complete episode is skipped outright', !empty($r3['skipped']), true);
check('  → with no source contacted at all', count(array_filter((array)$r3['meta'], fn($m) => ($m['status'] ?? '') !== 'skipped')), 0);

section('phase 9 — targeting modes do only their own work');
for ($ep = TEST_LO + 1; $ep <= TEST_LO + 4; $ep++) $engine->syncEpisode($ep, ['all_fields' => true, 'skip_thumbnail' => true]);

$t = $md->targetsFor('single', ['episode' => $EP]);
check('single targets exactly one episode', count($t['episodes']), 1);
check('  → and it is the one asked for', $t['episodes'][0], $EP);

$t = $md->targetsFor('range', ['from' => TEST_LO, 'to' => TEST_LO + 4, 'limit' => 100]);
check('range targets exactly the range', count($t['episodes']), 5);

$t = $md->targetsFor('range', ['from' => 1, 'to' => 5000, 'limit' => 25]);
check('range obeys the batch limit rather than scraping thousands', count($t['episodes']), 25);

$t = $md->targetsFor('full', ['from' => 1, 'to' => 5000, 'limit' => 30]);
check('full rescan is windowed, not unbounded', count($t['episodes']), 30);

$t = $md->targetsFor('missing', ['limit' => 500]);
check('missing mode returns only incomplete episodes', in_array($EP, $t['episodes'], true), false);
check('  → and reports which fields each one needs', is_array($t['fields']), true);

$t = $md->targetsFor('failed', ['limit' => 100]);
check('failed mode returns only previously-failed episodes',
      count(array_diff($t['episodes'], array_keys($md->failedEpisodes(500)))), 0);

$t = $md->targetsFor('latest', ['latest' => $md->maxEpisode(), 'max_new' => 5, 'tail' => 3]);
check('latest mode does not queue the whole archive', count($t['episodes']) <= 10, true);

section('phase 9 — only the needed sources are contacted');
$partial = TEST_LO + 5;
seedEpisode($partial);
$db->prepare("INSERT INTO episodes (episode_number, title, air_date, main_mission, verification_required)
              VALUES (?,?,?,?,1)")->execute([$partial, "Episode #$partial - Existing Title", '2026-08-15', 'Existing mission']);
$gaps = $md->gaps($partial);
check('gap detector sees the empty fields', in_array('synopsis', $gaps, true), true);
check('  → and not the filled ones', in_array('air_date', $gaps, true), false);
$sources = RmSourceRegistry::instance()->sourcesForFields(['synopsis']);
check('a synopsis gap selects fewer sources than "everything"',
      count($sources) < count(RmSourceRegistry::instance()->active(true)), true);
check('  → and only sources that actually supply synopsis',
      array_values(array_filter($sources, fn($s) => !in_array('synopsis', RmSourceRegistry::instance()->get($s)->fields(), true))), []);

// ============================================================
section('phase 10 — dry run writes nothing');
$dryEp = TEST_LO + 6;
seedEpisode($dryEp);
$countBefore = (int)$db->query("SELECT COUNT(*) FROM episodes")->fetchColumn();
$changeBefore = (int)$db->query("SELECT COUNT(*) FROM scrape_changes")->fetchColumn();
$dry = $engine->dryRun($dryEp, ['skip_thumbnail' => true]);
check('dry run reports what it would change', count($dry['changes']) > 0, true);
check('  → creates no episode row', (int)$db->query("SELECT COUNT(*) FROM episodes WHERE episode_number=$dryEp")->fetchColumn(), 0);
check('  → leaves the episode count unchanged', (int)$db->query("SELECT COUNT(*) FROM episodes")->fetchColumn(), $countBefore);
check('  → writes no change rows', (int)$db->query("SELECT COUNT(*) FROM scrape_changes")->fetchColumn(), $changeBefore);
check('  → writes no provenance', (int)$db->query("SELECT COUNT(*) FROM episode_field_sources WHERE episode_number=$dryEp")->fetchColumn(), 0);
check('  → and downloads no thumbnail', ($dry['thumbnail']['ok'] ?? null) !== true, true);

section('phase 3 — the trace observes and writes NOTHING');
// A diagnostic that mutates the thing it is diagnosing is worse than no
// diagnostic. The trace must not write episode data, provenance, change
// rows, log lines, or source health — tracing an episode must never be
// the thing that puts a source into a cool-down.
$traceEp = TEST_LO + 10;
seedEpisode($traceEp);
$snapshot = fn() => [
    'episodes'    => (int)$GLOBALS['db']->query('SELECT COUNT(*) FROM episodes')->fetchColumn(),
    'sources'     => (int)$GLOBALS['db']->query('SELECT COUNT(*) FROM episode_sources')->fetchColumn(),
    'fields'      => (int)$GLOBALS['db']->query('SELECT COUNT(*) FROM episode_field_sources')->fetchColumn(),
    'changes'     => (int)$GLOBALS['db']->query('SELECT COUNT(*) FROM scrape_changes')->fetchColumn(),
    'log'         => (int)$GLOBALS['db']->query('SELECT COUNT(*) FROM scrape_log')->fetchColumn(),
    'runs'        => (int)$GLOBALS['db']->query('SELECT COUNT(*) FROM scrape_runs')->fetchColumn(),
    'flags'       => (int)$GLOBALS['db']->query('SELECT COUNT(*) FROM review_flags')->fetchColumn(),
    'health'      => (int)$GLOBALS['db']->query('SELECT COALESCE(SUM(success_count+failure_count+empty_count),0) FROM source_health')->fetchColumn(),
];
$before = $snapshot();
$trace = $engine->trace($traceEp);
$after = $snapshot();
check('the trace produced a plan', count($trace['resolved'] ?? []) > 0, true);
check('  → and shows per-source detail', count($trace['meta'] ?? []) > 0, true);
check('  → and reports what it would apply', count($trace['apply'] ?? []) > 0, true);
check('  → episodes untouched',   $after['episodes'], $before['episodes']);
check('  → provenance untouched', $after['sources'],  $before['sources']);
check('  → field sources untouched', $after['fields'], $before['fields']);
check('  → change log untouched', $after['changes'],  $before['changes']);
check('  → activity log untouched', $after['log'],    $before['log']);
check('  → run table untouched',  $after['runs'],     $before['runs']);
check('  → review flags untouched', $after['flags'],  $before['flags']);
check('  → source health untouched', $after['health'], $before['health']);
check('  → and it is marked read-only', !empty($trace['read_only']), true);

section('phase 10 — a dry run records no provenance either');
$dryProvEp = TEST_LO + 12;
seedEpisode($dryProvEp);
$provBefore = (int)$db->query('SELECT COUNT(*) FROM episode_sources')->fetchColumn();
$engine->dryRun($dryProvEp, ['skip_thumbnail' => true]);
check('a dry run writes no source provenance',
      (int)$db->query('SELECT COUNT(*) FROM episode_sources')->fetchColumn(), $provBefore);
check('  → but it DOES record health, having really contacted the sources',
      (int)$db->query('SELECT COALESCE(SUM(success_count+failure_count+empty_count),0) FROM source_health')->fetchColumn() > 0, true);

section('phase 10 — then the real sync applies exactly that');
$wouldApply = array_keys((array)$dry['apply']);
$real = $engine->syncEpisode($dryEp, ['all_fields' => true, 'skip_thumbnail' => true]);
check('the real run succeeds', empty($real['failed']), true);
check('  → and applies the same fields the dry run predicted',
      array_values(array_intersect($wouldApply, array_keys((array)$real['apply']))), array_values($wouldApply));
check('  → the episode now exists', (int)$db->query("SELECT COUNT(*) FROM episodes WHERE episode_number=$dryEp")->fetchColumn(), 1);

section('phase 10 — a broken source cannot empty good data');
$good = $db->query("SELECT synopsis, title FROM episodes WHERE episode_number=$dryEp")->fetch();
$cache->set("http:mrm:ep:$dryEp", '<html><body>Home | Episodes | Guests</body></html>' . str_repeat(' ', 700), 900, 'page');
$cache->set("http:myrm:ep:$dryEp", '<html><body>Site under maintenance</body></html>' . str_repeat(' ', 500), 900, 'page');
$engine->syncEpisode($dryEp, ['all_fields' => true, 'skip_thumbnail' => true]);
$after = $db->query("SELECT synopsis, title FROM episodes WHERE episode_number=$dryEp")->fetch();
check('synopsis survives a parser break', $after['synopsis'], $good['synopsis']);
check('title survives a parser break',    $after['title'],    $good['title']);
check('and the source is marked, not trusted',
      (int)$db->query("SELECT COUNT(*) FROM episode_sources WHERE episode_number=$dryEp AND status='parser_warning'")->fetchColumn() > 0, true);

// ============================================================
section('guest identity survives a deleted guest');
// Regression: deleting a guest used to leave its alias behind pointing
// at a row that no longer existed. resolve() returned that dead id, the
// INSERT IGNORE into episode_guests hit a foreign-key failure that
// IGNORE swallowed, and the guest silently stopped attaching to
// anything — with no error recorded anywhere.
$gr = new RmGuestResolver($db);
$first = $gr->resolve('Fixture Ghost', 1950, 'test');
check('a new guest is created', $first['created'], true);
check('  → and an alias is recorded',
      (int)$db->query("SELECT COUNT(*) FROM guest_aliases WHERE alias_raw='Fixture Ghost'")->fetchColumn() > 0, true);
$db->exec('DELETE FROM guests WHERE guest_id = ' . (int)$first['guest_id']);
$second = $gr->resolve('Fixture Ghost', 1950, 'test');
check('after deletion the name resolves to a LIVE guest', $second['created'], true);
check('  → and the id returned really exists',
      (int)$db->query("SELECT COUNT(*) FROM guests WHERE guest_id=" . (int)$second['guest_id'])->fetchColumn(), 1);
check('  → the stale alias was cleaned up',
      (int)$db->query("SELECT COUNT(*) FROM guest_aliases a LEFT JOIN guests g ON g.guest_id=a.guest_id
                        WHERE a.guest_id IS NOT NULL AND g.guest_id IS NULL")->fetchColumn(), 0);
$db->exec('DELETE FROM guests WHERE name_romanized = \'Fixture Ghost\'');
$db->exec("DELETE FROM guest_aliases WHERE alias_raw='Fixture Ghost'");

section('phase 11 — cron: concurrent runs cannot collide');
lockRelease('cron');
check('first process acquires the lock', lockAcquire('cron', 1800), true);
check('second process is refused',       lockAcquire('cron', 1800), false);
check('the lock reads as held',          lockIsHeld('cron'), true);
lockRelease('cron');
check('and is released cleanly',         lockIsHeld('cron'), false);

section('phase 11 — a successful run');
RmSourceHealth::instance()->clearSuppression();
RmCache::instance()->flush('negative');
$okEp = TEST_LO + 7;
seedEpisode($okEp);
$out = $engine->runMode('range', ['from' => $okEp, 'to' => $okEp, 'limit' => 1,
                                  'skip_thumbnail' => true, 'time_limit' => 60, 'scope' => 'integration ok']);
check('status is completed', $out['summary']['status'], 'completed');
check('one episode added',   (int)$out['summary']['counts']['added'], 1);
check('nothing failed',      (int)$out['summary']['counts']['failed'], 0);
check('a run row was written', RmScrapeRun::latest() !== null, true);
check('the run log has lines', count(RmScrapeRun::logFor((int)RmScrapeRun::latest()['run_id'], 50)) > 0, true);

section('phase 11 — partial failure is not reported as success');
$badEp = TEST_LO + 8;   // no fixture, and the network is unavailable
$goodEp = TEST_LO + 9;
seedEpisode($goodEp);
$out = $engine->runMode('range', ['from' => min($goodEp, $badEp), 'to' => max($goodEp, $badEp), 'limit' => 2,
                                  'skip_thumbnail' => true, 'time_limit' => 60, 'scope' => 'integration partial']);
check('some succeeded and some failed',
      (int)$out['summary']['counts']['failed'] >= 1 && (int)$out['summary']['counts']['added'] >= 1, true);
check('status is partial, not completed', $out['summary']['status'], 'partial');

section('phase 11 — total failure is reported as failed');
$out = $engine->runMode('range', ['from' => $badEp, 'to' => $badEp, 'limit' => 1,
                                  'skip_thumbnail' => true, 'time_limit' => 60, 'scope' => 'integration total']);
check('status is failed', $out['summary']['status'], 'failed');
check('nothing was added', (int)$out['summary']['counts']['added'], 0);
check('the failure names a cause, not just "no data"',
      strlen((string)($out['results'][$badEp]['reason'] ?? '')) > 25, true);

section('a cool-down blocks requests, not cached data');
// Regression: a source pushed into cool-down by one episode's failure
// used to be skipped for EVERY later episode — including ones whose
// data was already cached. A protective back-off was silently costing
// data it did not need to.
$cachedEp = TEST_LO + 2;
$db->exec("DELETE FROM episodes WHERE episode_number = $cachedEp");
seedEpisode($cachedEp);
// disabled_until is written by the engine with PHP's clock (see
// RmSourceHealth::refreshStatus), so set it the same way — SQL NOW()
// can sit in a different timezone from PHP's date_default_timezone_set
// and the comparison would be meaningless.
$db->prepare("UPDATE source_health SET status='down', disabled_until=?, consecutive_failures=5
               WHERE source_name IN ('myrunningman','myrm')")
   ->execute([date('Y-m-d H:i:s', time() + 3600)]);
check('the sources really are suppressed', RmSourceHealth::instance()->isSuppressed('myrunningman'), true);
$r = $engine->syncEpisode($cachedEp, ['all_fields' => true, 'skip_thumbnail' => true]);
check('the episode still succeeds from cache', empty($r['failed']), true);
check('  → and is saved', (int)$db->query("SELECT COUNT(*) FROM episodes WHERE episode_number=$cachedEp")->fetchColumn(), 1);
check('  → the suppressed source is marked as such',
      !empty($r['meta']['myrunningman']['suppressed']), true);
check('  → and its cool-down is NOT cleared by a cached read',
      RmSourceHealth::instance()->isSuppressed('myrunningman'), true);

$uncachedEp = TEST_LO + 11;   // suppressed sources, nothing cached
$r = $engine->syncEpisode($uncachedEp, ['all_fields' => true, 'skip_thumbnail' => true]);
check('with nothing cached a suppressed source reports honestly',
      $r['meta']['myrunningman']['status'] ?? null, 'suppressed');
check('  → and is not blamed for a failure it never had',
      str_contains((string)($r['meta']['myrunningman']['error'] ?? ''), 'not contacted'), true);
RmSourceHealth::instance()->clearSuppression();

section('phase 11 — interruption and recovery');
// The deliberate failures above put every source into an automatic
// cool-down. That is the engine behaving correctly; clear it so this
// phase tests recovery rather than the back-off.
RmSourceHealth::instance()->clearSuppression();
RmCache::instance()->flush('negative');
// Simulate a run killed mid-flight: status still "running", queue stored.
$resumeEp = TEST_LO + 3;
$db->exec("DELETE FROM episodes WHERE episode_number = $resumeEp");
seedEpisode($resumeEp);
$db->prepare("INSERT INTO scrape_runs (mode, scope, dry_run, status, started_at, cursor_state, last_episode)
              VALUES ('range','integration interrupted',0,'running', DATE_SUB(NOW(), INTERVAL 90 MINUTE), ?, ?)")
   ->execute([json_encode([$resumeEp]), $resumeEp - 1]);
$stale = RmScrapeRun::resumable(30);
check('an interrupted run is detected', $stale !== null, true);
check('  → and its remaining queue is intact', json_decode((string)$stale['cursor_state'], true), [$resumeEp]);
$res = $engine->resume($stale, ['skip_thumbnail' => true]);
check('resuming processes the remaining episode', !empty($res['resumed']), true);
check('  → the episode is now saved', (int)$db->query("SELECT COUNT(*) FROM episodes WHERE episode_number=$resumeEp")->fetchColumn(), 1);
check('  → the abandoned run is closed out',
      $db->query("SELECT status FROM scrape_runs WHERE run_id=" . (int)$stale['run_id'])->fetchColumn(), 'aborted');
check('  → and nothing is left to resume', RmScrapeRun::resumable(30), null);

section('phase 11 — a time budget pauses instead of overrunning');
$out = $engine->runMode('range', ['from' => TEST_LO, 'to' => TEST_LO + 4, 'limit' => 5,
                                  'skip_thumbnail' => true, 'time_limit' => 0.001, 'scope' => 'integration budget']);
check('the run reports itself paused', !empty($out['paused']), true);
$paused = $db->query("SELECT cursor_state, status FROM scrape_runs ORDER BY run_id DESC LIMIT 1")->fetch();
check('  → the run is left open for resuming', $paused['status'] ?? null, 'running');
check('  → and the remaining queue is checkpointed',
      is_array(json_decode((string)($paused['cursor_state'] ?? ''), true)), true);
$db->exec("UPDATE scrape_runs SET status='aborted', cursor_state=NULL WHERE status='running'");

section('invalid data never reaches an episode that already has good data');
// Step 4 of the live check-list: prove end to end — not just in the
// resolver — that a source offering a wrong-year date, a title from a
// different episode and a stub synopsis cannot damage a good record.
$badEp = TEST_LO + 8;
$db->prepare("INSERT INTO episodes (episode_number, title, air_date, synopsis, main_mission, verification_required)
              VALUES (?,?,?,?,?,0)
              ON DUPLICATE KEY UPDATE title=VALUES(title), air_date=VALUES(air_date), synopsis=VALUES(synopsis)")
   ->execute([$badEp, "Episode #$badEp - A Correct Title", '2026-07-15',
              'A correct and reasonably long synopsis that the archive already holds for this episode.',
              'The correct mission']);
$goodBefore = $db->query("SELECT title, air_date, synopsis, main_mission FROM episodes WHERE episode_number=$badEp")->fetch(PDO::FETCH_ASSOC);

RmSourceHealth::instance()->clearSuppression();
$cache->flush();
// English Wikipedia offers this episode with a date four years off, a
// title that belongs to a different episode, and a one-word synopsis.
seedWikiYear(rmYear($badEp), [$badEp => [
    'date' => '2011-03-06', 'title' => 'Episode #' . ($badEp + 40) . ' - Someone Else Entirely',
    'guests' => ['Not A Person 12345'], 'mission' => 'x', 'teams' => '', 'results' => '',
]]);
$engine->syncEpisode($badEp, ['all_fields' => true, 'skip_thumbnail' => true]);
$goodAfter = $db->query("SELECT title, air_date, synopsis, main_mission FROM episodes WHERE episode_number=$badEp")->fetch(PDO::FETCH_ASSOC);

check('a date from the wrong year is refused',   $goodAfter['air_date'], $goodBefore['air_date']);
check('a title belonging to another episode is refused', $goodAfter['title'], $goodBefore['title']);
check('a stub synopsis cannot replace a real one',$goodAfter['synopsis'], $goodBefore['synopsis']);
check('and the mission is left alone',            $goodAfter['main_mission'], $goodBefore['main_mission']);
check('the rejected guest did not become a person',
      (int)$db->query("SELECT COUNT(*) FROM guests WHERE name_romanized LIKE '%12345%'")->fetchColumn(), 0);

// The same source, offering the same episode correctly, is still trusted.
$cache->flush();
seedWikiYear(rmYear($badEp), [$badEp => [
    'date' => '2026-07-15', 'title' => "Episode #$badEp - A Correct Title",
    'guests' => ['Fixture Ha-neul'], 'mission' => 'A properly described mission for this episode',
    'teams' => 'Blue vs Red', 'results' => 'Blue wins',
]]);
$engine->syncEpisode($badEp, ['all_fields' => true, 'skip_thumbnail' => true]);
check('  → so a later valid answer from it is still accepted',
      (int)$db->query("SELECT COUNT(*) FROM episode_guests eg JOIN episodes e ON e.episode_id=eg.episode_id
                       WHERE e.episode_number=$badEp")->fetchColumn() > 0, true);

// ============================================================
section('source health — broken is not the same as has nothing');
// The reported symptom behind this: the control centre showed every
// source Online while the run said "sources ok 0", and a source that
// simply does not list episode 813 was painted with a red error line.
// Reachability, coverage and parser health are three different facts.
$hp   = RmSourceHealth::instance();
$hsrc = 'kowiki';
$snapH = $db->query("SELECT * FROM source_health WHERE source_name=" . $db->quote($hsrc))->fetch(PDO::FETCH_ASSOC);
$db->exec("DELETE FROM source_health WHERE source_name=" . $db->quote($hsrc));
$hrow = fn() => $db->query("SELECT * FROM source_health WHERE source_name=" . $db->quote($hsrc))->fetch(PDO::FETCH_ASSOC) ?: [];

$hp->record($hsrc, 'success', ['error_class' => 'ok', 'ms' => 100, 'parser_version' => 'kowiki-1.0']);
check('a source that answered is healthy', $hrow()['status'], 'ok');

// HTTP 200, parser fine, the episode is simply not on the page.
$hp->record($hsrc, 'empty', ['error_class' => 'missing_episode', 'ms' => 90,
    'error' => 'Episode 813 is not listed on the 2026 page (52 episodes found)', 'parser_version' => 'kowiki-1.0']);
$h1 = $hrow();
check('an episode it does not carry leaves it healthy', $h1['status'], 'ok');
check('  → and is not written up as a source error',    (string)($h1['last_error'] ?? ''), '');
check('  → but is still counted, so coverage stays visible', (int)$h1['empty_count'], 1);
check('  → and it is not put in a cool-down',           $hp->isSuppressed($hsrc), false);

// Reached, parsed, produced nothing: that IS a problem with the source.
$hp->record($hsrc, 'parser_warning', ['error_class' => 'parser_failure', 'ms' => 95,
    'error' => 'Year page loaded but produced 0 episode rows', 'parser_version' => 'kowiki-1.0']);
$h2 = $hrow();
check('a page that parses to nothing does count against it', (int)$h2['parser_warnings'], 1);
check('  → and says so',  str_contains((string)$h2['last_error'], '0 episode rows'), true);
check('  → without being called unreachable', in_array($h2['status'], ['down','blocked'], true), false);

// Transport failure: the only kind that makes a source unreachable.
$hp->record($hsrc, 'failure', ['error_class' => 'timeout', 'ms' => 20000,
    'error' => 'Timed out after 20s', 'parser_version' => 'kowiki-1.0']);
check('a transport failure degrades it', $hrow()['status'], 'degraded');
for ($i = 0; $i < 2; $i++)
    $hp->record($hsrc, 'failure', ['error_class' => 'timeout', 'ms' => 20000, 'error' => 'Timed out after 20s']);
check('  → and repeated ones take it down',   $hrow()['status'], 'down');
check('  → which does put it in a cool-down', $hp->isSuppressed($hsrc), true);

// A refusal from the site is neither a timeout nor our problem to retry.
$hp->record($hsrc, 'failure', ['error_class' => 'blocked', 'ms' => 300, 'error' => 'HTTP 403']);
check('a 403 is classified as blocked, not down', $hrow()['status'], 'blocked');

$hp->record($hsrc, 'success', ['error_class' => 'ok', 'ms' => 100, 'parser_version' => 'kowiki-1.0']);
$h3 = $hrow();
check('one good answer clears the record',  (int)$h3['consecutive_failures'], 0);
check('  → and the stale error with it',    (string)($h3['last_error'] ?? ''), '');

$db->exec("DELETE FROM source_health WHERE source_name=" . $db->quote($hsrc));
if ($snapH) {
    $cols = array_keys($snapH);
    $db->prepare('INSERT INTO source_health (`' . implode('`,`', $cols) . '`) VALUES ('
                 . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($snapH));
}

section('the reported live scenario — a top-up that finds nothing');
// Reproduces a real run: existing, intact episodes; sources reachable and
// healthy; none of them carrying the fields those episodes are missing.
// It used to report "14 checked / 14 failed" with "sources ok 0 /
// skipped 98" while the health panel correctly showed every source
// Online — three separate misreadings of a run in which nothing was
// wrong and nothing was lost.
wipeTestData($db);
RmSourceHealth::instance()->clearSuppression();
$cache->flush();
$lo = TEST_LO; $hi = TEST_LO + 5;

// Wikipedia lists a different year's episodes, so these are simply not on it.
seedWikiYear(rmYear($lo), [500 => [
    'date' => '2020-01-05', 'title' => 'An Older Episode',
    'guests' => ['Someone'], 'mission' => 'M', 'teams' => 'T', 'results' => 'R',
]]);
// SBS serves a client-rendered shell — the live symptom.
$shell = '<html><head><title>런닝맨</title></head><body><div id="root"></div>'
       . '<script>window.__NEXT_DATA__={"props":{}}</script></body></html>' . str_repeat(' ', 1200);
foreach ([md5('https://programs.sbs.co.kr/enter/runningman/visualboard/54666'),
          md5('https://programs.sbs.co.kr/enter/runningman')] as $h) $cache->set("http:sbs:page:$h", $shell, 300, 'page');

for ($n = $lo; $n <= $hi; $n++) {
    $db->prepare("INSERT INTO episodes (episode_number, title, air_date, synopsis, main_mission, verification_required)
                  VALUES (?,?,?,?,?,1)
                  ON DUPLICATE KEY UPDATE title=VALUES(title), synopsis=VALUES(synopsis)")
       ->execute([$n, "Episode #$n - Intact Title", '2026-07-01',
                  'An existing synopsis that must survive a run which finds nothing new at all.', 'Existing mission']);
    $cache->set("http:mrm:ep:$n", '<html><body><nav>Home | Episodes | Guests</nav></body></html>' . str_repeat(' ', 700), 300, 'page');
    $cache->set("http:myrm:ep:$n", '<html><body><nav>Home</nav></body></html>' . str_repeat(' ', 500), 300, 'page');
    $cache->set("http:mdl:ep:$n", '<html><body><nav>Home</nav></body></html>' . str_repeat(' ', 700), 300, 'page');
    // AsianWiki answers with its "no such page" body — reachable, and
    // genuinely without this episode.
    $cache->set('http:asianwiki:ep:' . md5("https://asianwiki.com/Running_Man_Episode_$n"),
        '<html><body>There is currently no text in this page</body></html>' . str_repeat(' ', 500), 300, 'page');
    // Fandom (PR12): reachable, resolves to the right episode, but the
    // article has no portable-infobox yet — a real, reachable-but-warned
    // response, same bucket as myrunningman/myrm above.
    $cache->set("http:fandom:ep:$n",
        json_encode(['parse' => ['title' => "Episode/$n", 'text' => ['*' => '<p>Stub article.</p>']]])
        . str_repeat(' ', 700), 300, 'page');
}
// TVmaze (PR12): reachable episode index, but it covers none of these —
// same "reachable, genuinely nothing here" shape as AsianWiki/IMDb above.
$cache->set('tvmaze:index', [1 => ['name' => 'Episode 1', 'airdate' => '2010-07-11']], 3600, 'api');
// IMDb answers with a season listing that does not include these episodes.
$cache->set('http:imdb:season:' . md5('https://www.imdb.com/title/tt1587289/episodes/?season=' . (rmYear($lo) - 2009)),
    '<html><body><script type="application/ld+json">{"@type":"TVSeries","episode":[]}</script></body></html>'
    . str_repeat(' ', 900), 300, 'page');
// Korean Wikipedia: reachable, but its table covers a different year.
foreach (["런닝맨의 에피소드 목록 (" . rmYear($lo) . ")",
          "런닝맨의 에피소드 목록 (" . rmYear($lo) . "년)",
          '런닝맨의 에피소드 목록'] as $koTitle) {
    $cache->set('http:kowiki:page:' . rmYear($lo) . ':' . md5($koTitle),
        json_encode(['parse' => ['text' => ['*' =>
            '<table class="wikitable"><tr><th>회차</th><th>방송일</th><th>제목</th><th>게스트</th></tr>'
            . '<tr><td>500</td><td>2020-01-05</td><td>다른 회차</td><td>누군가</td></tr></table>'
            . str_repeat('<!-- pad -->', 220)]]]), 300, 'api');
}
$snapBefore = $db->query("SELECT COUNT(*) c, SUM(CHAR_LENGTH(synopsis)) len FROM episodes
                           WHERE episode_number BETWEEN $lo AND $hi")->fetch();

$out = $engine->runMode('range', ['from' => $lo, 'to' => $hi, 'limit' => 6,
                                  'time_limit' => 120, 'scope' => 'integration reported-scenario']);
$c = $out['summary']['counts'];

check('the run is not reported as failed',        $out['summary']['status'], 'completed');
check('  → the episodes count as skipped',        (int)$c['skipped'], 6);
check('  → and none as failed',                   (int)$c['failed'], 0);

// The counters must distinguish the three reasons a source gave nothing.
check('sources that had nothing are counted as empty, not skipped', (int)$c['src_empty'] > 0, true);
check('sources that parsed nothing are counted as warned',          (int)$c['src_warned'] > 0, true);
// The whole point of the split counters: "skipped" must mean "never
// contacted" and nothing else. Anything that was actually asked has to
// land in ok / empty / warned / failed however little it gave back. The
// live run read "ok 0 · failed 6 · skipped 98" precisely because every
// contacted-but-fruitless source fell into the skipped bucket.
$neverContacted = 0; $contacted = 0;
foreach ($out['results'] as $r) {
    foreach (($r['meta'] ?? []) as $info) {
        in_array($info['status'] ?? '', ['skipped','disabled','suppressed','not_applicable'], true)
            ? $neverContacted++ : $contacted++;
    }
}
check('nothing failed at the transport level', (int)$c['src_failed'], 0);
check('"skipped" counts the never-contacted sources and only those',
      (int)$c['src_skipped'], $neverContacted);
check('  → every source that was actually asked is counted elsewhere',
      (int)$c['src_ok'] + (int)$c['src_empty'] + (int)$c['src_warned'] + (int)$c['src_failed'], $contacted);
check('  → and this run did ask several of them', $contacted > 0, true);

$snapAfter = $db->query("SELECT COUNT(*) c, SUM(CHAR_LENGTH(synopsis)) len FROM episodes
                          WHERE episode_number BETWEEN $lo AND $hi")->fetch();
check('every episode survives untouched',  $snapAfter['c'], $snapBefore['c']);
check('  → with its synopsis intact',      $snapAfter['len'], $snapBefore['len']);

$one = $out['results'][$hi] ?? [];
check('the episode result explains itself', strlen((string)($one['reason'] ?? '')) > 30, true);
check('  → and says the episode was left unchanged',
      str_contains(strtolower((string)($one['reason'] ?? '')), 'unchanged'), true);
check('SBS is diagnosed as client-rendered, not a generic parser warning',
      $one['meta']['sbs']['status'] ?? null, 'needs_javascript');
check('  → and health does not call SBS unreachable',
      in_array(RmSourceHealth::instance()->get('sbs')['status'], ['down', 'blocked'], true), false);

echo "\n" . str_repeat('─', 62) . "\n";
printf("%s — %d passed, %d failed\n", $fail === 0 ? 'INTEGRATION VERIFIED' : 'INTEGRATION PROBLEMS', $pass, $fail);
exit($fail === 0 ? 0 : 1);
