<?php
// ============================================================
// Auto Sync — the Research Command Centre.
//
// The page this replaces drove its own loop in JavaScript. The
// browser held the queue, counted the progress and decided it was
// finished, so a refresh after a completed run showed a page that
// looked like nothing had ever happened, and the same 386 episodes
// came back every time it was opened. "Did the sync actually finish?"
// was unanswerable because nothing had written the answer down.
//
// Here the server owns the run. The queue is a table, progress is a
// query, and the outcome is a row. The page's job is to answer five
// questions the moment it loads:
//
//   1. What state is the archive in?
//   2. Is a run active right now?
//   3. When was the last run?
//   4. What happened during it?
//   5. What needs attention, and why?
//
// RUN state, ARCHIVE state and RESEARCH state are three different
// things. A run can be finished while the archive is still
// incomplete, and that is not a failure — it is a coverage gap. The
// page says so in those words.
//
// ── IMPORTANT: AJAX handlers MUST run before layout.php ──
// layout.php prints raw HTML the moment it is required. Any JSON
// response below would otherwise arrive with a full page glued in
// front of it. admin/config.php arms the JSON guard for ?a= requests,
// which is belt to this braces.
// ============================================================
$adminTitle = 'Auto Sync';
$adminPage  = 'sync';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';
adminCheck();

$db = getDB();
const LOCK_NAME = 'sync';

// ────────────────────────────────────────────────────────────
// AJAX
// ────────────────────────────────────────────────────────────
$action = $_GET['a'] ?? null;

/** Every AJAX reply goes through here, so none of them can drift. */
function asJson(array $payload, int $code = 200): never
{
    if (function_exists('rmJsonOut')) rmJsonOut($payload, $code);
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Everything the page needs to describe itself, in one call.
 *
 * Deliberately one payload rather than five: the whole point is that
 * run state and archive state are shown together and never confused,
 * and that is easiest to guarantee when they are read together.
 */
function pageState(PDO $db): array
{
    $svc     = new RmResearchService($db);
    $state   = $svc->state();
    $ready   = rmResearchTablesExist();

    RmResearchRun::reapStalled();          // a walked-away run becomes resumable, not stuck

    $current  = RmResearchRun::current();
    $last     = RmResearchRun::last();
    $health   = $state->archiveHealth();
    $counts   = $state->eligibleCount();
    // Archive COVERAGE (does it exist / what does live evidence say the
    // archive should have) is a separate axis from archiveHealth() above
    // (metadata enrichment gaps) — PR11 §2. DB-only on page load, same
    // rule every dashboard here follows; the live evidence-based
    // detection (RmLatestEpisode::detectDetailed(), PR9/PR10 — never the
    // older vote-only RmDiscovery::latestEpisode() this used to read)
    // only runs on the explicit "Detect Latest" click below, and is
    // cached here so the header isn't blank until someone clicks it.
    $cachedDetection = json_decode((string)stateGet('latest_detection', ''), true);
    $coverage = $state->archiveCoverage(200, is_array($cachedDetection) ? $cachedDetection : null);

    $sources = [];
    foreach (RmSourceHealth::instance()->all() as $name => $h) {
        [$label, $colour, $dot] = RmSourceHealth::present((string)$h['status']);
        $rep = RmSourceReputation::instance();
        $sources[$name] = [
            'name'        => $name,
            'label'       => $h['label'] ?? $name,
            'status'      => $h['status'],
            'status_text' => $label,
            'colour'      => $colour,
            'dot'         => $dot,
            'reputation'  => $rep->reliability($name, '*'),
            'band'        => RmSourceReputation::band($rep->reliability($name, '*')),
            'last_error'  => $h['last_error'] ?? null,
            'avg_ms'      => (int)($h['avg_ms'] ?? 0),
            'last_success'=> $h['last_success_at'] ?? null,
            'fields'      => RmSourceRegistry::instance()->get($name)?->fields() ?? [],
        ];
    }

    return [
        'ok'        => true,
        'ready'     => $ready,
        'archive'   => $health + $coverage + [
            'db_max'          => $coverage['archive_latest'],
            'eligible'        => $counts['eligible'],
            'resting'         => $counts['resting'],
        ],
        'current'   => $current?->summary(),
        'last'      => $last?->summary(),
        'attention' => $state->attention(400),
        'sources'   => $sources,
        'reviews'   => count(RmDecisionEngine::openReviews($db, 200)),
        'modes'     => RmResearchService::modes(),
    ];
}

if ($action === 'state') {
    asJson(pageState($db));
}

if ($action === 'install') {
    $out = [];
    foreach (['scraping_engine', 'research_engine'] as $f) {
        $out[$f] = rmRunSqlFile($db, __DIR__ . "/../database/$f.sql");
    }
    rmResearchTablesExist(true);
    asJson(['ok' => $out['research_engine']['ok'], 'detail' => $out]);
}

if ($action === 'detect_latest') {
    // The one place this page contacts live sources — an explicit click,
    // never automatic. Uses the same evidence-based detector Maintenance
    // uses (RmLatestEpisode::detectDetailed(), PR9/PR10): aired vs
    // upcoming vs source disagreement vs insufficient evidence, never a
    // bare vote count from the older RmDiscovery::latestEpisode(). The
    // full result is cached so archiveCoverage() can show it on the next
    // page load without contacting anything.
    $det = RmLatestEpisode::detectDetailed();
    stateSet('latest_detection', json_encode($det));
    $coverage = (new RmResearchService($db))->state()->archiveCoverage(200, $det);
    asJson([
        'ok'                    => true,
        'decision'              => $det['decision'],
        'decision_note'         => $det['decision_note'],
        'latest_verified_aired' => $coverage['latest_verified_aired'],
        'upcoming'              => $coverage['upcoming'],
        'archive_latest'        => $coverage['archive_latest'],
        'missing_count'         => $coverage['missing_count'],
        'missing_range'         => $coverage['missing_range'],
        'confidence'            => $det['confidence'],
        'conflict'              => $det['conflict'],
        'source_status'         => $det['source_status'],
    ]);
}

// ── Run lifecycle ─────────────────────────────────────────────
if ($action === 'run_start') {
    if (!rmResearchTablesExist()) {
        asJson(['ok' => false, 'error' => 'Research tables are not installed yet.'], 200);
    }
    $existing = RmResearchRun::current();
    if ($existing !== null) {
        asJson(['ok' => false, 'error' => 'A run is already active (' . $existing->ref() . ').',
                'run' => $existing->summary()]);
    }
    $scope = (string)($_GET['scope'] ?? 'missing');
    $opt = [
        'mode'    => (string)($_GET['mode'] ?? rmScrapeConfig('research.default_mode', 'balanced')),
        'dry_run' => !empty($_GET['dry']),
        'limit'   => max(1, min((int)rmScrapeConfig('research.max_per_run', 200), (int)($_GET['limit'] ?? 50))),
        'force'   => !empty($_GET['force']),
    ];
    if ($scope === 'range')  { $opt['from'] = (int)($_GET['from'] ?? 1); $opt['to'] = (int)($_GET['to'] ?? 1); }
    if ($scope === 'single') { $opt['episode'] = (int)($_GET['ep'] ?? 0); }
    if ($scope === 'new')    { $opt['latest'] = (int)($_GET['latest'] ?? 0); }

    $svc = new RmResearchService($db);
    $run = $svc->startRun($scope, $opt);
    if ($run === null) asJson(['ok' => false, 'error' => 'Could not create the run.']);

    logActivity('sync', null, 'success',
        'Research run ' . $run->ref() . ' started (' . $scope . ', ' . $opt['mode'] . ')');
    asJson(['ok' => true, 'run' => $run->summary(),
            'queue' => array_map(fn($i) => [
                'episode' => (int)$i['episode_number'], 'reason' => $i['reason'],
                'priority' => (int)$i['priority'], 'state' => $i['state'],
            ], $run->items(500))]);
}

if ($action === 'run_step') {
    $run = RmResearchRun::current();
    if ($run === null) asJson(['ok' => true, 'idle' => true, 'run' => RmResearchRun::last()?->summary()]);
    if ($run->status() === RmResearchRun::PAUSED) {
        asJson(['ok' => true, 'paused' => true, 'run' => $run->summary()]);
    }
    set_time_limit(120);
    $svc  = new RmResearchService($db);
    $step = $svc->step($run, [
        'seconds'  => max(3, min(45, (int)($_GET['seconds'] ?? rmScrapeConfig('research.step_seconds', 20)))),
        'delay_ms' => max(0, min(10000, (int)($_GET['delay'] ?? 0))),
    ]);
    asJson(['ok' => true] + $step);
}

if (in_array($action, ['run_pause', 'run_resume', 'run_cancel'], true)) {
    $run = RmResearchRun::current();
    if ($run === null) asJson(['ok' => false, 'error' => 'No active run.']);
    match ($action) {
        'run_pause'  => $run->pause(),
        'run_resume' => $run->resume(),
        'run_cancel' => $run->cancelNow(),
    };
    logActivity('sync', null, 'success', 'Research run ' . $run->ref() . ' ' . substr($action, 4));
    asJson(['ok' => true, 'run' => $run->summary()]);
}

if ($action === 'run_report') {
    $id  = (int)($_GET['run'] ?? 0);
    $run = $id > 0 ? RmResearchRun::load($id) : RmResearchRun::last();
    if ($run === null) asJson(['ok' => false, 'error' => 'No such run.']);
    asJson(['ok' => true, 'run' => $run->summary(),
            'items' => array_map(fn($i) => [
                'episode' => (int)$i['episode_number'], 'state' => $i['state'],
                'reason' => $i['reason'], 'result' => $i['result_summary'],
                'confidence' => $i['confidence'] !== null ? (int)$i['confidence'] : null,
            ], $run->items(1000))]);
}

// ── One episode ───────────────────────────────────────────────
if ($action === 'episode') {
    $ep  = (int)($_GET['ep'] ?? 0);
    $svc = new RmResearchService($db);
    $prov = new RmProvenance($db);
    $desc = $svc->state()->describe($ep);

    $sources = [];
    foreach ($prov->forEpisode($ep)['sources'] ?? [] as $row) {
        $sources[] = [
            'source'      => $row['source_name'],
            'status'      => RmEvidenceSet::sourceStatusFor((string)$row['status']),
            'raw_status'  => $row['status'],
            'url'         => $row['source_url'],
            'fields'      => array_values(array_filter(explode(',', (string)$row['fields_provided']))),
            'http'        => $row['http_status'],
            'ms'          => (int)$row['duration_ms'],
            'fetched_at'  => $row['fetched_at'],
        ];
    }
    $decisions = [];
    try {
        $s = $db->prepare('SELECT * FROM research_decisions WHERE episode_number = ? ORDER BY field_name');
        $s->execute([$ep]);
        $decisions = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { }

    asJson(['ok' => true, 'episode' => $ep, 'research' => $desc,
            'reasons' => $svc->state()->queueReasons($ep),
            'sources' => $sources, 'decisions' => $decisions,
            'evidence' => RmEvidenceSet::stored($db, $ep)]);
}

if ($action === 'evidence') {
    $ep    = (int)($_GET['ep'] ?? 0);
    $field = (string)($_GET['field'] ?? '');
    asJson(['ok' => true, 'episode' => $ep, 'field' => $field,
            'evidence' => RmEvidenceSet::stored($db, $ep, $field ?: null)]);
}

/** Dry run: everything except the write, so the operator sees the diff first. */
if ($action === 'preview') {
    $ep  = (int)($_GET['ep'] ?? 0);
    $svc = new RmResearchService($db);
    $r   = $svc->researchEpisode($ep, [
        'mode'       => (string)($_GET['mode'] ?? rmScrapeConfig('research.default_mode', 'balanced')),
        'dry_run'    => true,
        'all_fields' => true,
        'bypass_cache' => !empty($_GET['fresh']),
    ]);
    unset($r['evidence'], $r['plan']);   // objects, not JSON payload
    asJson(['ok' => true] + $r);
}

if ($action === 'apply_safe') {
    $ep  = (int)($_GET['ep'] ?? 0);
    $svc = new RmResearchService($db);
    $r   = $svc->researchEpisode($ep, [
        'mode'       => (string)($_GET['mode'] ?? rmScrapeConfig('research.default_mode', 'balanced')),
        'all_fields' => true,
        'bypass_cache' => !empty($_GET['fresh']),
    ]);
    unset($r['evidence'], $r['plan']);
    logActivity('sync', $ep, $r['outcome'] === 'failed' ? 'failed' : 'success',
                (string)$r['reason'], (int)$r['ms']);
    asJson(['ok' => true] + $r);
}

/** An explicit retry overrides the cool-down — the operator has asked. */
if ($action === 'retry') {
    $ep = (int)($_GET['ep'] ?? 0);
    (new RmResearchState($db))->clearCoolDown($ep ?: null);
    if (!empty($_GET['sources'])) RmSourceHealth::instance()->clearSuppression();
    asJson(['ok' => true, 'episode' => $ep]);
}

// ── The conflict inbox ────────────────────────────────────────
if ($action === 'reviews') {
    asJson(['ok' => true, 'reviews' => RmDecisionEngine::openReviews($db, 200)]);
}

if ($action === 'review_resolve') {
    $id  = (int)($_GET['id'] ?? 0);
    $out = (string)($_GET['outcome'] ?? '');
    $ok  = RmDecisionEngine::resolveReview($db, $id, $out);
    asJson(['ok' => $ok, 'error' => $ok ? null : 'Unknown decision or outcome.']);
}

// ── Backward compatibility ────────────────────────────────────
// The single-episode endpoint the old page (and anything else that
// learned it) calls. Same URL, same JSON keys. It now runs through
// the research service, so a manual sync gains evidence, decisions
// and research memory without anything on the calling side changing.
if ($action === 'sync' && isset($_GET['ep'])) {
    set_time_limit(60);
    $n = (int)$_GET['ep'];
    try {
        $svc = new RmResearchService($db);
        $r = $svc->researchEpisode($n, [
            'dry_run'      => !empty($_GET['dry']),
            'all_fields'   => !empty($_GET['all']),
            'bypass_cache' => !empty($_GET['fresh']),
            'mode'         => (string)($_GET['mode'] ?? rmScrapeConfig('research.default_mode', 'balanced')),
        ]);
        $contributors = [];
        foreach ((array)$r['decisions'] as $d) {
            if (!empty($d['safe'])) foreach ((array)$d['supporting'] as $s) $contributors[$s] = true;
        }
        $existing = (new RmMissingData($db))->currentValues($n);
        logActivity('sync', $n, $r['outcome'] === 'failed' ? 'failed' : 'success',
                    (string)$r['reason'], (int)$r['ms']);

        asJson([
            'ok'      => $r['outcome'] !== 'failed',
            'ep'      => $n,
            'source'  => implode('+', array_keys($contributors)) ?: 'none',
            'title'   => $existing['title'] ?? null,
            'has_syn' => !empty($existing['synopsis']),
            'guests'  => count((array)($existing['guests'] ?? [])),
            'msg'     => $r['reason'],
            'outcome' => $r['outcome'],
            'applied' => count((array)($r['applied'] ?? [])),
            'skipped' => $r['outcome'] === 'no_data',
            'confidence' => $r['confidence'],
            'ms'      => $r['ms'],
        ]);
    } catch (Throwable $e) {
        logActivity('sync', $n, 'failed', $e->getMessage());
        asJson(['ok' => false, 'ep' => $n, 'msg' => $e->getMessage()]);
    }
}

// The old lock endpoints, kept so nothing that still calls them
// breaks. The persistent run is the real mechanism now.
if ($action === 'lock_status') {
    $run = RmResearchRun::current();
    asJson(['locked' => $run !== null, 'run' => $run?->summary(),
            'last_ep' => (int)stateGet('sync_last_ep', 0)]);
}
if ($action === 'lock_release') {
    lockRelease(LOCK_NAME);
    RmResearchRun::current()?->pause();
    asJson(['ok' => true]);
}
if ($action === 'progress') {
    // The old browser-driven loop's heartbeat. The run's own heartbeat
    // has replaced it, but anything still calling this must get JSON.
    stateSet('sync_done', (int)($_GET['done'] ?? 0));
    stateSet('sync_last_ep', (int)($_GET['ep'] ?? 0));
    RmResearchRun::current()?->heartbeat();
    asJson(['ok' => true, 'deprecated' => 'The run records its own progress now.']);
}

// Anything else that arrived as ?a= is an AJAX call the client believes
// exists. Falling through to the HTML page below would hand it a full
// document where it expects JSON — precisely the "Unexpected token '<'"
// failure this codebase already fixed once, arriving by another route.
rmJsonRejectUnknownAction($action);

// ────────────────────────────────────────────────────────────
// Page render — only reached when no AJAX action matched
// ────────────────────────────────────────────────────────────
require_once __DIR__ . '/layout.php';

$S          = pageState($db);
$researchOk = $S['ready'];
$archive    = $S['archive'];
$current    = $S['current'];
$last       = $S['last'];
$attention  = $S['attention'];
$modes      = $S['modes'];
$defaultMode = (string)rmScrapeConfig('research.default_mode', 'balanced');
$recentLog  = getRecentActivity(12, 'sync');

/** hh:mm:ss from a millisecond duration. */
function durHuman(?int $ms): string
{
    if ($ms === null || $ms <= 0) return '—';
    $s = (int)round($ms / 1000);
    if ($s < 60) return $s . 's';
    $m = intdiv($s, 60); $s %= 60;
    if ($m < 60) return $m . 'm ' . $s . 's';
    return intdiv($m, 60) . 'h ' . ($m % 60) . 'm';
}
function toneColour(string $tone): string
{
    return match ($tone) {
        'ok'   => '#4ade80', 'warn' => '#fcd34d', 'bad' => '#f87171',
        'busy' => '#29ABE2', default => 'rgba(255,255,255,.45)',
    };
}
?>
<style>
.rc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.55rem;margin-bottom:1.1rem}
.rc-stat{background:#111a28;border:1px solid rgba(41,171,226,.1);border-radius:9px;padding:.65rem .8rem}
.rc-stat b{display:block;font-size:1.35rem;font-weight:900;letter-spacing:-.03em;line-height:1.1}
.rc-stat span{display:block;font-size:.58rem;text-transform:uppercase;letter-spacing:.09em;color:rgba(255,255,255,.3);margin-top:.2rem}
.rc-banner{border-radius:12px;padding:1rem 1.15rem;margin-bottom:1.1rem;border:1px solid rgba(41,171,226,.16);background:#0e1420}
.rc-banner h3{font-size:.95rem;font-weight:900;display:flex;align-items:center;gap:.5rem;margin-bottom:.15rem}
.rc-ref{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.68rem;color:rgba(255,255,255,.35)}
.rc-nums{display:flex;flex-wrap:wrap;gap:.35rem .9rem;margin-top:.6rem;font-size:.74rem}
.rc-nums i{font-style:normal;color:rgba(255,255,255,.35)}
.rc-nums b{font-weight:800}
.rc-bar{background:#141c2c;border-radius:99px;height:5px;overflow:hidden;margin:.7rem 0 .3rem}
.rc-bar>div{background:#29ABE2;height:100%;border-radius:99px;width:0;transition:width .35s}
.rc-buckets{display:grid;grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:.6rem}
.rc-bucket{background:#111a28;border:1px solid rgba(41,171,226,.1);border-radius:10px;padding:.75rem .85rem;cursor:pointer;transition:.15s}
.rc-bucket:hover{border-color:rgba(41,171,226,.32)}
.rc-bucket b{font-size:1.25rem;font-weight:900;letter-spacing:-.02em}
.rc-bucket h5{font-size:.74rem;font-weight:800;margin:.05rem 0 .2rem}
.rc-bucket p{font-size:.64rem;color:rgba(255,255,255,.32);line-height:1.5}
.rc-src{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:.5rem}
.rc-srccard{background:#111a28;border:1px solid rgba(41,171,226,.1);border-radius:9px;padding:.6rem .7rem;cursor:pointer}
.rc-srccard h5{font-size:.74rem;font-weight:800;display:flex;align-items:center;gap:.35rem}
.rc-srccard div{font-size:.62rem;color:rgba(255,255,255,.35);line-height:1.65;margin-top:.15rem}
.rc-row{display:flex;gap:.55rem;align-items:baseline;padding:.32rem 0;border-bottom:1px solid rgba(41,171,226,.05);font-size:.73rem}
.rc-row .ep{font-family:ui-monospace,monospace;color:#29ABE2;font-weight:700;min-width:62px}
.rc-row .why{color:rgba(255,255,255,.38);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rc-pill{display:inline-block;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;padding:1px 7px;border-radius:20px}
.rc-mode{display:flex;gap:.4rem;flex-wrap:wrap}
.rc-mode label{border:1px solid rgba(41,171,226,.16);border-radius:8px;padding:.45rem .7rem;cursor:pointer;font-size:.73rem;background:#111a28}
.rc-mode input{display:none}
.rc-mode input:checked+span{color:#29ABE2;font-weight:800}
.rc-mode label:has(input:checked){border-color:rgba(41,171,226,.5);background:rgba(41,171,226,.07)}
.rc-mode small{display:block;font-size:.6rem;color:rgba(255,255,255,.28);margin-top:.1rem}
.rc-adv{border:1px solid rgba(41,171,226,.1);border-radius:10px;padding:0 .9rem;background:#0e1420}
.rc-adv summary{cursor:pointer;padding:.7rem 0;font-size:.76rem;font-weight:800;color:rgba(255,255,255,.55);list-style:none}
.rc-adv summary::-webkit-details-marker{display:none}
.rc-adv summary:before{content:'▸ ';color:#29ABE2}
.rc-adv[open] summary:before{content:'▾ '}
.rc-in{width:74px;padding:.32rem .45rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:6px;color:#eef2f8;font-size:.76rem;outline:none}
.rc-scroll{max-height:280px;overflow-y:auto}
.rc-log{font-size:.68rem;font-family:ui-monospace,monospace;line-height:1.75}
.rc-log .t{color:rgba(255,255,255,.22)}
@media(max-width:640px){
  .rc-nums{gap:.25rem .6rem;font-size:.7rem}
  .rc-buckets,.rc-src{grid-template-columns:1fr 1fr}
  .rc-row .why{white-space:normal}
}
</style>

<div class="at">
  <h1>Auto Sync</h1>
  <p>Research the archive against every public source, weigh the evidence, and write only what it supports.</p>
</div>

<?php if (!$researchOk): ?>
<div class="alert alert-warn" style="margin-bottom:1rem">
  <strong>Research tables are not installed yet.</strong><br>
  <span class="sc-meta">Run state, the persistent queue, evidence and decisions all need them. Syncing still works without them, but a completed run will not survive a refresh.</span>
  <div style="margin-top:.6rem">
    <button class="btn btn-sm" onclick="installTables(this)">Install research tables</button>
    <span class="sc-meta">or run <code>database/research_engine.sql</code> in phpMyAdmin</span>
  </div>
</div>
<?php endif; ?>

<!-- ══ 1. ARCHIVE COVERAGE — does the episode exist? (PR11 §4/§15) ═══ -->
<div class="sc-meta" style="margin-bottom:.4rem">Archive Coverage — episode existence, never metadata enrichment</div>
<div class="rc-grid" style="margin-bottom:1.3rem">
  <div class="rc-stat"><b style="color:#FFD700"><?= number_format($archive['stored_episodes']) ?></b><span>Stored episodes</span></div>
  <div class="rc-stat"><b><?= $archive['archive_latest'] ? 'EP'.(int)$archive['archive_latest'] : '—' ?></b><span>Archive latest</span></div>
  <div class="rc-stat">
    <b id="netLatest" style="color:<?= $archive['latest_verified_aired'] ? '#86efac' : 'rgba(255,255,255,.3)' ?>"
       title="<?= h($archive['decision_note']) ?>">
      <?= $archive['latest_verified_aired'] ? 'EP'.(int)$archive['latest_verified_aired']
          : ($archive['decision']==='SOURCE_DISAGREEMENT' ? 'Source disagreement'
             : ($archive['decision']==='NOT_CHECKED' ? 'Not checked' : 'Unknown')) ?>
    </b><span>Latest verified aired</span>
  </div>
  <div class="rc-stat"><b id="netUpcoming" style="color:<?= $archive['upcoming'] ? '#29ABE2' : 'rgba(255,255,255,.3)' ?>">
      <?= $archive['upcoming'] ? 'EP'.(int)$archive['upcoming'] : 'None verified' ?>
    </b><span>Upcoming</span></div>
  <div class="rc-stat"><b style="color:<?= $archive['missing_count'] ? '#fcd34d' : '#4ade80' ?>"><?= number_format($archive['missing_count']) ?></b><span>Missing episodes</span>
    <?php if ($archive['missing_range']): ?><span class="sc-meta" style="margin-top:.15rem"><?= h($archive['missing_range']) ?></span><?php endif; ?>
  </div>
</div>

<!-- ══ 2. METADATA HEALTH — core vs enrichment (PR11 §5/§16) ═══════ -->
<div class="sc-meta" style="margin-bottom:.4rem">Metadata Health — title + air date only; enrichment gaps are Insufficient Evidence below, not "incomplete episodes"</div>
<div class="rc-grid" style="margin-bottom:1.3rem">
  <div class="rc-stat"><b style="color:<?= $archive['core']['pct'] >= 95 ? '#4ade80' : '#fcd34d' ?>"><?= (int)$archive['core']['pct'] ?>%</b><span>Core complete</span></div>
  <div class="rc-stat"><b style="color:<?= $archive['core']['core_partial'] ? '#fcd34d' : '#4ade80' ?>"><?= number_format($archive['core']['core_partial']) ?></b><span>Core partial<br><span style="text-transform:none;letter-spacing:0">(missing title/air date)</span></span></div>
</div>

<!-- ══ 3. RESEARCH STATE (PR11 §6) ═════════════════════════════════ -->
<div class="sc-meta" style="margin-bottom:.4rem">Research State — what the research system knows, separate from source health</div>
<div class="rc-grid">
  <div class="rc-stat"><b style="color:<?= $archive['conflicts'] ? '#f87171' : 'inherit' ?>"><?= (int)$archive['conflicts'] ?></b><span>Conflicts</span></div>
  <div class="rc-stat"><b style="color:<?= $S['reviews'] ? '#fcd34d' : 'inherit' ?>"><?= (int)$S['reviews'] ?></b><span>Needs review</span></div>
  <div class="rc-stat"><b><?= (int)$archive['stale'] ?></b><span>Stale</span></div>
  <div class="rc-stat"><b><?= (int)$archive['never_researched'] ?></b><span>Never researched</span></div>
  <div class="rc-stat"><b><?= (int)($archive['locked_episodes'] ?? 0) ?></b><span>Locked fields<br><span style="text-transform:none;letter-spacing:0">(manually pinned)</span></span></div>
</div>

<!-- ══ 2. CURRENT RUN ═════════════════════════════════════════ -->
<div class="rc-banner" id="currentRun" style="<?= $current ? '' : 'display:none' ?>">
  <h3><span id="curIcon" style="color:#29ABE2"><?= h($current['label']['icon'] ?? '●') ?></span>
      Current run — <span id="curStatus"><?= h($current['label']['text'] ?? 'Running') ?></span></h3>
  <div class="rc-ref" id="curRef"><?= h($current['ref'] ?? '') ?></div>
  <div class="rc-bar"><div id="curBar" style="width:<?= (int)($current['percent'] ?? 0) ?>%"></div></div>
  <div class="rc-nums" id="curNums"></div>
  <div class="sc-meta" id="curNow" style="margin-top:.35rem"></div>
  <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap">
    <button class="btn btn-sm btn-dark" id="btnPause"  onclick="runControl('pause')">Ⅱ Pause</button>
    <button class="btn btn-sm"          id="btnResume" onclick="runControl('resume')" style="display:none">▶ Resume</button>
    <button class="btn btn-sm btn-ghost" onclick="runControl('cancel')">■ Cancel</button>
  </div>
</div>

<!-- ══ 3 + 4. LAST RUN, AND WHAT HAPPENED ═════════════════════ -->
<div class="rc-banner" id="lastRun" style="<?= $last ? '' : 'display:none' ?>">
  <h3><span id="lastIcon" style="color:<?= toneColour($last['label']['tone'] ?? 'idle') ?>"><?= h($last['label']['icon'] ?? '✓') ?></span>
      Last run — <span id="lastStatus"><?= h($last['label']['text'] ?? '') ?></span></h3>
  <div class="rc-ref" id="lastRef"><?= h(($last['ref'] ?? '') . ' · ' . ($last['scope'] ?? '')) ?></div>
  <div class="rc-nums" id="lastNums"></div>
  <div class="sc-meta" id="lastWhen" style="margin-top:.4rem"></div>
  <div style="margin-top:.7rem">
    <button class="btn btn-sm btn-dark" onclick="showReport()">View report</button>
  </div>
</div>
<div class="rc-banner" id="noRuns" style="<?= ($last || $current) ? 'display:none' : '' ?>">
  <h3 style="color:rgba(255,255,255,.4)">○ No sync runs yet</h3>
  <div class="sc-meta">Start one below. Whatever happens, the outcome is recorded and will still be here after a refresh.</div>
</div>

<!-- ══ RUN vs ARCHIVE ═════════════════════════════════════════ -->
<div class="alert alert-info" id="runVsArchive" style="margin-bottom:1.1rem;display:none">
  <strong>The run finished. The archive is still incomplete — that is expected.</strong><br>
  <span class="sc-meta" id="runVsArchiveText"></span>
</div>

<!-- ══ ACTIONS ════════════════════════════════════════════════ -->
<div class="ap">
  <div class="sh">Research</div>
  <div class="rc-mode" style="margin-bottom:.9rem">
    <?php foreach ($modes as $key => $m): ?>
    <label><input type="radio" name="rmode" value="<?= h($key) ?>" <?= $key === $defaultMode ? 'checked' : '' ?>>
      <span><?= h($m['label'] ?? $key) ?><small><?= h($m['hint'] ?? '') ?></small></span></label>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.85rem">
    <button class="btn btn-dark" onclick="detectLatest(this)">Detect latest</button>
    <button class="btn" onclick="startRun('new')" title="Genuinely absent episode numbers — a coverage gap">Research Missing Episodes</button>
    <button class="btn" onclick="startRun('missing')" title="Existing episodes with an empty enrichment field"
            >Fill Missing Metadata<span id="eligibleHint" class="sc-meta" style="margin-left:.35rem"></span></button>
    <button class="btn btn-dark" onclick="startRun('missing',{force:1,limit:200})">Research Everything (Metadata)</button>
    <button class="btn btn-dark" onclick="singleEpisode(false)">Single episode</button>
    <button class="btn btn-ghost" onclick="singleEpisode(true)">Dry run</button>
  </div>
  <div class="sc-meta" id="eligibleNote"></div>

  <details class="rc-adv" style="margin-top:.9rem">
    <summary>Advanced / manual</summary>
    <div style="padding-bottom:.9rem">
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;font-size:.76rem;color:rgba(255,255,255,.4);margin-bottom:.7rem">
        Sync range: EP <input type="number" id="sFrom" class="rc-in" value="1">
        to EP <input type="number" id="sTo" class="rc-in" value="<?= (int)$archive['db_max'] ?>">
        <button class="btn btn-sm btn-dark" onclick="startRun('range')">Sync range</button>
        <span>· delay <input type="number" id="sDelay" class="rc-in" style="width:56px" value="0.5" step="0.1" min="0"> s between episodes</span>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;font-size:.76rem;color:rgba(255,255,255,.4)">
        Single episode: EP <input type="number" id="sEp" class="rc-in" value="<?= (int)$archive['db_max'] ?>">
        <button class="btn btn-sm btn-dark" onclick="previewEpisode()">Preview changes</button>
        <button class="btn btn-sm btn-dark" onclick="inspectEpisode()">Inspect</button>
        <button class="btn btn-sm btn-ghost" onclick="retryEpisode()">Clear cool-down</button>
        <label style="display:flex;gap:.3rem;align-items:center"><input type="checkbox" id="sFresh"> ignore cache</label>
        <span>· max per run <input type="number" id="sLimit" class="rc-in" style="width:60px" value="50" min="1" max="200"></span>
      </div>
    </div>
  </details>
</div>

<!-- ══ 5. WHAT NEEDS ATTENTION ════════════════════════════════ -->
<div class="ap">
  <div class="sh">Archive attention</div>
  <div class="sc-meta" style="margin-bottom:.7rem">These are different problems wanting different responses — which is why they are not all called “needing sync”.</div>
  <div class="rc-buckets">
    <?php
    $bucketColour = ['new'=>'#29ABE2','never_researched'=>'#fcd34d','conflict'=>'#f87171',
                     'needs_review'=>'#fcd34d','failed'=>'#f87171','low_confidence'=>'#fcd34d',
                     'incomplete'=>'rgba(255,255,255,.55)','stale'=>'rgba(255,255,255,.4)'];
    foreach ($attention as $key => $b): ?>
    <div class="rc-bucket" onclick="showBucket('<?= h($key) ?>')">
      <b style="color:<?= $b['count'] ? ($bucketColour[$key] ?? 'inherit') : 'rgba(255,255,255,.2)' ?>"><?= (int)$b['count'] ?></b>
      <h5><?= h($b['label']) ?></h5>
      <p><?= h($b['hint']) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
  <div id="bucketList" style="display:none;margin-top:.9rem">
    <div class="sc-meta" id="bucketTitle" style="margin-bottom:.4rem"></div>
    <div class="rc-scroll" id="bucketRows"></div>
  </div>
</div>

<!-- ══ SOURCES ════════════════════════════════════════════════ -->
<div class="ap">
  <div class="sh" style="display:flex;justify-content:space-between;align-items:center">
    <span>Sources</span>
    <button class="btn btn-dark btn-sm" onclick="clearCooldowns(this)">Clear all cool-downs</button>
  </div>
  <div class="rc-src">
    <?php foreach ($S['sources'] as $name => $s): ?>
    <div class="rc-srccard" onclick="showSource('<?= h($name) ?>')">
      <h5><span style="color:<?= $s['colour'] ?>"><?= $s['dot'] ?></span> <?= h($s['label']) ?></h5>
      <div>
        <span style="color:<?= $s['colour'] ?>;font-weight:700"><?= h(strtoupper($s['status_text'])) ?></span>
        · <?= h($s['band']) ?> (<?= (int)$s['reputation'] ?>)
        <?php if ($s['avg_ms']): ?><br><?= (int)$s['avg_ms'] ?>ms avg<?php endif; ?>
        <?php if ($s['last_error']): ?><br><span style="color:rgba(252,165,165,.7)"><?= h(mb_strimwidth((string)$s['last_error'], 0, 60, '…')) ?></span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div id="sourceDetail" style="display:none;margin-top:.9rem"></div>
</div>

<!-- ══ CONFLICT INBOX ═════════════════════════════════════════ -->
<div class="ap" id="reviewPanel" style="<?= $S['reviews'] ? '' : 'display:none' ?>">
  <div class="sh">Needs review (<span id="reviewCount"><?= (int)$S['reviews'] ?></span>)</div>
  <div class="sc-meta" style="margin-bottom:.6rem">Held back deliberately: the evidence was not strong enough to overwrite what the archive already holds. Ignoring one keeps its evidence — nothing here is ever deleted.</div>
  <div class="rc-scroll" id="reviewRows"></div>
</div>

<!-- ══ ACTIVITY ═══════════════════════════════════════════════ -->
<div class="ap">
  <div class="sh">Activity</div>
  <div class="rc-scroll rc-log" id="activity">
    <?php foreach ($recentLog as $r): ?>
    <div><span class="t"><?= h(substr((string)$r['created_at'], 11, 8)) ?></span>
      <span style="color:<?= $r['status'] === 'success' ? '#86efac' : '#fca5a5' ?>"><?= $r['status'] === 'success' ? '✓' : '✗' ?></span>
      <?= $r['episode_number'] ? 'EP' . (int)$r['episode_number'] : '——' ?>
      <span style="color:rgba(255,255,255,.4)"><?= h(mb_strimwidth((string)$r['message'], 0, 110, '…')) ?></span></div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ DETAIL PANEL ═══════════════════════════════════════════ -->
<div class="ap" id="detailPanel" style="display:none">
  <div class="sh" id="detailTitle">Episode</div>
  <div id="detailBody"></div>
</div>

</main></div>
<script>
const BP = '<?= bp() ?>';
const API = BP + '/admin/auto_sync.php';
const ATTENTION = <?= json_encode(array_map(fn($b) => ['label'=>$b['label'],'hint'=>$b['hint'],'episodes'=>$b['episodes']], $attention), JSON_UNESCAPED_UNICODE) ?>;
let polling = false, stepping = false;

function esc(s){ const d=document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; }
function pad(n){ return 'EP' + String(n).padStart(3,'0'); }
function toast(m){ let t=document.getElementById('toast'); if(!t){t=document.createElement('div');t.id='toast';document.body.appendChild(t);} t.textContent=m; t.classList.add('show'); clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('show'),3000); }
function mode(){ const r=document.querySelector('input[name=rmode]:checked'); return r?r.value:'balanced'; }
function tone(t){ return {ok:'#4ade80',warn:'#fcd34d',bad:'#f87171',busy:'#29ABE2'}[t] || 'rgba(255,255,255,.45)'; }
function dur(ms){ if(!ms) return '—'; const s=Math.round(ms/1000); if(s<60) return s+'s'; const m=Math.floor(s/60); return m<60 ? m+'m '+(s%60)+'s' : Math.floor(m/60)+'h '+(m%60)+'m'; }

async function api(action, params){
  const q = new URLSearchParams(Object.assign({a:action}, params||{}));
  const r = await fetch(API + '?' + q.toString());
  const text = await r.text();
  try { return JSON.parse(text); }
  catch(e){ return {ok:false, error:'The server did not return JSON: ' + text.slice(0,120)}; }
}

// ── Rendering the two run banners ────────────────────────────
// The distinction the old page could not draw: a CURRENT run is
// progress, a LAST run is a result. They never share a box, and the
// result stays on screen after a refresh because it comes from the
// database, not from a variable.
function numsHtml(r){
  return [
    ['Requested', r.requested], ['Processed', r.processed],
    ['Updated', r.updated, '#4ade80'], ['No new data', r.no_data],
    ['Needs review', r.review, r.review ? '#fcd34d' : null],
    ['Failed', r.failed, r.failed ? '#f87171' : null],
    ['Remaining', r.remaining],
  ].map(([l,v,c]) => '<span><i>'+l+'</i> <b'+(c?' style="color:'+c+'"':'')+'>'+(v|0)+'</b></span>').join('');
}

function renderRun(s){
  const cur = document.getElementById('currentRun'), lastEl = document.getElementById('lastRun');
  document.getElementById('noRuns').style.display = (s.current || s.last) ? 'none' : '';

  if (s.current) {
    cur.style.display = '';
    document.getElementById('curIcon').textContent = s.current.label.icon;
    document.getElementById('curIcon').style.color = tone(s.current.label.tone);
    document.getElementById('curStatus').textContent = s.current.label.text;
    document.getElementById('curRef').textContent = s.current.ref + ' · ' + (s.current.scope||'') + (s.current.dry_run?' · DRY RUN':'');
    document.getElementById('curBar').style.width = (s.current.percent|0) + '%';
    document.getElementById('curNums').innerHTML = numsHtml(s.current);
    document.getElementById('curNow').textContent =
      s.current.processed + ' / ' + s.current.requested + ' episodes · ' + (s.current.percent|0) + '%';
    const paused = s.current.status === 'paused';
    document.getElementById('btnPause').style.display  = paused ? 'none' : '';
    document.getElementById('btnResume').style.display = paused ? '' : 'none';
  } else { cur.style.display = 'none'; }

  if (s.last) {
    lastEl.style.display = '';
    document.getElementById('lastIcon').textContent = s.last.label.icon;
    document.getElementById('lastIcon').style.color = tone(s.last.label.tone);
    document.getElementById('lastStatus').textContent = s.last.label.text;
    document.getElementById('lastRef').textContent = s.last.ref + ' · ' + (s.last.scope||'') + (s.last.dry_run?' · DRY RUN':'');
    document.getElementById('lastNums').innerHTML = numsHtml(s.last);
    document.getElementById('lastWhen').textContent =
      'Finished ' + (s.last.finished_at || '—') + ' · took ' + dur(s.last.duration_ms) +
      (s.last.avg_confidence != null ? ' · average confidence ' + s.last.avg_confidence + '%' : '') +
      (s.last.evidence ? ' · ' + s.last.evidence + ' pieces of evidence' : '');
  } else { lastEl.style.display = 'none'; }

  // The message the specification insists on: a finished run and an
  // incomplete archive are both true at once, and saying so removes
  // the only genuinely confusing thing about this page.
  const box = document.getElementById('runVsArchive');
  // s.attention.incomplete is the correctly-scoped count: episodes that
  // have ALREADY been researched and still lack an enrichment field no
  // source publishes (INSUFFICIENT_EVIDENCE) — never episodes that
  // simply haven't been looked at yet (those are "never researched").
  const insufficientEvidence = (s.attention && s.attention.incomplete) ? s.attention.incomplete.count : 0;
  if (s.last && !s.current && s.last.remaining === 0 && insufficientEvidence > 0) {
    box.style.display = '';
    document.getElementById('runVsArchiveText').textContent =
      'Run ' + s.last.ref + ' processed everything it queued (' + s.last.processed + ' of ' + s.last.requested +
      ', 0 remaining). ' + insufficientEvidence + ' episode(s) still have INSUFFICIENT EVIDENCE for an enrichment ' +
      'field, because no public source publishes it. Those are not waiting in a queue — they have been ' +
      'researched and recorded as such.';
  } else { box.style.display = 'none'; }

  const hint = document.getElementById('eligibleNote');
  hint.textContent = s.archive.incomplete
    ? s.archive.eligible + ' of ' + s.archive.incomplete + ' episodes with a metadata gap are due to be looked at again; ' +
      s.archive.resting + ' were researched recently and are resting until there is a reason to re-ask.'
    : 'Every episode in the archive has full core metadata.';
  document.getElementById('eligibleHint').textContent = s.archive.eligible ? '(' + s.archive.eligible + ')' : '';
  const netLatest = document.getElementById('netLatest');
  if (s.archive.latest_verified_aired) { netLatest.textContent = 'EP' + s.archive.latest_verified_aired; netLatest.style.color = '#86efac'; }
  else if (s.archive.decision === 'SOURCE_DISAGREEMENT') { netLatest.textContent = 'Source disagreement'; netLatest.style.color = '#fcd34d'; }
  document.getElementById('netUpcoming').textContent = s.archive.upcoming ? 'EP' + s.archive.upcoming : 'None verified';
}

async function refreshState(){
  const s = await api('state');
  if (!s.ok) return;
  renderRun(s);
  document.getElementById('reviewCount').textContent = s.reviews;
  document.getElementById('reviewPanel').style.display = s.reviews ? '' : 'none';
  if (s.reviews) loadReviews();
  return s;
}

// ── Driving a run ────────────────────────────────────────────
// The browser asks the server to take another step. It does not hold
// the queue, does not count anything, and cannot lose the run by
// being closed.
async function startRun(scope, extra){
  const p = Object.assign({
    scope: scope, mode: mode(),
    limit: document.getElementById('sLimit') ? document.getElementById('sLimit').value : 50,
  }, extra||{});
  if (scope === 'range'){ p.from = document.getElementById('sFrom').value; p.to = document.getElementById('sTo').value; }
  // Confirmed-aired ceiling only — never the raw "sources mention this
  // number" value, so an announced-but-unaired episode is never queued
  // as if it had already aired (PR11 §6/§9).
  if (scope === 'new'){ const d = await api('detect_latest'); p.latest = d.latest_verified_aired || d.archive_latest || 0; }

  const r = await api('run_start', p);
  if (!r.ok){ toast(r.error || 'Could not start the run'); await refreshState(); return; }
  toast('Started ' + r.run.ref + ' — ' + r.run.requested + ' episode(s) queued');
  await refreshState();
  pump();
}

async function pump(){
  if (stepping) return;
  stepping = true;
  try {
    for(;;){
      const delay = parseFloat((document.getElementById('sDelay')||{}).value || '0') * 1000;
      const r = await api('run_step', {delay: Math.round(delay)});
      if (!r.ok || r.idle){ break; }
      if (r.activity) appendActivity(r.activity);
      if (r.stepped) r.stepped.forEach(d => appendStep(d));
      const s = await refreshState();
      if (r.paused) { toast('Run paused'); break; }
      if (!s || !s.current) break;
    }
  } finally {
    stepping = false;
    const s = await refreshState();
    if (s && s.last && !s.current) toast(s.last.label.text + ' — ' + s.last.processed + ' processed');
  }
}

async function runControl(what){
  if (what === 'cancel' && !confirm('Cancel the run?\n\nWork already done is kept and recorded.')) return;
  const r = await api('run_' + what);
  if (!r.ok){ toast(r.error || 'No active run'); return; }
  await refreshState();
  if (what === 'resume') pump();
}

function appendStep(d){
  const colour = {completed:'#86efac', no_data:'rgba(255,255,255,.4)', needs_review:'#fcd34d', failed:'#fca5a5'}[d.outcome] || '#fff';
  const el = document.createElement('div');
  el.innerHTML = '<span class="t">' + new Date().toTimeString().slice(0,8) + '</span> ' +
    '<span style="color:' + colour + '">' + esc(d.outcome) + '</span> ' + pad(d.episode) +
    ' <span style="color:rgba(255,255,255,.4)">' + esc((d.reason||'').slice(0,100)) + '</span>';
  const log = document.getElementById('activity');
  log.insertBefore(el, log.firstChild);
}

function appendActivity(rows){
  const log = document.getElementById('activity');
  rows.slice(-40).reverse().forEach(function(a){
    const el = document.createElement('div');
    const colour = a.level === 'error' ? '#fca5a5' : (a.level === 'warning' ? '#fcd34d' : 'rgba(255,255,255,.42)');
    el.innerHTML = '<span class="t">' + esc(a.at) + '</span> <span style="color:' + colour + '">' + esc(a.message) + '</span>';
    log.insertBefore(el, log.firstChild);
  });
  while (log.children.length > 300) log.removeChild(log.lastChild);
}

// ── Latest-episode detection ─────────────────────────────────
// Evidence-based (RmLatestEpisode::detectDetailed(), PR9/PR10): aired vs
// upcoming vs source disagreement vs insufficient evidence. Never
// invents a number — an unreachable/disagreeing result says so in
// words instead of falling back to a guess.
async function detectLatest(btn){
  if (btn){ btn.disabled = true; btn.textContent = 'Asking sources…'; }
  const d = await api('detect_latest');
  if (btn){ btn.disabled = false; btn.textContent = 'Detect latest'; }
  if (!d.ok){ toast('No source could be asked'); return; }
  const netLatest = document.getElementById('netLatest');
  if (d.latest_verified_aired) { netLatest.textContent = 'EP' + d.latest_verified_aired; netLatest.style.color = '#86efac'; }
  else if (d.decision === 'SOURCE_DISAGREEMENT') { netLatest.textContent = 'Source disagreement'; netLatest.style.color = '#fcd34d'; }
  else { netLatest.textContent = 'Unknown'; netLatest.style.color = 'rgba(255,255,255,.3)'; }
  document.getElementById('netUpcoming').textContent = d.upcoming ? 'EP' + d.upcoming : 'None verified';

  const missing = d.missing_count > 0
    ? d.missing_count + ' missing episode(s)' + (d.missing_range ? ' (' + d.missing_range + ')' : '')
    : null;
  toast(missing
    ? missing + ': archive is at EP' + d.archive_latest + ', latest verified aired is EP' + d.latest_verified_aired
    : (d.latest_verified_aired ? 'Up to date at EP' + d.latest_verified_aired + ' (' + d.confidence + ' confidence)' : d.decision_note));
  if (d.conflict) appendActivity([{at:new Date().toTimeString().slice(0,8), level:'warning',
    message:'Sources disagree on the latest episode — ' + d.decision_note}]);
}

// ── Buckets ──────────────────────────────────────────────────
async function showBucket(key){
  const b = ATTENTION[key]; if (!b) return;
  const wrap = document.getElementById('bucketList');
  document.getElementById('bucketTitle').textContent = b.label + ' — ' + b.hint + ' (' + b.episodes.length + ')';
  const rows = document.getElementById('bucketRows');
  rows.innerHTML = b.episodes.length ? '' : '<div class="sc-meta">Nothing in this category.</div>';
  b.episodes.slice(0, 300).forEach(function(ep){
    const el = document.createElement('div');
    el.className = 'rc-row';
    el.innerHTML = '<span class="ep">' + pad(ep) + '</span><span class="why" id="why' + ep + '">…</span>' +
      '<button class="btn btn-sm btn-dark" onclick="inspectEpisode(' + ep + ')">Inspect</button>' +
      '<button class="btn btn-sm btn-ghost" onclick="startRun(\'single\',{ep:' + ep + ',force:1})">Research</button>';
    rows.appendChild(el);
  });
  wrap.style.display = '';
  // Every queued episode explains itself — an unexplained episode
  // number is what made the old list impossible to act on.
  for (const ep of b.episodes.slice(0, 40)) {
    const d = await api('episode', {ep: ep});
    const cell = document.getElementById('why' + ep);
    if (cell && d.ok) cell.textContent = (d.reasons || []).join(' · ');
  }
}

// ── Episode detail: the source matrix and the decisions ──────
async function inspectEpisode(ep){
  ep = ep || parseInt(document.getElementById('sEp').value, 10);
  const d = await api('episode', {ep: ep});
  if (!d.ok){ toast('Could not load ' + pad(ep)); return; }
  const r = d.research;

  let html = '<div class="sc-meta" style="margin-bottom:.6rem">' +
    '<b style="color:#29ABE2">' + esc(r.status.replace(/_/g,' ')) + '</b>' +
    ' · completeness ' + r.completeness + '%' +
    (r.confidence != null ? ' · confidence ' + r.confidence + '%' : '') +
    (r.last_researched ? ' · last researched ' + esc(r.last_researched) : ' · never researched') +
    (r.next_eligible ? ' · resting until ' + esc(r.next_eligible) : '') +
    '</div>';
  if (r.reason) html += '<div class="sc-meta" style="margin-bottom:.6rem">' + esc(r.reason) + '</div>';
  if (r.missing && r.missing.length) html += '<div class="sc-meta" style="margin-bottom:.7rem">Still missing: <b>' + r.missing.map(esc).join(', ') + '</b></div>';

  html += '<div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:rgba(41,171,226,.45);margin:.6rem 0 .3rem">Source matrix</div>';
  if (!d.sources.length) html += '<div class="sc-meta">No source has been recorded for this episode yet.</div>';
  d.sources.forEach(function(s){
    const colour = s.status === 'FOUND' ? '#86efac'
      : (s.status === 'NO_DATA' || s.status === 'NOT_APPLICABLE') ? 'rgba(255,255,255,.35)'
      : (s.status === 'ACCESS_RESTRICTED' || s.status === 'RATE_LIMITED') ? '#fcd34d' : '#fca5a5';
    html += '<div class="rc-row"><span class="ep">' + esc(s.source) + '</span>' +
      '<span class="rc-pill" style="background:rgba(255,255,255,.05);color:' + colour + '">' + esc(s.status) + '</span>' +
      '<span class="why">' + (s.fields.length ? s.fields.length + ' field(s): ' + s.fields.map(esc).join(', ') : 'no fields') +
      (s.http ? ' · HTTP ' + s.http : '') + (s.ms ? ' · ' + s.ms + 'ms' : '') + '</span></div>';
  });

  if (d.decisions.length){
    html += '<div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:rgba(41,171,226,.45);margin:.9rem 0 .3rem">Decisions</div>';
    d.decisions.forEach(function(x){
      const colour = {UPDATE:'#4ade80',FILL:'#4ade80',KEEP:'rgba(255,255,255,.4)',REVIEW:'#fcd34d',REJECT:'#fca5a5',UNKNOWN:'rgba(255,255,255,.25)'}[x.decision];
      html += '<div style="border-bottom:1px solid rgba(41,171,226,.05);padding:.4rem 0">' +
        '<div style="font-size:.74rem"><span class="ep" style="color:#29ABE2">' + esc(x.field_name) + '</span> ' +
        '<span class="rc-pill" style="background:rgba(255,255,255,.05);color:' + colour + '">' + esc(x.decision) + '</span> ' +
        '<span style="color:rgba(255,255,255,.35)">' + (x.confidence|0) + '% · ' + (x.independent_n|0) + ' independent</span></div>' +
        '<div class="sc-meta">' + esc(x.reason) + '</div>' +
        (x.supporting ? '<div class="sc-meta">Supported by: ' + esc(x.supporting) + (x.dissenting ? ' · disagreeing: ' + esc(x.dissenting) : '') + '</div>' : '') +
        '</div>';
    });
  }
  html += '<div style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">' +
    '<button class="btn btn-sm btn-dark" onclick="previewEpisode(' + ep + ')">Preview changes</button>' +
    '<button class="btn btn-sm" onclick="applySafe(' + ep + ')">Apply all safe changes</button>' +
    '<button class="btn btn-sm btn-ghost" onclick="retryEpisode(' + ep + ')">Clear cool-down</button></div>';

  document.getElementById('detailTitle').textContent = pad(ep) + ' — research detail';
  document.getElementById('detailBody').innerHTML = html;
  document.getElementById('detailPanel').style.display = '';
  document.getElementById('detailPanel').scrollIntoView({behavior:'smooth', block:'nearest'});
}

// ── Change preview: never write before showing the diff ──────
async function previewEpisode(ep){
  ep = ep || parseInt(document.getElementById('sEp').value, 10);
  toast('Researching ' + pad(ep) + ' without writing…');
  const fresh = document.getElementById('sFresh') && document.getElementById('sFresh').checked ? 1 : 0;
  const r = await api('preview', {ep: ep, mode: mode(), fresh: fresh});
  if (!r.ok){ toast('Preview failed'); return; }
  renderPreview(ep, r, true);
}

async function applySafe(ep){
  ep = ep || parseInt(document.getElementById('sEp').value, 10);
  const fresh = document.getElementById('sFresh') && document.getElementById('sFresh').checked ? 1 : 0;
  const r = await api('apply_safe', {ep: ep, mode: mode(), fresh: fresh});
  if (!r.ok){ toast('Failed'); return; }
  renderPreview(ep, r, false);
  toast(pad(ep) + ': ' + r.reason);
  refreshState();
}

function renderPreview(ep, r, isDry){
  let html = '<div class="sc-meta" style="margin-bottom:.6rem">' +
    '<b>' + esc(r.outcome.replace(/_/g,' ').toUpperCase()) + '</b>' + (isDry ? ' · DRY RUN, nothing was written' : '') +
    (r.confidence != null ? ' · confidence ' + r.confidence + '%' : '') + ' · ' + (r.ms|0) + 'ms</div>' +
    '<div class="sc-meta" style="margin-bottom:.7rem">' + esc(r.reason) + '</div>';

  const decs = Object.entries(r.decisions || {});
  if (!decs.length) html += '<div class="sc-meta">No source offered a value for any field.</div>';
  decs.forEach(function([field, d]){
    const colour = {UPDATE:'#4ade80',FILL:'#4ade80',KEEP:'rgba(255,255,255,.4)',REVIEW:'#fcd34d',REJECT:'#fca5a5',UNKNOWN:'rgba(255,255,255,.25)'}[d.decision];
    const from = d.existing == null || d.existing === '' ? 'EMPTY'
      : (Array.isArray(d.existing) ? d.existing.join(', ') : String(d.existing));
    const to = d.value == null ? '—' : (Array.isArray(d.value) ? d.value.join(', ') : String(d.value));
    html += '<div style="border-bottom:1px solid rgba(41,171,226,.05);padding:.45rem 0">' +
      '<div style="font-size:.75rem"><span class="ep" style="color:#29ABE2">' + esc(field) + '</span> ' +
      '<span class="rc-pill" style="background:rgba(255,255,255,.05);color:' + colour + '">' + esc(d.decision) + '</span> ' +
      '<span style="color:rgba(255,255,255,.3)">' + (d.confidence|0) + '%</span></div>' +
      (d.decision === 'KEEP' ? '' :
        '<div class="sc-meta"><span style="color:rgba(255,255,255,.3)">' + esc(from.slice(0,70)) + '</span> → <span style="color:#eef2f8">' + esc(to.slice(0,70)) + '</span></div>') +
      '<div class="sc-meta">' + esc(d.reason) + '</div>' +
      '<details style="margin-top:.15rem"><summary class="sc-meta" style="cursor:pointer">Why</summary>' +
      '<ul class="sc-meta" style="margin:.2rem 0 0 1rem">' + (d.why||[]).map(w => '<li>' + esc(w) + '</li>').join('') + '</ul></details>' +
      '</div>';
  });
  (r.anomalies||[]).forEach(function(a){
    html += '<div class="sc-meta" style="color:#fcd34d;margin-top:.4rem">⚠ ' + esc(a.message) + '</div>';
  });
  if (r.withheld && r.withheld.length)
    html += '<div class="sc-meta" style="margin-top:.5rem;color:#fcd34d">Withheld from the automatic write: ' + r.withheld.map(esc).join(', ') + '</div>';

  if (isDry) html += '<div style="margin-top:.9rem;display:flex;gap:.5rem">' +
    '<button class="btn btn-sm" onclick="applySafe(' + ep + ')">Apply all safe changes</button>' +
    '<button class="btn btn-sm btn-ghost" onclick="document.getElementById(\'detailPanel\').style.display=\'none\'">Cancel</button></div>';

  document.getElementById('detailTitle').textContent = pad(ep) + (isDry ? ' — proposed changes' : ' — applied');
  document.getElementById('detailBody').innerHTML = html;
  document.getElementById('detailPanel').style.display = '';
  document.getElementById('detailPanel').scrollIntoView({behavior:'smooth', block:'nearest'});
}

// ── Conflict inbox ───────────────────────────────────────────
async function loadReviews(){
  const d = await api('reviews');
  if (!d.ok) return;
  const rows = document.getElementById('reviewRows');
  rows.innerHTML = d.reviews.length ? '' : '<div class="sc-meta">Nothing waiting.</div>';
  d.reviews.forEach(function(x){
    const el = document.createElement('div');
    el.style.cssText = 'border-bottom:1px solid rgba(41,171,226,.05);padding:.5rem 0';
    el.innerHTML =
      '<div style="font-size:.75rem"><span class="ep" style="color:#29ABE2">' + pad(x.episode_number) + '</span> ' +
      '<b>' + esc(x.field_name) + '</b> <span style="color:rgba(255,255,255,.3)">' + (x.confidence|0) + '%</span></div>' +
      '<div class="sc-meta"><span style="color:rgba(255,255,255,.3)">' + esc((x.existing_value||'EMPTY').slice(0,60)) +
      '</span> → <span style="color:#eef2f8">' + esc((x.chosen_value||'—').slice(0,60)) + '</span></div>' +
      '<div class="sc-meta">' + esc(x.reason) + '</div>' +
      (x.supporting ? '<div class="sc-meta">Supported by ' + esc(x.supporting) + (x.dissenting ? ', disputed by ' + esc(x.dissenting) : '') + '</div>' : '') +
      '<div style="display:flex;gap:.4rem;margin-top:.35rem">' +
      '<button class="btn btn-sm btn-dark" onclick="inspectEpisode(' + x.episode_number + ')">Review</button>' +
      '<button class="btn btn-sm" onclick="resolveReview(' + x.decision_id + ',\'accepted\')">Accept</button>' +
      '<button class="btn btn-sm btn-dark" onclick="resolveReview(' + x.decision_id + ',\'kept_existing\')">Keep existing</button>' +
      '<button class="btn btn-sm btn-ghost" onclick="resolveReview(' + x.decision_id + ',\'ignored\')">Ignore</button></div>';
    rows.appendChild(el);
  });
}

async function resolveReview(id, outcome){
  const r = await api('review_resolve', {id: id, outcome: outcome});
  if (!r.ok){ toast(r.error || 'Could not record that'); return; }
  toast('Recorded — the evidence is kept either way');
  loadReviews(); refreshState();
}

// ── Sources ──────────────────────────────────────────────────
async function showSource(name){
  const s = await api('state');
  const src = s.sources[name]; if (!src) return;
  document.getElementById('sourceDetail').style.display = '';
  document.getElementById('sourceDetail').innerHTML =
    '<div class="sc-meta"><b style="color:' + src.colour + '">' + esc(src.label) + '</b> — ' + esc(src.status_text) +
    ' · reputation ' + src.reputation + ' (' + esc(src.band) + ')' +
    (src.avg_ms ? ' · ' + src.avg_ms + 'ms average' : '') +
    (src.last_success ? ' · last success ' + esc(src.last_success) : ' · no successful fetch recorded') + '<br>' +
    'Supplies: ' + src.fields.map(esc).join(', ') +
    (src.last_error ? '<br><span style="color:rgba(252,165,165,.75)">' + esc(src.last_error) + '</span>' : '') + '</div>';
}

async function clearCooldowns(btn){
  btn.disabled = true;
  await api('retry', {sources: 1});
  btn.disabled = false;
  toast('Cool-downs cleared — sources will be contacted again');
  location.reload();
}

// ── Odds and ends ────────────────────────────────────────────
async function retryEpisode(ep){
  ep = ep || parseInt(document.getElementById('sEp').value, 10);
  await api('retry', {ep: ep});
  toast(pad(ep) + ' is eligible for research again');
  refreshState();
}
function singleEpisode(dry){
  const ep = parseInt(document.getElementById('sEp').value, 10);
  if (!ep){ toast('Enter an episode number under Advanced'); return; }
  dry ? previewEpisode(ep) : startRun('single', {ep: ep, force: 1});
}
async function showReport(){
  const d = await api('run_report');
  if (!d.ok){ toast('No report'); return; }
  let html = '<div class="sc-meta" style="margin-bottom:.7rem">' +
    esc(d.run.ref) + ' · ' + esc(d.run.scope||'') + ' · ' + esc(d.run.label.text) +
    '<br>Started ' + esc(d.run.started_at) + ' · finished ' + esc(d.run.finished_at||'—') + ' · ' + dur(d.run.duration_ms) +
    '<br>' + d.run.requested + ' requested · ' + d.run.processed + ' processed · ' + d.run.updated + ' updated · ' +
    d.run.no_data + ' with no new data · ' + d.run.review + ' for review · ' + d.run.failed + ' failed' +
    (d.run.evidence ? '<br>' + d.run.evidence + ' pieces of evidence' : '') +
    (d.run.avg_confidence != null ? ' · average confidence ' + d.run.avg_confidence + '%' : '') + '</div>';
  d.items.forEach(function(i){
    const colour = {completed:'#86efac', no_data:'rgba(255,255,255,.4)', needs_review:'#fcd34d', failed:'#fca5a5'}[i.state] || '#fff';
    html += '<div class="rc-row"><span class="ep">' + pad(i.episode) + '</span>' +
      '<span class="rc-pill" style="background:rgba(255,255,255,.05);color:' + colour + '">' + esc(i.state) + '</span>' +
      '<span class="why">' + esc(i.result || i.reason || '') + '</span>' +
      (i.confidence != null ? '<span style="color:rgba(255,255,255,.3)">' + i.confidence + '%</span>' : '') + '</div>';
  });
  document.getElementById('detailTitle').textContent = 'Run report — ' + d.run.ref;
  document.getElementById('detailBody').innerHTML = html;
  document.getElementById('detailPanel').style.display = '';
  document.getElementById('detailPanel').scrollIntoView({behavior:'smooth', block:'nearest'});
}
async function installTables(btn){
  btn.disabled = true; btn.textContent = 'Installing…';
  const r = await api('install');
  toast(r.ok ? 'Tables installed' : 'Install reported errors — check the log');
  location.reload();
}

// On load: report state from the database. A run that was in flight
// when the tab closed is picked up here rather than lost.
refreshState().then(function(s){
  if (s && s.current && s.current.status === 'running') pump();
});
</script>
</body></html>
