<?php
// AJAX must run before layout.php — see auto_sync.php for why.
$adminTitle = 'System Health';
$adminPage  = 'health';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/system.php';
adminCheck();

// AJAX: run health check
if (isset($_GET['a']) && $_GET['a'] === 'check') {
    // Keep total runtime predictable: 3 external pings + DB check should
    // never approach PHP's default 30s execution limit.
    set_time_limit(25);
    header('Content-Type: application/json');

    // Discard any stray warning/notice output that would otherwise
    // get prepended to the JSON and make the client's JSON.parse fail.
    if (ob_get_level() > 0) ob_clean();

    try {
        $result = runHealthCheck();
        echo json_encode($result);
    } catch (Throwable $e) {
        // Guaranteed valid JSON even if something inside genuinely broke —
        // the UI can show a real error message instead of a parse failure.
        http_response_code(200);
        echo json_encode([
            ['name'=>'Health Check','url'=>'-','ok'=>false,'code'=>0,'ms'=>0,
             'error'=>'Internal error: '.$e->getMessage()]
        ]);
    }
    exit;
}

// AJAX: data integrity check (PR #4, section 25) — read-only sweep for
// duplicate/invalid episode numbers, invalid or duplicate air dates,
// orphaned guests/thumbnails, duplicate thumbnail images and broken
// theme/location references. Never merges, deletes or fixes anything.
if (isset($_GET['a']) && $_GET['a'] === 'integrity') {
    header('Content-Type: application/json');
    if (ob_get_level() > 0) ob_clean();
    try {
        require_once __DIR__ . '/../includes/scraping/bootstrap.php';
        $report = (new RmDuplicateDetector(getDB()))->fullCheck();
        $total = 0;
        foreach ($report as $r) $total += count($r['rows']);
        echo json_encode(['ok' => true, 'total' => $total, 'categories' => $report]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: database backup before a bulk change (section 24). Read-only
// against application data — it only ever creates a new dump file.
if (isset($_GET['a']) && $_GET['a'] === 'backup') {
    header('Content-Type: application/json');
    if (ob_get_level() > 0) ob_clean();
    require_once __DIR__ . '/../includes/scraping/bootstrap.php';
    $label = trim((string)($_GET['label'] ?? 'manual'));
    echo json_encode(RmDatabaseBackup::create($label ?: 'manual'));
    exit;
}
if (isset($_GET['a']) && $_GET['a'] === 'backups') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../includes/scraping/bootstrap.php';
    echo json_encode(['ok' => true, 'available' => RmDatabaseBackup::available(), 'backups' => RmDatabaseBackup::list()]);
    exit;
}

// A ?a= request that reached this point matched no handler above.
// Rendering the page would hand a JSON caller an HTML document.
rmJsonRejectUnknownAction();

require_once __DIR__ . '/layout.php';

$db = getDB();
$tablesReady = stabilityTablesExist();
$lastCheck   = stateGet('last_health_check', 'never');
$syncLocked  = lockIsHeld('sync');
$cronLocked  = lockIsHeld('cron');

// 24h activity summary
$stats24h = getActivityStats(24);
$byType = [];
foreach ($stats24h as $r) {
    $byType[$r['action_type']][$r['status']] = (int)$r['cnt'];
}

// Recent failures across all action types — the things worth investigating
$failures = getRecentFailures(7, 20);
?>

<div class="at"><h1>🩺 System Health</h1><p>Connectivity status, lock state, and recent activity across all automation.</p></div>

<?php if (!$tablesReady): ?>
<div class="alert alert-warn" style="margin-bottom:1rem">
  ⚠ Stability tables not installed — lock state, activity log, and history below will be empty until you run
  <code style="background:rgba(0,0,0,.2);padding:1px 6px;border-radius:3px">database/stability_patch.sql</code> in phpMyAdmin.
  Live connectivity checks below still work regardless.
</div>
<?php endif; ?>

<!-- Live source check -->
<div class="ap">
  <div class="sh">Data Source Connectivity</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:1rem">Last checked: <span id="lastCheck"><?= h($lastCheck === '' ? 'never' : $lastCheck) ?></span></p>
  <button class="btn btn-sm" onclick="runCheck()" id="btnCheck">🔄 Run Health Check Now</button>
  <div id="healthResults" style="margin-top:1.2rem;display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem"></div>
</div>

<!-- Lock states -->
<div class="ap">
  <div class="sh">Operation Locks</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.9rem">
    Prevents two automation processes (e.g. manual sync + scheduled cron) from writing to the database at the same time.
  </p>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
    <div style="background:#141c2c;border:1px solid <?= $syncLocked?'rgba(245,158,11,.3)':'rgba(34,197,94,.2)' ?>;border-radius:10px;padding:1rem">
      <div style="font-weight:700;font-size:.88rem;color:#eef2f8">Auto Sync</div>
      <div style="font-size:.78rem;margin-top:.3rem;color:<?= $syncLocked?'#fcd34d':'#86efac' ?>">
        <?= $syncLocked ? '🔒 Locked — sync in progress' : '✓ Free' ?>
      </div>
    </div>
    <div style="background:#141c2c;border:1px solid <?= $cronLocked?'rgba(245,158,11,.3)':'rgba(34,197,94,.2)' ?>;border-radius:10px;padding:1rem">
      <div style="font-weight:700;font-size:.88rem;color:#eef2f8">Weekly Update (cron)</div>
      <div style="font-size:.78rem;margin-top:.3rem;color:<?= $cronLocked?'#fcd34d':'#86efac' ?>">
        <?= $cronLocked ? '🔒 Locked — cron in progress' : '✓ Free' ?>
      </div>
    </div>
  </div>
</div>

<!-- 24h summary -->
<div class="ap">
  <div class="sh">Last 24 Hours — Activity Summary</div>
  <?php if (empty($byType)): ?>
  <p style="font-size:.82rem;color:rgba(255,255,255,.3)">No automation activity in the last 24 hours.</p>
  <?php else: ?>
  <table class="atable">
    <thead><tr><th>Action</th><th>Success</th><th>Failed</th><th>Skipped</th></tr></thead>
    <tbody>
    <?php foreach ($byType as $type => $s): ?>
    <tr>
      <td style="font-weight:700;text-transform:capitalize"><?= h($type) ?></td>
      <td style="color:#86efac"><?= $s['success'] ?? 0 ?></td>
      <td style="color:<?= ($s['failed']??0)>0?'#fca5a5':'rgba(255,255,255,.3)' ?>"><?= $s['failed'] ?? 0 ?></td>
      <td style="color:rgba(255,255,255,.3)"><?= $s['skipped'] ?? 0 ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Database backup -->
<div class="ap">
  <div class="sh">Database Backup</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.9rem">
    A plain SQL dump, taken before a bulk operation (Fill Missing, a large AI generation pass, a big import)
    so there is something to restore from if it needs undoing. Stored outside the web-accessible paths.
  </p>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.8rem">
    <input type="text" id="backupLabel" placeholder="label (e.g. run ref)" style="width:200px;padding:.4rem .7rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.82rem;outline:none">
    <button class="btn btn-sm" onclick="runBackup()" id="btnBackup">💾 Backup Now</button>
  </div>
  <div id="backupResult" style="margin-bottom:.8rem"></div>
  <div id="backupList" style="font-size:.78rem"></div>
</div>

<!-- Data integrity check -->
<div class="ap">
  <div class="sh">Data Integrity Check</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.9rem">
    A read-only sweep for duplicate/invalid episode numbers, invalid or duplicate air dates, orphaned
    guests and thumbnails, duplicate thumbnail images, and broken theme/location references.
    Nothing is ever merged, deleted, or fixed automatically — every finding is a report for a person to act on.
  </p>
  <button class="btn btn-sm" onclick="runIntegrity()" id="btnIntegrity">🔍 Run Data Integrity Check</button>
  <div id="integrityResult" style="margin-top:1rem"></div>
</div>

<!-- Recent failures -->
<div class="ap">
  <div class="sh">Recent Failures (last 7 days)</div>
  <?php if (empty($failures)): ?>
  <p style="font-size:.82rem;color:#86efac">✓ No failures recorded in the last 7 days.</p>
  <?php else: ?>
  <div style="max-height:280px;overflow-y:auto">
    <?php foreach ($failures as $f): ?>
    <div style="padding:.4rem 0;border-bottom:1px solid rgba(41,171,226,.05);display:flex;gap:.6rem;align-items:baseline;font-size:.78rem">
      <span style="color:rgba(255,255,255,.25);min-width:130px"><?= h($f['created_at']) ?></span>
      <span style="background:rgba(41,171,226,.1);color:#29ABE2;padding:1px 7px;border-radius:3px;font-size:.68rem;font-weight:700;text-transform:uppercase"><?= h($f['action_type']) ?></span>
      <span style="color:rgba(255,255,255,.5)"><?= $f['episode_number'] ? 'EP'.str_pad($f['episode_number'],3,'0',STR_PAD_LEFT) : '—' ?></span>
      <span style="color:#fca5a5;flex:1"><?= h($f['message']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

</main></div>
<script>
const BP='<?= bp() ?>';
async function runCheck(){
  var btn=document.getElementById('btnCheck');
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Checking…';
  try{
    var ctrl=new AbortController();
    var killTimer=setTimeout(()=>ctrl.abort(),28000); // client-side safety net
    var r=await fetch(BP+'/admin/health.php?a=check',{signal:ctrl.signal});
    clearTimeout(killTimer);
    if(!r.ok) throw new Error('HTTP '+r.status+' from server');
    var sources=await r.json();
    var html='';
    sources.forEach(function(s){
      var color = s.ok ? '#86efac' : '#fca5a5';
      var bg    = s.ok ? 'rgba(34,197,94,.08)' : 'rgba(239,68,68,.08)';
      var border= s.ok ? 'rgba(34,197,94,.2)'  : 'rgba(239,68,68,.25)';
      html += '<div style="background:'+bg+';border:1px solid '+border+';border-radius:10px;padding:1rem">'
            + '<div style="display:flex;justify-content:space-between;align-items:baseline">'
            + '<span style="font-weight:700;font-size:.88rem;color:#eef2f8">'+s.name+'</span>'
            + '<span style="color:'+color+';font-size:.78rem;font-weight:700">'+(s.ok?'✓ OK':'✗ DOWN')+'</span>'
            + '</div>'
            + '<div style="font-size:.72rem;color:rgba(255,255,255,.35);margin-top:.3rem">'
            + (s.ok ? s.ms+'ms response time' : (s.error||'Unreachable'))
            + '</div></div>';
    });
    document.getElementById('healthResults').innerHTML = html;
    document.getElementById('lastCheck').textContent = new Date().toLocaleString();
  }catch(e){
    var msg = e.name==='AbortError' ? 'Health check timed out (server took too long to respond).' : ('Health check failed: '+e.message);
    document.getElementById('healthResults').innerHTML = '<div style="color:#fca5a5">'+msg+'</div>';
  }
  btn.disabled=false; btn.innerHTML='🔄 Run Health Check Now';
}
async function runIntegrity(){
  var btn=document.getElementById('btnIntegrity');
  var out=document.getElementById('integrityResult');
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Scanning…';
  out.innerHTML='';
  try{
    var r=await fetch(BP+'/admin/health.php?a=integrity');
    var d=await r.json();
    if(!d.ok){ out.innerHTML='<div class="alert alert-err">'+d.error+'</div>'; return; }
    if(d.total===0){ out.innerHTML='<div class="alert alert-ok">✓ No integrity issues found.</div>'; return; }
    var html='<div class="alert alert-warn" style="margin-bottom:.8rem">'+d.total+' issue(s) found — nothing was changed.</div>';
    Object.keys(d.categories).forEach(function(k){
      var c=d.categories[k];
      if(!c.rows.length) return;
      html+='<div style="margin-bottom:1rem"><div style="font-weight:700;font-size:.85rem;color:#fcd34d;margin-bottom:.4rem">'
          + c.label+' ('+c.rows.length+')</div><div class="sc-log" style="max-height:220px;overflow-y:auto;font-size:.74rem">'
          + c.rows.map(function(row){ return JSON.stringify(row); }).join('\n')
          + '</div></div>';
    });
    out.innerHTML=html;
  }catch(e){ out.innerHTML='<div class="alert alert-err">Request failed: '+e.message+'</div>'; }
  btn.disabled=false; btn.innerHTML='🔍 Run Data Integrity Check';
}
async function runBackup(){
  var btn=document.getElementById('btnBackup');
  var label=document.getElementById('backupLabel').value.trim();
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Backing up…';
  try{
    var r=await fetch(BP+'/admin/health.php?a=backup&label='+encodeURIComponent(label||'manual'));
    var d=await r.json();
    var out=document.getElementById('backupResult');
    out.innerHTML = d.ok
      ? '<div class="alert alert-ok">✓ Backup created: '+d.filename+' ('+(d.size/1024/1024).toFixed(1)+' MB)</div>'
      : '<div class="alert alert-err">✗ '+d.reason+'</div>';
    loadBackups();
  }catch(e){ document.getElementById('backupResult').innerHTML='<div class="alert alert-err">Request failed: '+e.message+'</div>'; }
  btn.disabled=false; btn.innerHTML='💾 Backup Now';
}
async function loadBackups(){
  var out=document.getElementById('backupList');
  try{
    var r=await fetch(BP+'/admin/health.php?a=backups');
    var d=await r.json();
    if(!d.available){ out.innerHTML='<span style="color:rgba(255,255,255,.3)">mysqldump is not available on this host — backups cannot be taken here.</span>'; return; }
    if(!d.backups.length){ out.innerHTML='<span style="color:rgba(255,255,255,.3)">No backups yet.</span>'; return; }
    out.innerHTML = d.backups.map(function(b){
      return '<div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid rgba(41,171,226,.05)">'
        +'<span>'+b.filename+'</span><span style="color:rgba(255,255,255,.3)">'+(b.size/1024/1024).toFixed(1)+' MB · '+b.created_at.slice(0,16).replace('T',' ')+'</span></div>';
    }).join('');
  }catch(e){ out.innerHTML='<span style="color:#fca5a5">Failed to load: '+e.message+'</span>'; }
}
window.addEventListener('load', function(){ runCheck(); loadBackups(); });
</script>
</body></html>
