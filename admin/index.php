<?php
// ============================================================
// Admin Dashboard — PR #4 rework.
//
// The old dashboard answered "how many episodes exist?". This one
// answers the four questions every admin page has to (spec section 33):
// what is the archive's actual state, why does it need attention,
// what would fixing it involve, and where do you go to act on it.
//
// Deliberately DB-only: archive health, source health and last-run
// info all come from stored state (research_state, source_health,
// scrape_runs), never a live network probe — a dashboard that blocks
// on external requests on every page load is not a dashboard. Live
// verification (source probes, latest-episode detection) stays an
// explicit action on Diagnostics / the Research Centre, one click away.
// ============================================================
if (isset($_GET['s'])) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/functions.php';
    adminCheck();
    header('Content-Type: application/json');
    if (ob_get_level() > 0) ob_clean();

    $stats = getStats();
    $extra = [
        'health' => ['episodes'=>0,'completeness'=>0,'conflicts'=>0,'needs_review'=>0,
                      'stale'=>0,'low_confidence'=>0,'incomplete'=>0],
        // Archive Coverage (episode existence + evidence-based latest) is
        // a separate axis from 'health' above (metadata enrichment gaps)
        // — PR11 §2. DB-only here, same as everything else on this
        // endpoint: no live source probe on page load. Uses whatever
        // RmLatestEpisode::detectDetailed() result Auto Sync's explicit
        // "Detect Latest" last cached, or falls back to the archive
        // maximum with an honest "not checked" note if nothing has run
        // yet — never invents a number.
        'coverage' => ['stored_episodes'=>0,'archive_latest'=>null,'latest_verified_aired'=>null,
                        'upcoming'=>null,'decision'=>'NOT_CHECKED','decision_note'=>'',
                        'missing_count'=>0,'missing_range'=>null,'core'=>['pct'=>0,'core_partial'=>0]],
        'last_run'      => null,
        'source_health' => ['healthy' => 0, 'total' => 0],
    ];
    try {
        require_once __DIR__ . '/../includes/scraping/bootstrap.php';
        require_once __DIR__ . '/../includes/system.php';
        $state = new RmResearchState();
        $extra['health'] = $state->archiveHealth();
        $cachedDetection = json_decode((string)stateGet('latest_detection', ''), true);
        $extra['coverage'] = $state->archiveCoverage(200, is_array($cachedDetection) ? $cachedDetection : null);

        $run = RmScrapeRun::latest();
        if ($run) {
            $extra['last_run'] = [
                'mode'        => $run['mode'] ?? $run['research_mode'] ?? null,
                'status'      => $run['status'] ?? null,
                'finished_at' => $run['finished_at'] ?? $run['started_at'] ?? null,
                'summary'     => $run['summary'] ?? $run['notes'] ?? null,
            ];
        }

        $sources = RmSourceHealth::instance()->all();
        $healthyStatuses = ['ok', 'unknown'];   // never-yet-checked reads as healthy, not broken
        $extra['source_health'] = [
            'healthy' => count(array_filter($sources, fn($s) => in_array($s['status'] ?? 'unknown', $healthyStatuses, true))),
            'total'   => count($sources),
        ];
    } catch (Throwable $e) { /* PR #4 tables/engine not installed yet — dashboard still works */ }

    echo json_encode($stats + $extra);
    exit;
}

$adminTitle = 'Dashboard';
$adminPage  = 'dash';
require_once __DIR__ . '/layout.php';

$recent = getDB()->query(
    "SELECT episode_number, title, air_date, verification_required
     FROM episodes ORDER BY episode_number DESC LIMIT 10"
)->fetchAll();

$cronLog = @file_get_contents(__DIR__.'/../cron.log') ?: '';
$logLines = array_filter(array_slice(explode("\n", trim($cronLog)), -6));
?>

<div class="at"><h1>Dashboard</h1><p>Running Man Archive · <?= date('d M Y') ?></p></div>

<!-- Archive health — the four questions every admin page owes an answer to -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem;margin-bottom:1.6rem">
  <div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:13px;padding:1.2rem">
    <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-bottom:.5rem">Archive Coverage</div>
    <div style="display:flex;gap:1.1rem">
      <div>
        <div style="font-size:1.2rem;font-weight:900;color:#FFD700" id="st-archlatest">…</div>
        <div style="font-size:.62rem;color:rgba(255,255,255,.3)">Archive latest</div>
      </div>
      <div>
        <div style="font-size:1.2rem;font-weight:900" id="st-verifiedlatest">…</div>
        <div style="font-size:.62rem;color:rgba(255,255,255,.3)">Latest verified aired</div>
      </div>
      <div>
        <div style="font-size:1.2rem;font-weight:900" id="st-missingeps">…</div>
        <div style="font-size:.62rem;color:rgba(255,255,255,.3)">Missing episodes</div>
      </div>
    </div>
    <div style="font-size:.72rem;color:rgba(255,255,255,.4);margin-top:.4rem">
      <a href="<?= bp() ?>/admin/auto_sync.php" style="color:#29ABE2">Auto Sync → Detect Latest</a> to verify against live sources
    </div>
  </div>
  <div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:13px;padding:1.2rem">
    <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-bottom:.5rem">Last Research Run</div>
    <div id="st-lastrun" style="font-size:.95rem;font-weight:700;color:#eef2f8">Loading…</div>
    <div id="st-lastrun-detail" style="font-size:.72rem;color:rgba(255,255,255,.4);margin-top:.3rem"></div>
  </div>
  <div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:13px;padding:1.2rem">
    <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-bottom:.5rem">Source Health</div>
    <div id="st-srchealth" style="font-size:1.5rem;font-weight:900">…</div>
    <div style="font-size:.72rem;color:rgba(255,255,255,.4);margin-top:.3rem"><a href="<?= bp() ?>/admin/diagnostics.php" style="color:#29ABE2">View detail →</a></div>
  </div>
</div>

<!-- Stats — skeleton, loaded async -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:.8rem;margin-bottom:1.6rem">
  <?php foreach ([
    ['st-total', 'Episodes',      '#FFD700'],
    ['st-miss',  'Missing #s',    '#86efac'],
    ['st-syn',   'No Synopsis',   '#fcd34d'],
    ['st-thumb', 'No Thumbnail',  '#fcd34d'],
    ['st-review','Needs Review',  '#fca5a5'],
    ['st-pct',   'Complete',      '#29ABE2'],
  ] as [$id,$lbl,$col]): ?>
  <div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:13px;padding:1.1rem .9rem;text-align:center">
    <div id="<?= $id ?>" style="font-size:1.55rem;font-weight:900;letter-spacing:-.04em;margin-bottom:.18rem;color:<?= $col ?>">
      <div style="background:rgba(41,171,226,.08);border-radius:4px;height:2rem;animation:sk 1.2s infinite"></div>
    </div>
    <div style="font-size:.58rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28)"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
</div>
<style>@keyframes sk{0%,100%{opacity:1}50%{opacity:.4}}</style>

<!-- Progress bar -->
<div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:13px;padding:1.2rem;margin-bottom:1.6rem">
  <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:.5rem">
    <span id="prog-lbl" style="color:rgba(255,255,255,.35)">Loading…</span>
    <strong id="prog-val"></strong>
  </div>
  <div style="background:#141c2c;border-radius:99px;height:5px;overflow:hidden">
    <div id="prog-bar" style="background:linear-gradient(90deg,#1a82b0,#29ABE2);height:100%;border-radius:99px;width:0;transition:width .8s"></div>
  </div>
</div>

<!-- Quick Actions -->
<div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(41,171,226,.4);margin-bottom:.9rem">Quick Actions</div>
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem;margin-bottom:1.75rem">
  <?php foreach ([
    ['⚡','Research Latest', 'scraper.php',              true],
    ['🧩','Fill Missing',    'scraper.php',              true],
    ['🚩','Review Issues',   'scraper.php#needs-review', false],
    ['🩺','Diagnostics',     'diagnostics.php',          false],
    ['📥','Import Excel/CSV','import.php',               false],
  ] as [$ic,$lbl,$url,$pri]): ?>
  <a href="<?= bp().'/'.'admin/'.$url ?>"
     style="background:<?= $pri?'rgba(255,215,0,.06)':'#0e1420' ?>;border:1px solid <?= $pri?'rgba(255,215,0,.22)':'rgba(41,171,226,.1)' ?>;border-radius:12px;padding:1.1rem 1rem;text-decoration:none;color:#eef2f8;transition:.18s;text-align:center;display:flex;flex-direction:column;align-items:center;gap:.4rem">
    <span style="font-size:1.4rem"><?= $ic ?></span>
    <span style="font-size:.78rem;font-weight:600;color:<?= $pri?'#FFD700':'rgba(255,255,255,.58)' ?>"><?= $lbl ?></span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Latest episodes -->
<div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(41,171,226,.4);margin-bottom:.85rem;display:flex;justify-content:space-between;align-items:center">
  <span>Latest Episodes</span>
  <a href="<?= bp() ?>/search.php" target="_blank" style="font-size:.75rem;color:#29ABE2;text-decoration:none">View all →</a>
</div>
<div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:13px;overflow:hidden;margin-bottom:1.5rem">
  <?php foreach ($recent as $ep): ?>
  <div style="display:flex;align-items:center;gap:.75rem;padding:.58rem 1.1rem;border-bottom:1px solid rgba(41,171,226,.05);transition:.1s" onmouseover="this.style.background='rgba(41,171,226,.04)'" onmouseout="this.style.background=''">
    <span style="font-size:.72rem;font-weight:800;color:#FFD700;min-width:52px">#<?= str_pad($ep['episode_number'],3,'0',STR_PAD_LEFT) ?></span>
    <span style="font-size:.8rem;color:rgba(255,255,255,.55);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($ep['title'] ?? 'Episode #'.str_pad($ep['episode_number'],3,'0',STR_PAD_LEFT)) ?></span>
    <span style="font-size:.7rem;color:rgba(255,255,255,.22);flex-shrink:0"><?= $ep['air_date'] ?? '—' ?></span>
    <?php if ($ep['verification_required']): ?>
      <span style="background:rgba(245,158,11,.12);color:#f59e0b;font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:3px;flex-shrink:0">Unverified</span>
    <?php else: ?>
      <span style="background:rgba(34,197,94,.1);color:#22c55e;font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:3px;flex-shrink:0">✓</span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($logLines): ?>
<div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(41,171,226,.4);margin-bottom:.7rem">Last Update Log</div>
<div style="background:#06090f;border:1px solid rgba(41,171,226,.08);border-radius:10px;padding:.9rem;font-size:.72rem;font-family:monospace;line-height:1.85">
  <?php foreach ($logLines as $l): ?>
  <div style="color:<?= str_contains($l,'ERROR')?'#fca5a5':(str_contains($l,'✓')?'#86efac':'rgba(255,255,255,.35)') ?>"><?= htmlspecialchars($l) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</main></div>
<script>
fetch('index.php?s=1')
  .then(r=>r.json()).then(d=>{
    var c=(id,v,col)=>{var e=document.getElementById(id);if(e){e.textContent=v;e.style.color=col||'inherit'}};
    c('st-total', d.total.toLocaleString(), '#FFD700');
    // Archive Coverage's own evidence-aware missing count when a live
    // detection has been cached (Auto Sync → Detect Latest); falls back
    // to the plain numbering-range count otherwise — either way, this
    // is coverage (does the episode exist), never metadata enrichment.
    var cov = d.coverage || {};
    var missingCount = cov.missing_count ?? d.missing;
    c('st-miss',  missingCount, missingCount>0?'#fca5a5':'#86efac');
    c('st-syn',   d.no_synopsis,d.no_synopsis>0?'#fcd34d':'#86efac');
    c('st-thumb', d.thumb_missing,d.thumb_missing>0?'#fcd34d':'#86efac');
    c('st-pct',   d.pct+'%',   d.pct>=100?'#86efac':'#29ABE2');
    document.getElementById('prog-lbl').textContent='EP'+d.first_ep+'–EP'+d.last_ep;
    document.getElementById('prog-val').textContent=d.total+' / '+d.expected+' · '+d.pct+'%';
    setTimeout(()=>document.getElementById('prog-bar').style.width=Math.min(d.pct,100)+'%',120);

    var h = d.health || {};
    c('st-review', h.needs_review ?? 0, (h.needs_review ?? 0) > 0 ? '#fca5a5' : '#86efac');
    c('st-archlatest', cov.archive_latest ? 'EP'+String(cov.archive_latest).padStart(3,'0') : '—', '#FFD700');
    if (cov.latest_verified_aired) c('st-verifiedlatest', 'EP'+String(cov.latest_verified_aired).padStart(3,'0'), '#86efac');
    else if (cov.decision === 'SOURCE_DISAGREEMENT') c('st-verifiedlatest', 'Disagreement', '#fcd34d');
    else c('st-verifiedlatest', 'Not checked', 'rgba(255,255,255,.3)');
    c('st-missingeps', missingCount, missingCount>0?'#fcd34d':'#86efac');

    var lr = d.last_run;
    var lrEl = document.getElementById('st-lastrun'), lrDetail = document.getElementById('st-lastrun-detail');
    if (lr) {
      var statusColor = lr.status === 'completed' ? '#86efac' : (lr.status === 'failed' ? '#fca5a5' : '#fcd34d');
      lrEl.innerHTML = '<span style="color:'+statusColor+'">' + (lr.status || 'unknown') + '</span>' + (lr.mode ? ' · ' + lr.mode : '');
      lrDetail.textContent = (lr.finished_at || '') + (lr.summary ? ' — ' + lr.summary : '');
    } else {
      lrEl.textContent = 'No run yet';
      lrDetail.innerHTML = '<a href="scraper.php" style="color:#29ABE2">Start one →</a>';
    }

    var sh = d.source_health || {healthy:0,total:0};
    var shEl = document.getElementById('st-srchealth');
    if (sh.total > 0) {
      shEl.textContent = sh.healthy + '/' + sh.total;
      shEl.style.color = sh.healthy === sh.total ? '#86efac' : (sh.healthy >= sh.total * 0.6 ? '#fcd34d' : '#fca5a5');
    } else {
      shEl.textContent = '—';
      shEl.style.color = 'rgba(255,255,255,.3)';
    }
  }).catch(()=>{});
</script>
</body></html>
