<?php
// ============================================================
// Admin — Scraper Control Centre
//
// One page to see what the scraping system is doing and to drive it:
// live source health, the last run, an activity log, the change feed,
// review flags, and the targeted scraping actions (latest / missing /
// failed / range / thumbnails / full rescan) with a dry-run option.
//
// AJAX handlers run BEFORE layout.php: layout.php prints HTML the
// moment it is required, and a JSON response with a page glued in
// front of it breaks JSON.parse() on the client — the same trap
// documented in auto_sync.php.
// ============================================================
$adminTitle = 'Scraper Control Centre';
$adminPage  = 'scraper';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';
adminCheck();

const RM_SCRAPE_LOCK = 'scrape';

// Thin alias over the shared guard (includes/json_api.php), so this
// page and every other admin endpoint end a response the same way:
// buffered, warnings reported rather than printed, fatals still JSON.
function rmJson(array $payload, int $code = 200): never {
    rmJsonOut($payload, $code);
}

$action = $_GET['a'] ?? null;

// ── AJAX: install the scraping tables ─────────────────────────
if ($action === 'install') {
    try {
        $result = rmRunSqlFile(getDB(), __DIR__ . '/../database/scraping_engine.sql');
        rmScrapingTablesExist(true);
        $ready = rmScrapingTablesExist();
        rmJson([
            'ok'       => $ready && $result['ok'],
            'executed' => $result['executed'],
            'error'    => $result['errors'] ? implode(' · ', array_slice($result['errors'], 0, 3)) : null,
            'tables'   => rmScrapingTableStatus(),
        ]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: live source status ──────────────────────────────────
if ($action === 'health') {
    set_time_limit(60);
    try {
        $health = RmSourceHealth::instance();
        $probe  = !empty($_GET['probe']) ? $health->probeAll() : [];
        rmJson(['ok' => true, 'sources' => $health->all(), 'probe' => $probe]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: detect the latest episode across every signal ───────
if ($action === 'detect') {
    set_time_limit(90);
    try { rmJson(['ok' => true] + RmLatestEpisode::detect()); }
    catch (Throwable $e) { rmJson(['ok' => false, 'error' => $e->getMessage()]); }
}

// ── AJAX: preview what a mode WOULD do (no fetching) ──────────
if ($action === 'preview') {
    try {
        $md = new RmMissingData();
        $mode = (string)($_GET['mode'] ?? 'latest');
        $t = $md->targetsFor($mode, [
            'limit'   => (int)($_GET['limit'] ?? 50),
            'from'    => (int)($_GET['from'] ?? 1),
            'to'      => (int)($_GET['to'] ?? 0),
            'episode' => (int)($_GET['ep'] ?? 0),
            'latest'  => (int)($_GET['latest'] ?? 0),
        ]);
        rmJson(['ok' => true, 'label' => $t['label'], 'count' => count($t['episodes']),
                'episodes' => array_slice($t['episodes'], 0, 60), 'fields' => $t['fields']]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: scrape ONE episode (the unit the browser loop drives) ──
if ($action === 'episode') {
    set_time_limit(120);
    $ep = (int)($_GET['ep'] ?? 0);
    if ($ep < 1) rmJson(['ok' => false, 'error' => 'Enter a valid episode number']);
    try {
        $engine = new RmScrapingEngine();
        $opt = [
            'dry_run'      => !empty($_GET['dry']),
            'all_fields'   => !empty($_GET['all']),
            'bypass_cache' => !empty($_GET['fresh']),
        ];
        if (!empty($_GET['fields'])) $opt['fields'] = array_filter(explode(',', (string)$_GET['fields']));
        $r = $engine->syncEpisode($ep, $opt);

        rmJson([
            'ok'         => empty($r['failed']),
            'ep'         => $ep,
            'dry_run'    => !empty($opt['dry_run']),
            'skipped'    => !empty($r['skipped']),
            'is_new'     => !empty($r['is_new']),
            'reason'     => $r['reason'] ?? null,
            'applied'    => (int)($r['summary']['total_applied'] ?? 0),
            'lines'      => array_map([RmDiffEngine::class, 'renderLine'],
                             array_values(array_filter((array)($r['changes'] ?? []), fn($c) => $c['type'] !== 'unchanged'))),
            'confidence' => array_map(fn($x) => ['field' => $x[0], 'conf' => $x[1], 'source' => $x[2]],
                             array_map(fn($f, $x) => [$f, $x['confidence'] ?? null, $x['source'] ?? null],
                                       array_keys((array)($r['resolved'] ?? [])), array_values((array)($r['resolved'] ?? [])))),
            'sources'    => array_map(fn($m) => ['status' => $m['status'], 'ms' => $m['ms'], 'error' => $m['error'], 'cached' => $m['cached']],
                             (array)($r['meta'] ?? [])),
            'warnings'   => array_column((array)($r['warnings'] ?? []), 'message'),
            'thumbnail'  => $r['thumbnail'] ?? null,
            'duplicates' => $r['duplicate_suspects'] ?? [],
            'ms'         => (int)($r['ms'] ?? 0),
        ]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'ep' => $ep, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: run a whole mode server-side (bounded by a time limit) ──
if ($action === 'run') {
    set_time_limit(0);
    $mode = (string)($_GET['mode'] ?? 'latest');
    if (lockIsHeld('sync')) rmJson(['ok' => false, 'error' => 'Auto Sync is running — refusing to write concurrently']);
    if (!lockAcquire(RM_SCRAPE_LOCK, 3600)) rmJson(['ok' => false, 'error' => 'Another scraping run is already in progress']);
    try {
        $engine = new RmScrapingEngine();
        $out = $engine->runMode($mode, [
            'dry_run'    => !empty($_GET['dry']),
            'limit'      => max(1, min(500, (int)($_GET['limit'] ?? 25))),
            'from'       => (int)($_GET['from'] ?? 1),
            'to'         => (int)($_GET['to'] ?? 0),
            'episode'    => (int)($_GET['ep'] ?? 0),
            'time_limit' => max(10, min(600, (int)($_GET['seconds'] ?? 120))),
            'scope'      => 'admin control centre',
        ]);
        lockRelease(RM_SCRAPE_LOCK);
        logActivity('scrape', null, ($out['summary']['counts']['failed'] ?? 0) > 0 ? 'failed' : 'success',
            $mode . ': ' . json_encode($out['summary']['counts']), (int)($out['summary']['duration_ms'] ?? 0));
        rmJson(['ok' => true, 'summary' => $out['summary'], 'targets' => $out['targets']['label'] ?? '', 'paused' => $out['paused'] ?? false]);
    } catch (Throwable $e) {
        lockRelease(RM_SCRAPE_LOCK);
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: resume an interrupted run ───────────────────────────
if ($action === 'resume') {
    set_time_limit(0);
    try {
        $row = RmScrapeRun::resumable(5);
        if (!$row) rmJson(['ok' => false, 'error' => 'No interrupted run to resume']);
        if (!lockAcquire(RM_SCRAPE_LOCK, 3600)) rmJson(['ok' => false, 'error' => 'Another run is already in progress']);
        $out = (new RmScrapingEngine())->resume($row);
        lockRelease(RM_SCRAPE_LOCK);
        rmJson(['ok' => true, 'summary' => $out['summary'] ?? null, 'resumed' => $out['resumed'] ?? false]);
    } catch (Throwable $e) {
        lockRelease(RM_SCRAPE_LOCK);
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: verify stored thumbnails ────────────────────────────
if ($action === 'verify_thumbs') {
    set_time_limit(120);
    try {
        $te = new RmThumbnailEngine();
        $eps = $te->needingVerification(max(1, min(200, (int)($_GET['limit'] ?? 40))));
        $ok = 0; $bad = [];
        foreach ($eps as $ep) {
            $r = $te->verify($ep);
            if ($r['ok']) $ok++; else $bad[] = ['ep' => $ep, 'reason' => $r['reason']];
        }
        rmJson(['ok' => true, 'checked' => count($eps), 'valid' => $ok, 'broken' => $bad]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: archive-wide integrity sweep ────────────────────────
// Looks for possible duplicates across the WHOLE archive (shared air
// dates, identical normalised titles) and raises them for review.
// Nothing is merged or deleted — a two-part special legitimately shares
// an air date, so this is a human's decision every time.
if ($action === 'integrity') {
    set_time_limit(120);
    try {
        $dup   = new RmDuplicateDetector();
        $found = $dup->scanAll(300);
        $prov  = new RmProvenance();
        $raised = 0;
        foreach ($found as $f) {
            $eps = array_map('intval', explode(',', (string)$f['episodes']));
            $prov->flag('duplicate_episode', 'episode', null, $eps[0] ?? null,
                ucfirst(str_replace('_', ' ', (string)$f['type'])) . ' "' . mb_substr((string)$f['key'], 0, 80) . '" — EP' .
                implode(', EP', $eps) . '. ' . $f['note'] . ' Not merged or deleted: review manually.');
            $raised++;
        }
        rmJson(['ok' => true, 'found' => count($found), 'raised' => $raised, 'items' => array_slice($found, 0, 40)]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: enrich guest records via the registered person-lookup source ──
// Fills BLANK name_korean / profession / nationality only. An editor's
// existing value is never overwritten by a lookup, and a name without a
// confident match is left alone rather than guessed at. PR #4 removed
// Wikidata (the only adapter that ever implemented person lookup), so
// this currently reports every row "skipped" until a future source
// implements RmScraper::supportsPersonLookup() — degrading to a safe
// no-op rather than failing, exactly like every other optional feature.
if ($action === 'enrich_guests') {
    set_time_limit(180);
    try {
        $db    = getDB();
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        $rows  = $db->query(
            "SELECT guest_id, name_romanized FROM guests
              WHERE (name_korean IS NULL OR name_korean = '')
                 OR (profession IS NULL OR profession = '')
              ORDER BY guest_id LIMIT $limit"
        )->fetchAll();

        $gr = new RmGuestResolver($db);
        $enriched = 0; $skipped = 0; $details = [];
        foreach ($rows as $g) {
            $r = $gr->enrich((int)$g['guest_id'], (string)$g['name_romanized']);
            if (!empty($r['changed'])) {
                $enriched++;
                $details[] = $g['name_romanized'] . ' → ' . implode(', ', $r['changed']);
            } else {
                $skipped++;
            }
        }
        rmJson(['ok' => true, 'checked' => count($rows), 'enriched' => $enriched,
                'skipped' => $skipped, 'details' => array_slice($details, 0, 25)]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: activity log tail ───────────────────────────────────
if ($action === 'log') {
    try {
        $runId = isset($_GET['run']) ? (int)$_GET['run'] : null;
        rmJson(['ok' => true, 'lines' => RmScrapeRun::logFor($runId ?: null, (int)($_GET['limit'] ?? 120))]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: resolve a review flag ───────────────────────────────
if ($action === 'flag') {
    try {
        $ok = (new RmProvenance())->resolveFlag((int)($_GET['id'] ?? 0), (string)($_GET['status'] ?? 'resolved'));
        rmJson(['ok' => $ok]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: engine self-test (no network, no database writes) ───
if ($action === 'selftest') {
    try {
        require_once __DIR__ . '/../includes/scraping/selftest.php';
        rmJson(['ok' => true] + rmScrapingSelfTest());
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── AJAX: clear caches ────────────────────────────────────────
if ($action === 'flush_cache') {
    try {
        $type = $_GET['type'] ?? null;
        $n = RmCache::instance()->flush($type ?: null);
        RmSourceHealth::instance()->clearSuppression();
        rmJson(['ok' => true, 'removed' => $n, 'stats' => RmCache::instance()->stats()]);
    } catch (Throwable $e) {
        rmJson(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ============================================================
// Page render
// ============================================================
// A ?a= request that reached this point matched no handler above.
// Rendering the page would hand a JSON caller an HTML document.
rmJsonRejectUnknownAction();

require_once __DIR__ . '/layout.php';

$tablesReady   = rmScrapingTablesExist();
$tableStatus   = $tablesReady ? [] : rmScrapingTableStatus();
$health        = RmSourceHealth::instance()->all();
$latestRun     = RmScrapeRun::latest();
$recentRuns    = RmScrapeRun::recent(8);
$runStats      = RmScrapeRun::stats(7);
$prov          = new RmProvenance();
$flags         = $prov->openFlags(null, 25);
$changes       = $prov->recentChanges(25);
$md            = new RmMissingData();
$dbMax         = $md->maxEpisode();
$incomplete    = $tablesReady ? count($md->incompleteEpisodes(1000)) : 0;
$resumable     = RmScrapeRun::resumable(5);
$cacheStats    = RmCache::instance()->stats();
$matrix        = RmSourceRegistry::instance()->fieldMatrix();

$adminExtraCSS = <<<CSS
.sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.75rem}
.sc-card{background:#111a28;border:1px solid rgba(41,171,226,.12);border-radius:10px;padding:.85rem .95rem}
.sc-card h4{font-size:.82rem;font-weight:800;margin-bottom:.15rem;display:flex;align-items:center;gap:.4rem}
.sc-meta{font-size:.68rem;color:rgba(255,255,255,.4);line-height:1.7}
.sc-dot{font-size:.7rem}
.sc-stat{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:.6rem;margin-bottom:1rem}
.sc-stat div{background:#111a28;border:1px solid rgba(41,171,226,.1);border-radius:9px;padding:.7rem .85rem}
.sc-stat b{display:block;font-size:1.3rem;font-weight:900;letter-spacing:-.02em}
.sc-stat span{font-size:.6rem;text-transform:uppercase;letter-spacing:.09em;color:rgba(255,255,255,.32);font-weight:800}
.sc-actions{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.sc-log{background:#06090f;border-radius:8px;padding:.8rem;font-size:.71rem;font-family:ui-monospace,monospace;line-height:1.8;max-height:340px;overflow-y:auto;white-space:pre-wrap}
.sc-log .lv-error{color:#fca5a5}.sc-log .lv-warning{color:#fcd34d}.sc-log .lv-info{color:rgba(255,255,255,.55)}
.sc-log .ts{color:rgba(41,171,226,.5)}
.conf-high{color:#4ade80}.conf-medium{color:#facc15}.conf-low{color:#fb923c}.conf-conflict{color:#f87171;font-weight:800}
.sc-in{width:88px;padding:.4rem .6rem;border:1px solid rgba(41,171,226,.16);border-radius:7px;background:#141c2c;color:#eef2f8;font-size:.8rem;font-family:inherit}
.sc-matrix td:first-child{font-weight:700;color:rgba(255,255,255,.75)}
.sc-flag{border-left:3px solid #facc15;background:rgba(250,204,21,.06);padding:.55rem .75rem;border-radius:0 7px 7px 0;margin-bottom:.5rem;font-size:.78rem}
CSS;
?>
<style><?= $adminExtraCSS ?></style>

<div class="at">
  <h1>🛰️ Scraper Control Centre</h1>
  <p>Source health, targeted scraping, change history and data-quality review — all in one place.</p>
</div>

<?php if (!$tablesReady): ?>
<div class="alert alert-warn" style="margin-bottom:1.2rem">
  <div>
    <strong>Scraping engine tables are not installed yet.</strong><br>
    Health history, provenance, change tracking, review flags and run logs stay empty until they exist.
    Scraping itself still works without them.
    <div style="margin-top:.6rem">
      <button class="btn btn-sm" onclick="installTables(this)">Install tables now</button>
      <span class="sc-meta">or run <code>database/scraping_engine.sql</code> in phpMyAdmin</span>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($resumable): ?>
<div class="alert alert-warn" style="margin-bottom:1.2rem">
  <div>
    <strong>Run #<?= (int)$resumable['run_id'] ?> was interrupted</strong>
    (<?= h((string)$resumable['mode']) ?>, started <?= h((string)$resumable['started_at']) ?>).
    Its remaining episodes were checkpointed.
    <div style="margin-top:.5rem"><button class="btn btn-sm btn-yel" onclick="act('resume',{},this)">▶ Resume it</button></div>
  </div>
</div>
<?php endif; ?>

<!-- ── Source status ───────────────────────────────────────── -->
<div class="ap">
  <div class="sh" style="display:flex;justify-content:space-between;align-items:center">
    <span>Scraper Status</span>
    <button class="btn btn-dark btn-sm" onclick="refreshHealth(true,this)">⟳ Probe all sources</button>
  </div>
  <div class="sc-grid" id="srcGrid">
    <?php foreach ($health as $name => $h):
        [$label, $colour, $dot] = RmSourceHealth::present((string)$h['status']); ?>
    <div class="sc-card" data-src="<?= h($name) ?>">
      <h4><span class="sc-dot" style="color:<?= $colour ?>"><?= $dot ?></span> <?= h((string)$h['label']) ?></h4>
      <div class="sc-meta">
        <span style="color:<?= $colour ?>;font-weight:700"><?= h($label) ?></span>
        · <?= h((string)($h['class'] ?? '?')) ?> · tier <?= (int)$h['tier'] ?>
        <?php if ($h['success_rate'] !== null): ?> · <?= (int)$h['success_rate'] ?>% success<?php endif; ?>
        <?php if ($h['avg_ms']): ?> · <?= (int)$h['avg_ms'] ?>ms avg<?php endif; ?>
        <br>
        <?php if ($h['last_success_at']): ?>Last success: <?= h(substr((string)$h['last_success_at'], 0, 16)) ?><br><?php endif; ?>
        <?php if (!empty($h['parser_warnings'])): ?><span style="color:#fcd34d">Parser warnings: <?= (int)$h['parser_warnings'] ?></span><br><?php endif; ?>
        <?php if (!empty($h['suppressed_until']) && strtotime((string)$h['suppressed_until']) > time()): ?>
          <span style="color:#fb923c">Cooling down until <?= h(substr((string)$h['suppressed_until'], 11, 5)) ?></span><br>
        <?php endif; ?>
        <?php if (!empty($h['last_error'])): ?>
          <span style="color:rgba(252,165,165,.75)"><?= h(mb_substr((string)$h['last_error'], 0, 110)) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── Latest run ──────────────────────────────────────────── -->
<div class="ap">
  <div class="sh">Latest Run</div>
  <?php if ($latestRun): ?>
  <div class="sc-stat">
    <div><b><?= (int)$latestRun['episodes_checked'] ?></b><span>Checked</span></div>
    <div><b style="color:#4ade80"><?= (int)$latestRun['episodes_added'] ?></b><span>Added</span></div>
    <div><b style="color:#29ABE2"><?= (int)$latestRun['episodes_updated'] ?></b><span>Updated</span></div>
    <div><b style="color:rgba(255,255,255,.45)"><?= (int)$latestRun['episodes_skipped'] ?></b><span>Skipped</span></div>
    <div><b style="color:<?= (int)$latestRun['episodes_failed'] ? '#f87171' : 'rgba(255,255,255,.45)' ?>"><?= (int)$latestRun['episodes_failed'] ?></b><span>Failed</span></div>
    <div><b><?= $latestRun['duration_ms'] ? round((int)$latestRun['duration_ms'] / 1000, 1) . 's' : '—' ?></b><span>Duration</span></div>
  </div>
  <div class="sc-meta">
    Run #<?= (int)$latestRun['run_id'] ?> · <?= h((string)$latestRun['mode']) ?>
    <?= !empty($latestRun['dry_run']) ? ' · DRY RUN' : '' ?>
    · <?= h((string)$latestRun['status']) ?>
    · started <?= h((string)$latestRun['started_at']) ?>
    <?php
      // Five separate numbers, because they call for five different
      // responses. "skipped" used to absorb the other four, which made a
      // run in which nothing was wrong read as a wall of skips.
      $sEmpty  = array_key_exists('sources_empty',  $latestRun) ? (int)$latestRun['sources_empty']  : null;
      $sWarned = array_key_exists('sources_warned', $latestRun) ? (int)$latestRun['sources_warned'] : null;
    ?>
    <br>sources:
    <b><?= (int)$latestRun['sources_ok'] ?></b> gave data
    <?php if ($sEmpty !== null): ?>· <b><?= $sEmpty ?></b> healthy but had nothing for those episodes<?php endif; ?>
    <?php if ($sWarned !== null): ?>· <b style="color:<?= $sWarned ? '#fcd34d' : 'inherit' ?>"><?= $sWarned ?></b> reachable but parsed nothing<?php endif; ?>
    · <b style="color:<?= (int)$latestRun['sources_failed'] ? '#f87171' : 'inherit' ?>"><?= (int)$latestRun['sources_failed'] ?></b> unreachable
    · <b><?= (int)$latestRun['sources_skipped'] ?></b> never contacted
  </div>
  <?php else: ?>
  <div class="sc-meta">No runs recorded yet<?= $tablesReady ? '.' : ' — install the engine tables to start recording them.' ?></div>
  <?php endif; ?>
  <div class="sc-meta" style="margin-top:.5rem">
    Archive maximum: <strong>EP<?= (int)$dbMax ?></strong> ·
    Episodes with at least one empty field: <strong><?= (int)$incomplete ?></strong> ·
    Cache: <?= (int)$cacheStats['files'] ?> files, <?= round((int)$cacheStats['bytes'] / 1024) ?> KB
  </div>
</div>

<!-- ── Actions ─────────────────────────────────────────────── -->
<div class="ap">
  <div class="sh">Actions</div>
  <div class="sc-actions" style="margin-bottom:.75rem">
    <label class="sc-meta" style="display:flex;align-items:center;gap:.35rem">
      <input type="checkbox" id="dryRun"> Dry run (preview only, writes nothing)
    </label>
    <label class="sc-meta" style="display:flex;align-items:center;gap:.35rem">
      <input type="checkbox" id="freshFetch"> Bypass cache
    </label>
    <span class="sc-meta">Batch size <input class="sc-in" id="batchLimit" type="number" value="25" min="1" max="500"></span>
    <span class="sc-meta">Time budget <input class="sc-in" id="budget" type="number" value="120" min="10" max="600">s</span>
  </div>
  <div class="sc-actions">
    <button class="btn btn-sm" onclick="act('run',{mode:'latest'},this)">⚡ Sync Latest</button>
    <button class="btn btn-sm" onclick="act('run',{mode:'missing'},this)">🧩 Fill Missing Data</button>
    <button class="btn btn-sm" onclick="act('run',{mode:'failed'},this)">↻ Retry Failed</button>
    <button class="btn btn-sm" onclick="act('run',{mode:'unstable'},this)">⚠ Recheck Conflicted</button>
    <button class="btn btn-sm btn-dark" onclick="act('verify_thumbs',{limit:40},this)">🖼️ Verify Thumbnails</button>
    <button class="btn btn-sm btn-dark" onclick="detectLatest(this)">🔎 Detect Latest Episode</button>
  </div>
  <div class="sc-actions" style="margin-top:.6rem">
    <span class="sc-meta">Range</span>
    <input class="sc-in" id="rFrom" type="number" placeholder="from" value="<?= max(1, $dbMax - 20) ?>">
    <input class="sc-in" id="rTo" type="number" placeholder="to" value="<?= max(1, $dbMax) ?>">
    <button class="btn btn-sm btn-dark" onclick="act('run',{mode:'range',from:v('rFrom'),to:v('rTo')},this)">Scrape Range</button>
    <span style="width:1rem"></span>
    <span class="sc-meta">Single</span>
    <input class="sc-in" id="oneEp" type="number" placeholder="ep" value="<?= max(1, $dbMax) ?>">
    <button class="btn btn-sm btn-dark" onclick="oneEpisode(false,this)">Scrape</button>
    <button class="btn btn-sm btn-outline" onclick="oneEpisode(true,this)">Dry Run</button>
  </div>
  <div class="ar">
    <button class="btn btn-sm btn-dark" onclick="if(confirm('Full rescan re-checks every episode in the batch window. Continue?'))act('run',{mode:'full',from:v('rFrom'),to:v('rTo')},this)">🔁 Full Rescan (windowed)</button>
    <button class="btn btn-sm btn-dark" onclick="integrityScan(this)">🔍 Integrity Scan</button>
    <button class="btn btn-sm btn-dark" onclick="enrichGuests(this)">👤 Enrich Guest Records</button>
    <button class="btn btn-sm btn-ghost" onclick="act('flush_cache',{},this)">🧹 Clear Cache &amp; Cool-downs</button>
    <button class="btn btn-sm btn-ghost" onclick="selfTest(this)">🧪 Engine Self-Test</button>
  </div>
  <div id="out" style="margin-top:1rem"></div>
</div>

<!-- ── Activity log ────────────────────────────────────────── -->
<div class="ap">
  <div class="sh" style="display:flex;justify-content:space-between;align-items:center">
    <span>Scraper Activity Log</span>
    <button class="btn btn-dark btn-sm" onclick="loadLog(this)">⟳ Refresh</button>
  </div>
  <div class="sc-log" id="logBox">Press Refresh to load the most recent log lines.</div>
</div>

<!-- ── Changes + flags ─────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.4rem">
  <div class="ap">
    <div class="sh">Recent Changes</div>
    <?php if (!$changes): ?>
      <div class="sc-meta">No recorded changes yet.</div>
    <?php else: ?>
    <table class="atable">
      <thead><tr><th>EP</th><th>Field</th><th>Change</th><th>Source</th><th>Conf.</th></tr></thead>
      <tbody>
      <?php foreach ($changes as $c): ?>
        <tr>
          <td><a href="<?= bp() ?>/episode.php?ep=<?= (int)$c['episode_number'] ?>" target="_blank">EP<?= (int)$c['episode_number'] ?></a></td>
          <td><?= h((string)$c['field_name']) ?></td>
          <td style="max-width:230px">
            <span style="color:<?= $c['change_type'] === 'rejected' ? '#fca5a5' : ($c['applied'] ? '#86efac' : 'rgba(255,255,255,.5)') ?>">
              <?= h(str_replace('_', ' ', (string)$c['change_type'])) ?>
            </span>
            <?php if (!empty($c['reason'])): ?><br><span class="sc-meta"><?= h(mb_substr((string)$c['reason'], 0, 90)) ?></span><?php endif; ?>
          </td>
          <td class="sc-meta"><?= h((string)($c['source_name'] ?? '—')) ?></td>
          <td class="conf-<?= h((string)($c['confidence'] ?? '')) ?>"><?= h(strtoupper((string)($c['confidence'] ?? '—'))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="ap">
    <div class="sh">Needs Review</div>
    <?php if (!$flags): ?>
      <div class="sc-meta">Nothing flagged. Duplicates, near-identical guest names and source conflicts appear here — they are never resolved automatically.</div>
    <?php else: ?>
      <?php foreach ($flags as $f): ?>
      <div class="sc-flag" id="flag<?= (int)$f['flag_id'] ?>">
        <strong><?= h(str_replace('_', ' ', (string)$f['flag_type'])) ?></strong>
        <?php if ($f['episode_number']): ?> · EP<?= (int)$f['episode_number'] ?><?php endif; ?>
        <div class="sc-meta" style="color:rgba(255,255,255,.6)"><?= h((string)$f['detail']) ?></div>
        <div style="margin-top:.35rem">
          <button class="btn btn-sm btn-ghost" onclick="resolveFlag(<?= (int)$f['flag_id'] ?>,'resolved')">Mark resolved</button>
          <button class="btn btn-sm btn-ghost" onclick="resolveFlag(<?= (int)$f['flag_id'] ?>,'ignored')">Ignore</button>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── Field priority matrix ───────────────────────────────── -->
<div class="ap">
  <div class="sh">Field-Level Source Priority</div>
  <div class="sc-meta" style="margin-bottom:.7rem">
    Each field is won by the first source in its own list that returns a value passing validation —
    there is deliberately no single global ranking. Edit these in <code>config/scraping.php</code>.
  </div>
  <div class="sc-meta" style="margin-bottom:.9rem;line-height:1.9">
    <strong style="color:rgba(41,171,226,.7)">Source classes</strong> decide what a source may
    <em>overwrite</em>, separately from which source wins a field:
    <span style="color:#4ade80">primary</span> the broadcaster itself ·
    <span style="color:#29ABE2">secondary</span> substantial edited coverage ·
    <span style="color:#facc15">metadata</span> supplementary detail — may fill an empty field, but
    never replaces a value a stronger class recorded ·
    <span style="color:rgba(255,255,255,.4)">identity</span> person data, not episode data.
    <?php $byClass = []; foreach ($health as $n => $x) $byClass[$x['class'] ?? '?'][] = $n; ?>
    <br>
    <?php foreach (['primary','secondary','metadata','identity'] as $c): ?>
      <?php if (!empty($byClass[$c])): ?>
        <strong><?= h($c) ?>:</strong> <?= h(implode(', ', $byClass[$c])) ?>&nbsp;&nbsp;
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <table class="atable sc-matrix">
    <thead><tr><th style="width:150px">Field</th><th>Priority order</th></tr></thead>
    <tbody>
    <?php foreach ($matrix as $field => $order): ?>
      <tr><td><?= h($field) ?></td><td class="sc-meta"><?= h(implode('  →  ', $order)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── Run history ─────────────────────────────────────────── -->
<?php if ($recentRuns): ?>
<div class="ap">
  <div class="sh">Run History (7-day totals: <?= (int)($runStats['added'] ?? 0) ?> added, <?= (int)($runStats['updated'] ?? 0) ?> updated, <?= (int)($runStats['failed'] ?? 0) ?> failed)</div>
  <table class="atable">
    <thead><tr><th>#</th><th>Mode</th><th>Started</th><th>Status</th><th>Checked</th><th>Added</th><th>Updated</th><th>Failed</th><th>Time</th></tr></thead>
    <tbody>
    <?php foreach ($recentRuns as $r): ?>
      <tr>
        <td><a href="?run=<?= (int)$r['run_id'] ?>#logBox" onclick="loadLog(null,<?= (int)$r['run_id'] ?>);return false"><?= (int)$r['run_id'] ?></a></td>
        <td><?= h((string)$r['mode']) ?><?= !empty($r['dry_run']) ? ' <span class="sc-meta">(dry)</span>' : '' ?></td>
        <td class="sc-meta"><?= h((string)$r['started_at']) ?></td>
        <td style="color:<?= $r['status'] === 'completed' ? '#86efac' : ($r['status'] === 'running' ? '#fcd34d' : '#fca5a5') ?>"><?= h((string)$r['status']) ?></td>
        <td><?= (int)$r['episodes_checked'] ?></td>
        <td><?= (int)$r['episodes_added'] ?></td>
        <td><?= (int)$r['episodes_updated'] ?></td>
        <td><?= (int)$r['episodes_failed'] ?></td>
        <td class="sc-meta"><?= $r['duration_ms'] ? round((int)$r['duration_ms'] / 1000, 1) . 's' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
var BP = <?= json_encode(bp()) ?>;
function v(id){ return document.getElementById(id).value; }
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function out(html){ document.getElementById('out').innerHTML = html; }
function busy(btn,on){ if(!btn) return; btn.disabled = on; btn.dataset.t = btn.dataset.t || btn.textContent; btn.textContent = on ? 'Working…' : btn.dataset.t; }

function qs(extra){
  var p = new URLSearchParams(extra || {});
  if (document.getElementById('dryRun').checked)     p.set('dry','1');
  if (document.getElementById('freshFetch').checked) p.set('fresh','1');
  p.set('limit',   v('batchLimit'));
  p.set('seconds', v('budget'));
  return p.toString();
}

function api(action, extra){
  return fetch('scraper.php?a=' + action + '&' + qs(extra), {headers:{'X-Requested-With':'fetch'}})
    .then(function(r){ return r.json(); });
}

function act(action, extra, btn){
  busy(btn,true);
  out('<div class="sc-meta">Running <strong>' + esc(extra.mode || action) + '</strong>… <span class="spin"></span></div>');
  api(action, extra).then(function(d){
    busy(btn,false);
    if (!d.ok) { out('<div class="alert alert-err">' + esc(d.error || 'Failed') + '</div>'); return; }
    if (d.summary) {
      var c = d.summary.counts || {};
      out('<div class="alert alert-ok">' +
          '<div><strong>' + esc(d.targets || d.summary.mode) + '</strong>' + (d.summary.dry_run ? ' — DRY RUN, nothing written' : '') +
          '<br>checked ' + (c.checked|0) + ' · added ' + (c.added|0) + ' · updated ' + (c.updated|0) +
          ' · skipped ' + (c.skipped|0) + ' · failed ' + (c.failed|0) +
          '<br><span class="sc-meta">sources: ' + (c.src_ok|0) + ' gave data, ' + (c.src_empty|0) + ' had nothing for those episodes, ' +
          (c.src_warned|0) + ' parsed nothing, ' + (c.src_failed|0) + ' unreachable, ' + (c.src_skipped|0) + ' never contacted · ' +
          Math.round((d.summary.duration_ms||0)/100)/10 + 's' + (d.paused ? ' · paused at the time budget, resume to continue' : '') + '</span></div></div>');
      loadLog();
    } else if (d.checked !== undefined) {
      var bad = (d.broken||[]).map(function(b){ return 'EP' + b.ep + ': ' + esc(b.reason); }).join('<br>');
      out('<div class="alert ' + (d.broken && d.broken.length ? 'alert-warn' : 'alert-ok') + '"><div>' +
          'Verified ' + d.checked + ' thumbnail(s): ' + d.valid + ' valid' +
          (d.broken && d.broken.length ? ', ' + d.broken.length + ' broken<br><span class="sc-meta">' + bad + '</span>' : '') +
          '</div></div>');
    } else if (d.removed !== undefined) {
      out('<div class="alert alert-ok">Cleared ' + d.removed + ' cache file(s) and reset all source cool-downs.</div>');
    } else {
      out('<div class="alert alert-ok">Done.</div>');
    }
  }).catch(function(e){ busy(btn,false); out('<div class="alert alert-err">' + esc(e.message) + '</div>'); });
}

function oneEpisode(dry, btn){
  busy(btn,true);
  var p = new URLSearchParams({ep: v('oneEp'), all: '1'});
  if (dry || document.getElementById('dryRun').checked) p.set('dry','1');
  if (document.getElementById('freshFetch').checked) p.set('fresh','1');
  out('<div class="sc-meta">Scraping EP' + esc(v('oneEp')) + '… <span class="spin"></span></div>');
  fetch('scraper.php?a=episode&' + p.toString()).then(function(r){ return r.json(); }).then(function(d){
    busy(btn,false);
    if (!d.ok) { out('<div class="alert alert-err">EP' + d.ep + ': ' + esc(d.error || d.reason || 'failed') + '</div>'); return; }
    var h = '<div class="alert ' + (d.dry_run ? 'alert-info' : 'alert-ok') + '"><div>';
    h += '<strong>EP' + d.ep + '</strong> — ' + (d.skipped ? 'already complete, no sources contacted' :
         (d.is_new ? 'new episode' : d.applied + ' field(s) ' + (d.dry_run ? 'would change' : 'changed'))) +
         (d.dry_run ? ' · <strong>DRY RUN — nothing written</strong>' : '') + ' · ' + d.ms + 'ms';
    if (d.lines && d.lines.length) h += '<div class="sc-log" style="margin-top:.6rem;max-height:220px">' + d.lines.map(esc).join('\n') + '</div>';
    if (d.confidence && d.confidence.length) {
      h += '<div style="margin-top:.6rem" class="sc-meta">' + d.confidence.filter(function(c){return c.conf;}).map(function(c){
        return esc(c.field) + ': <span class="conf-' + esc(c.conf) + '">' + esc(String(c.conf).toUpperCase()) + '</span> <span style="opacity:.6">(' + esc(c.source) + ')</span>';
      }).join(' &nbsp;·&nbsp; ') + '</div>';
    }
    if (d.warnings && d.warnings.length) h += '<div style="margin-top:.5rem;color:#fcd34d" class="sc-meta">' + d.warnings.map(esc).join('<br>') + '</div>';
    if (d.duplicates && d.duplicates.length) h += '<div style="margin-top:.5rem;color:#fcd34d" class="sc-meta">Possible duplicate of EP' + d.duplicates.map(function(x){return x.episode_number;}).join(', EP') + ' — flagged for review</div>';
    if (d.thumbnail) h += '<div style="margin-top:.5rem" class="sc-meta">Thumbnail: ' + (d.thumbnail.ok ? 'ok' : 'not updated') + (d.thumbnail.reason ? ' — ' + esc(d.thumbnail.reason) : '') + '</div>';
    var srcs = Object.keys(d.sources || {});
    if (srcs.length) h += '<div style="margin-top:.5rem" class="sc-meta">' + srcs.map(function(s){
      var m = d.sources[s];
      return esc(s) + ': ' + esc(m.status) + (m.cached ? ' [cache]' : '') + (m.ms ? ' ' + m.ms + 'ms' : '') + (m.error ? ' — ' + esc(String(m.error).slice(0,70)) : '');
    }).join('<br>') + '</div>';
    h += '</div></div>';
    out(h);
  }).catch(function(e){ busy(btn,false); out('<div class="alert alert-err">' + esc(e.message) + '</div>'); });
}

function detectLatest(btn){
  busy(btn,true);
  out('<div class="sc-meta">Asking every source for its newest episode… <span class="spin"></span></div>');
  fetch('scraper.php?a=detect').then(function(r){ return r.json(); }).then(function(d){
    busy(btn,false);
    if (!d.ok) { out('<div class="alert alert-err">' + esc(d.error) + '</div>'); return; }
    var sig = Object.keys(d.signals || {}).map(function(k){ return esc(k) + ' = EP' + d.signals[k]; }).join(' · ');
    out('<div class="alert ' + (d.conflict ? 'alert-warn' : 'alert-ok') + '"><div>' +
        '<strong>Latest: EP' + (d.latest || '?') + '</strong> · confidence <span class="conf-' + esc(d.confidence) + '">' + esc(String(d.confidence).toUpperCase()) + '</span>' +
        '<br><span class="sc-meta">' + sig + '</span><br><span class="sc-meta">' + esc(d.note) + '</span></div></div>');
  }).catch(function(e){ busy(btn,false); out('<div class="alert alert-err">' + esc(e.message) + '</div>'); });
}

function selfTest(btn){
  busy(btn,true);
  fetch('scraper.php?a=selftest').then(function(r){ return r.json(); }).then(function(d){
    busy(btn,false);
    if (!d.ok) { out('<div class="alert alert-err">' + esc(d.error) + '</div>'); return; }
    var fails = (d.results||[]).filter(function(r){ return !r.pass; });
    out('<div class="alert ' + (d.ok && !fails.length ? 'alert-ok' : 'alert-err') + '"><div>' +
        '<strong>Engine self-test: ' + d.passed + '/' + d.total + ' checks passed</strong>' +
        (fails.length ? '<div class="sc-log" style="margin-top:.5rem">' + fails.map(function(f){
          return '[' + esc(f.group) + '] ' + esc(f.name) + '\n    expected ' + esc(f.expected) + ', got ' + esc(f.actual);
        }).join('\n') + '</div>' : '') + '</div></div>');
  }).catch(function(e){ busy(btn,false); out('<div class="alert alert-err">' + esc(e.message) + '</div>'); });
}

function integrityScan(btn){
  busy(btn,true);
  out('<div class="sc-meta">Scanning the archive for possible duplicates… <span class="spin"></span></div>');
  fetch('scraper.php?a=integrity').then(function(r){ return r.json(); }).then(function(d){
    busy(btn,false);
    if (!d.ok) { out('<div class="alert alert-err">' + esc(d.error) + '</div>'); return; }
    var rows = (d.items||[]).map(function(i){
      return '<tr><td>' + esc(String(i.type).replace(/_/g,' ')) + '</td><td>' + esc(String(i.key).slice(0,70)) +
             '</td><td>EP' + esc(String(i.episodes).split(',').join(', EP')) + '</td><td class="sc-meta">' + esc(i.note) + '</td></tr>';
    }).join('');
    out('<div class="alert ' + (d.found ? 'alert-warn' : 'alert-ok') + '"><div>' +
        (d.found ? d.found + ' possible duplicate group(s) found and raised for review — <strong>nothing was merged or deleted</strong>.' +
          '<table class="atable" style="margin-top:.6rem"><thead><tr><th>Type</th><th>Value</th><th>Episodes</th><th>Note</th></tr></thead><tbody>' + rows + '</tbody></table>'
          : 'No duplicate episodes detected.') + '</div></div>');
  }).catch(function(e){ busy(btn,false); out('<div class="alert alert-err">' + esc(e.message) + '</div>'); });
}

function enrichGuests(btn){
  busy(btn,true);
  out('<div class="sc-meta">Looking up guest identities… <span class="spin"></span></div>');
  fetch('scraper.php?a=enrich_guests&limit=25').then(function(r){ return r.json(); }).then(function(d){
    busy(btn,false);
    if (!d.ok) { out('<div class="alert alert-err">' + esc(d.error) + '</div>'); return; }
    out('<div class="alert alert-ok"><div>Checked ' + d.checked + ' guest record(s): ' + d.enriched + ' enriched, ' +
        d.skipped + ' left unchanged (no confident match, or already complete).' +
        (d.details && d.details.length ? '<div class="sc-log" style="margin-top:.5rem;max-height:180px">' + d.details.map(esc).join('\n') + '</div>' : '') +
        '<div class="sc-meta" style="margin-top:.4rem">Only blank fields are filled — an existing value is never overwritten by a lookup.</div>' +
        '</div></div>');
  }).catch(function(e){ busy(btn,false); out('<div class="alert alert-err">' + esc(e.message) + '</div>'); });
}

function loadLog(btn, runId){
  busy(btn,true);
  fetch('scraper.php?a=log&limit=150' + (runId ? '&run=' + runId : '')).then(function(r){ return r.json(); }).then(function(d){
    busy(btn,false);
    var box = document.getElementById('logBox');
    if (!d.ok || !d.lines || !d.lines.length) { box.textContent = d.ok ? 'No log entries yet.' : (d.error || 'Could not load the log.'); return; }
    box.innerHTML = d.lines.map(function(l){
      var t = String(l.created_at || '').slice(11,19);
      return '<span class="lv-' + esc(l.level) + '"><span class="ts">[' + esc(t) + ']</span> ' +
             (l.episode_number ? 'EP' + l.episode_number + ' ' : '') +
             (l.source_name ? esc(l.source_name) + ' ' : '') +
             esc(l.event) + (l.message ? ' — ' + esc(l.message) : '') +
             (l.duration_ms ? ' (' + l.duration_ms + 'ms)' : '') + '</span>';
    }).join('\n');
    box.scrollTop = box.scrollHeight;
  }).catch(function(e){ busy(btn,false); document.getElementById('logBox').textContent = e.message; });
}

function refreshHealth(probe, btn){
  busy(btn,true);
  fetch('scraper.php?a=health' + (probe ? '&probe=1' : '')).then(function(r){ return r.json(); }).then(function(d){
    busy(btn,false);
    if (!d.ok) { out('<div class="alert alert-err">' + esc(d.error) + '</div>'); return; }
    location.reload();
  }).catch(function(e){ busy(btn,false); out('<div class="alert alert-err">' + esc(e.message) + '</div>'); });
}

function installTables(btn){
  busy(btn,true);
  fetch('scraper.php?a=install').then(function(r){ return r.json(); }).then(function(d){
    busy(btn,false);
    if (d.ok) location.reload();
    else out('<div class="alert alert-err">' + esc(d.error || 'Install failed') + '</div>');
  }).catch(function(e){ busy(btn,false); out('<div class="alert alert-err">' + esc(e.message) + '</div>'); });
}

function resolveFlag(id, status){
  fetch('scraper.php?a=flag&id=' + id + '&status=' + status).then(function(r){ return r.json(); }).then(function(d){
    if (d.ok) { var el = document.getElementById('flag' + id); if (el) el.remove(); }
  });
}

loadLog();
</script>

</main></div>
</body></html>
