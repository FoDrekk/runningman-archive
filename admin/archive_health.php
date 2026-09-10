<?php
// ============================================================
// Archive Health (PR16) — SCAN → DETECT → CLASSIFY → EXPLAIN →
// RECOMMEND. Read-only: this page can trigger RmArchiveHealth::scan()
// and nothing else — no button here writes to episodes, thumbnails,
// research state, provenance or source configuration. Every
// recommended action links to an existing controlled workflow (Auto
// Sync, Thumbnail Recovery, the review inbox) instead of running one.
//
// AJAX must run before layout.php — see auto_sync.php for why.
// ============================================================
$adminTitle = 'Archive Health';
$adminPage  = 'archive_health';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/system.php';
adminCheck();

$db = getDB();
$action = $_GET['a'] ?? null;

if ($action === 'scan') {
    header('Content-Type: application/json');
    if (ob_get_level() > 0) ob_clean();
    set_time_limit(60);
    try {
        require_once __DIR__ . '/../includes/scraping/bootstrap.php';
        // DB-only by default — the one exception is an explicit, opt-in
        // live latest-episode check (?fresh=1), exactly the same "never
        // on page load, only on request" rule Auto Sync's own "Detect
        // Latest" button already follows.
        $detection = null;
        if (!empty($_GET['fresh'])) {
            $detection = RmLatestEpisode::detectDetailed();
            stateSet('latest_detection', json_encode($detection));
        } else {
            $cached = json_decode((string)stateGet('latest_detection', ''), true);
            if (is_array($cached)) $detection = $cached;
        }
        $report = (new RmArchiveHealth($db))->scan(['detection' => $detection]);
        echo json_encode(['ok' => true] + $report);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

rmJsonRejectUnknownAction();

require_once __DIR__ . '/layout.php';
?>
<style>
.ah-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.8rem;margin-bottom:1.4rem}
.ah-card{background:#111a28;border:1px solid rgba(41,171,226,.1);border-radius:11px;padding:1rem 1.1rem}
.ah-card h4{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.35);margin-bottom:.5rem;display:flex;justify-content:space-between;align-items:center}
.ah-pill{display:inline-block;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:2px 8px;border-radius:20px}
.ah-pill-GOOD,.ah-pill-VERIFIED{background:rgba(34,197,94,.12);color:#4ade80}
.ah-pill-INFO,.ah-pill-EMPTY,.ah-pill-UNKNOWN,.ah-pill-NOT_INSTALLED{background:rgba(148,163,184,.15);color:#94a3b8}
.ah-pill-WARNING,.ah-pill-CONFLICT,.ah-pill-MISSING_AIRED_EPISODES{background:rgba(245,158,11,.14);color:#fcd34d}
.ah-pill-ERROR{background:rgba(251,146,60,.16);color:#fb923c}
.ah-pill-CRITICAL{background:rgba(239,68,68,.16);color:#f87171}
.ah-card .cnt{font-size:.76rem;color:rgba(255,255,255,.5);line-height:1.8}
.ah-card .cnt b{color:#eef2f8}
.ah-card p{font-size:.68rem;color:rgba(255,255,255,.3);margin-top:.5rem;line-height:1.5}
.ah-filters{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.9rem}
.ah-filters select{padding:.4rem .6rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.78rem;outline:none}
.ah-issue{border-bottom:1px solid rgba(41,171,226,.05);padding:.6rem 0}
.ah-issue:last-child{border-bottom:none}
.ah-issue .hd{display:flex;gap:.5rem;align-items:baseline;flex-wrap:wrap}
.ah-issue .id{font-family:ui-monospace,monospace;font-size:.68rem;color:#29ABE2}
.ah-issue .ep{font-size:.68rem;color:rgba(255,255,255,.35)}
.ah-issue .desc{font-size:.8rem;color:rgba(255,255,255,.72);margin-top:.25rem}
.ah-issue .rec{font-size:.72rem;color:rgba(255,255,255,.35);margin-top:.2rem}
</style>

<div class="at">
  <h1>🩺 Archive Health</h1>
  <p>A read-only diagnostic scan across seven domains — episode coverage, metadata, research, thumbnails, sources, latest-episode verification, and AI/decision provenance. This page never writes to the archive; every recommendation links to an existing controlled workflow.</p>
</div>

<div class="alert alert-info" style="margin-bottom:1.2rem">
  This is a diagnostic system, not a repair system. Nothing on this page fixes anything automatically — it tells you what needs attention and where to act on it.
</div>

<div style="margin-bottom:1.2rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
  <button class="btn btn-sm" onclick="runScan(false)" id="btnScan">🔍 Run Health Scan</button>
  <button class="btn btn-dark btn-sm" onclick="runScan(true)" id="btnScanFresh">📡 Scan + Verify Latest Live</button>
  <span id="scanMeta" style="font-size:.72rem;color:rgba(255,255,255,.3)"></span>
</div>

<div id="domainCards" class="ah-grid"><div class="sc-meta">Run a scan to see the current state.</div></div>

<div class="ap">
  <div class="sh">Attention Required</div>
  <div class="ah-filters">
    <select id="fSeverity" onchange="renderIssues()">
      <option value="">All severities</option>
      <option value="CRITICAL">Critical</option>
      <option value="ERROR">Error</option>
      <option value="WARNING">Warning</option>
      <option value="INFO">Info</option>
    </select>
    <select id="fDomain" onchange="renderIssues()">
      <option value="">All domains</option>
      <option value="episode_coverage">Episode Coverage</option>
      <option value="metadata">Metadata</option>
      <option value="research">Research</option>
      <option value="thumbnails">Thumbnails</option>
      <option value="sources">Sources</option>
      <option value="latest_verification">Latest Verification</option>
      <option value="provenance">Provenance</option>
    </select>
    <input type="number" id="fEpisode" placeholder="Episode #" style="width:110px;padding:.4rem .6rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.78rem;outline:none" oninput="renderIssues()">
  </div>
  <div id="issueList"><div class="sc-meta">No scan run yet.</div></div>
</div>

<script>
const BP='<?= bp() ?>';
let lastIssues = [];

const SEV_ORDER = {CRITICAL:0, ERROR:1, WARNING:2, INFO:3};
const DOMAIN_LABEL = {
  episode_coverage:'Episode Coverage', metadata:'Metadata', research:'Research',
  thumbnails:'Thumbnails', sources:'Sources', latest_verification:'Latest Verification', provenance:'Provenance',
};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function runScan(fresh){
  const btn = document.getElementById(fresh ? 'btnScanFresh' : 'btnScan');
  const other = document.getElementById(fresh ? 'btnScan' : 'btnScanFresh');
  btn.disabled = true; other.disabled = true;
  btn.innerHTML = '<span class="spin"></span> Scanning…';
  try {
    const r = await fetch(BP + '/admin/archive_health.php?a=scan' + (fresh ? '&fresh=1' : ''));
    const d = await r.json();
    if (!d.ok) { document.getElementById('domainCards').innerHTML = '<div class="alert alert-err">' + esc(d.error) + '</div>'; return; }
    renderDomains(d.domains);
    lastIssues = d.issues || [];
    renderIssues();
    const c = d.severity_counts || {};
    document.getElementById('scanMeta').textContent =
      'Generated ' + new Date(d.generated_at).toLocaleString() + ' — ' +
      c.CRITICAL + ' critical, ' + c.ERROR + ' error, ' + c.WARNING + ' warning, ' + c.INFO + ' info' +
      (d.issues_truncated ? ' (list truncated)' : '');
  } catch (e) {
    document.getElementById('domainCards').innerHTML = '<div class="alert alert-err">Request failed: ' + esc(e.message) + '</div>';
  }
  btn.disabled = false; other.disabled = false;
  document.getElementById('btnScan').innerHTML = '🔍 Run Health Scan';
  document.getElementById('btnScanFresh').innerHTML = '📡 Scan + Verify Latest Live';
}

function renderDomains(domains){
  let html = '';
  for (const [key, label] of Object.entries(DOMAIN_LABEL)) {
    const d = domains[key] || {};
    const status = d.status || 'UNKNOWN';
    html += '<div class="ah-card"><h4>' + esc(label) + ' <span class="ah-pill ah-pill-' + esc(status) + '">' + esc(status) + '</span></h4>';
    html += '<div class="cnt">' + countsSummary(key, d.counts || {}) + '</div>';
    if (d.explanation) html += '<p>' + esc(d.explanation) + '</p>';
    html += '</div>';
  }
  document.getElementById('domainCards').innerHTML = html;
}

function countsSummary(domain, c){
  const row = (label, val) => '<div>' + esc(label) + ': <b>' + esc(val) + '</b></div>';
  switch (domain) {
    case 'episode_coverage':
      return row('Stored', c.stored_episodes) + row('Missing', c.missing_count) + row('Latest verified aired', c.latest_verified_aired ?? '—');
    case 'metadata':
      return row('Core complete', (c.core ? c.core.pct : 0) + '%') + row('Core partial', c.core ? c.core.core_partial : 0);
    case 'research':
      return row('Researched', c.researched ?? 0) + row('Conflicts', c.conflicts ?? 0) + row('Needs review', c.needs_review ?? 0) + row('Insufficient evidence', c.insufficient_evidence ?? 0);
    case 'thumbnails':
      return row('Valid', c.VALID ?? 0) + row('Broken', c.BROKEN ?? 0) + row('Missing', c.MISSING ?? 0) + row('Invalid', c.INVALID ?? 0) + row('Duplicate', c.DUPLICATE ?? 0) + row('Suspect', c.SUSPECT_DUPLICATE ?? 0);
    case 'sources':
      return row('Online', c.online ?? 0) + row('Degraded', c.degraded ?? 0) + row('Unavailable', c.unavailable ?? 0) + row('Disabled', c.disabled ?? 0);
    case 'latest_verification':
      return row('Stored latest', c.stored_latest != null ? 'EP' + c.stored_latest : '—')
           + row('Latest verified aired', c.latest_verified_aired != null ? 'EP' + c.latest_verified_aired : 'Unknown')
           + row('Upcoming', c.upcoming != null ? 'EP' + c.upcoming : '—');
    case 'provenance':
      return Object.entries(c).map(([k,v]) => row(k.replace(/_/g,' '), v)).join('') || row('Checked', 'nothing found');
    default:
      return '';
  }
}

function renderIssues(){
  const sev = document.getElementById('fSeverity').value;
  const dom = document.getElementById('fDomain').value;
  const ep  = document.getElementById('fEpisode').value;
  let issues = lastIssues.filter(i =>
    (!sev || i.severity === sev) &&
    (!dom || i.domain === dom) &&
    (!ep  || String(i.episode) === String(parseInt(ep,10)))
  );
  const out = document.getElementById('issueList');
  if (!issues.length) { out.innerHTML = '<div class="alert alert-ok">✓ Nothing matches — either fully healthy or filtered to nothing.</div>'; return; }
  out.innerHTML = issues.map(function(i, idx){
    return '<div class="ah-issue">' +
      '<div class="hd"><span class="ah-pill ah-pill-' + esc(i.severity) + '">' + esc(i.severity) + '</span>' +
      '<span class="id">' + esc(i.id) + '</span>' +
      '<span style="font-size:.68rem;color:rgba(255,255,255,.3)">' + esc(DOMAIN_LABEL[i.domain] || i.domain) + '</span>' +
      (i.episode != null ? '<span class="ep">EP' + esc(String(i.episode).padStart(3,'0')) + '</span>' : '') + '</div>' +
      '<div class="desc">' + esc(i.description) + '</div>' +
      (i.recommended_action ? '<div class="rec">→ ' + esc(i.recommended_action) + '</div>' : '') +
      '</div>';
  }).join('');
}

window.addEventListener('load', function(){ runScan(false); });
</script>
</main></div>
</body></html>
