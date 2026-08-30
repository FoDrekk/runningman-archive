<?php
// ── IMPORTANT: AJAX/action handlers MUST run before layout.php ──
// layout.php prints raw HTML the moment it's required (no output
// buffering). If it ran first, every JSON response below would have
// a full HTML page glued in front of it, breaking JSON.parse() on
// the client and making every button silently do nothing.
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

// ── AJAX: lock control ─────────────────────────────────────────
if (isset($_GET['a']) && $_GET['a'] === 'lock_status') {
    header('Content-Type: application/json');
    echo json_encode([
        'locked'=> lockIsHeld(LOCK_NAME),
        'last_ep'=> (int)stateGet('sync_last_ep', 0),
        'total'=> (int)stateGet('sync_total', 0),
        'done'=> (int)stateGet('sync_done', 0),
        'started_at'=> stateGet('sync_started_at', ''),
    ]);
    exit;
}

if (isset($_GET['a']) && $_GET['a'] === 'lock_start') {
    header('Content-Type: application/json');
    $total = (int)($_GET['total'] ?? 0);
    if (lockIsHeld(LOCK_NAME)) {
        echo json_encode(['ok'=>false,'msg'=>'Already running']);
    } else {
        lockAcquire(LOCK_NAME);
        stateSet('sync_total', $total);
        stateSet('sync_done', 0);
        logActivity('sync', null, 'success', "Bulk sync started (target: $total episodes)");
        echo json_encode(['ok'=>true]);
    }
    exit;
}

if (isset($_GET['a']) && $_GET['a'] === 'lock_release') {
    header('Content-Type: application/json');
    lockRelease(LOCK_NAME);
    logActivity('sync', null, 'success', 'Bulk sync stopped/finished');
    echo json_encode(['ok'=>true]);
    exit;
}

if (isset($_GET['a']) && $_GET['a'] === 'progress') {
    header('Content-Type: application/json');
    $doneN = (int)($_GET['done'] ?? 0);
    $lastEp = (int)($_GET['ep'] ?? 0);
    stateSet('sync_done', $doneN);
    stateSet('sync_last_ep', $lastEp);
    // Heartbeat: renew the lock timestamp so a genuinely-active sync never goes stale
    stateSet('sync_started_at', date('Y-m-d H:i:s'));
    echo json_encode(['ok'=>true]);
    exit;
}

// ── AJAX: sync one episode ────────────────────────────────────
// Now delegated to the scraping engine, so a bulk sync gains everything
// a single episode gets in the control centre: all sources consulted,
// per-FIELD priority, validation, confidence, provenance, and the
// data-safety guards that refuse to replace a good value with an empty
// or suspicious one. The JSON keys the front end already reads
// (ok/title/guests/source/msg) are unchanged.
if (isset($_GET['a']) && $_GET['a']==='sync' && isset($_GET['ep'])) {
    header('Content-Type: application/json');
    set_time_limit(60);
    $startMs = microtime(true);
    $n = (int)$_GET['ep'];

    try {
        $engine = new RmScrapingEngine();
        $r = $engine->syncEpisode($n, [
            'dry_run'      => !empty($_GET['dry']),
            'all_fields'   => !empty($_GET['all']),
            'bypass_cache' => !empty($_GET['fresh']),
        ]);
        $durMs = (int)((microtime(true) - $startMs) * 1000);

        if (!empty($r['failed'])) {
            logActivity('sync', $n, 'failed', (string)($r['reason'] ?? 'No data from any source'), $durMs);
            echo json_encode(['ok'=>false,'ep'=>$n,'msg'=>$r['reason'] ?? 'No data']);
            exit;
        }

        $resolved = (array)($r['resolved'] ?? []);
        $applied  = (int)($r['summary']['total_applied'] ?? 0);
        $title    = $resolved['title']['value'] ?? ($r['existing']['title'] ?? null);
        $location = $resolved['location']['value'] ?? null;

        // Sources that actually contributed a winning value.
        $contributors = [];
        foreach ($resolved as $f) foreach ((array)($f['sources'] ?? []) as $s) $contributors[$s] = true;

        $changeLines = array_map([RmDiffEngine::class, 'renderLine'],
            array_values(array_filter((array)($r['changes'] ?? []), fn($c) => $c['type'] !== 'unchanged')));

        if (!empty($r['skipped'])) {
            logActivity('sync', $n, 'skipped', (string)($r['reason'] ?? 'Already complete'), $durMs);
        } else {
            logActivity('sync', $n, 'success',
                ($title ?? '') . ' [' . (implode('+', array_keys($contributors)) ?: 'none') . ']'
                . ($applied ? " · $applied field(s)" : ' · no changes needed'), $durMs);
        }

        echo json_encode([
            'ok'         => true,
            'ep'         => $n,
            'source'     => implode('+', array_keys($contributors)) ?: 'none',
            'title'      => $title,
            'has_syn'    => !empty($resolved['synopsis']['value']) || !empty($r['existing']['synopsis']),
            'guests'     => count((array)($resolved['guests']['value'] ?? [])),
            'tags'       => count((array)($resolved['tags']['value'] ?? [])),
            'thumb'      => !empty($r['thumbnail']['ok']),
            'location'   => is_array($location) ? ($location['name'] ?? null) : $location,
            // Additive keys — the existing UI ignores them; newer views
            // use them for change and confidence display.
            'skipped'    => !empty($r['skipped']),
            'is_new'     => !empty($r['is_new']),
            'applied'    => $applied,
            'changes'    => $changeLines,
            'warnings'   => array_column((array)($r['warnings'] ?? []), 'message'),
            'confidence' => array_map(fn($x) => $x['confidence'] ?? null, $resolved),
            'ms'         => $durMs,
        ]);
    } catch (Throwable $e) {
        logActivity('sync', $n, 'failed', $e->getMessage(), (int)((microtime(true)-$startMs)*1000));
        echo json_encode(['ok'=>false,'ep'=>$n,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── Normal page render — only reached if no AJAX action matched ──
require_once __DIR__ . '/layout.php';

// ── Page load ──────────────────────────────────────────────────
$row=$db->query("SELECT COUNT(*) as total, SUM(verification_required=1) as unverified, SUM(synopsis IS NULL OR synopsis='') as no_syn FROM episodes")->fetch();
$totalEps  = (int)$row['total'];
$unverified= (int)$row['unverified'];
$noSynopsis= (int)$row['no_syn'];

$needSync=$db->query("SELECT episode_number FROM episodes WHERE verification_required=1 OR synopsis IS NULL OR synopsis='' OR title NOT LIKE 'Episode #% - %' ORDER BY episode_number ASC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);

// Full incomplete list (NOT capped at 200 like $needSync, which is only
// for the chip display) — used by the "Sync All N Incomplete" button so
// it syncs exactly the incomplete episodes rather than a blind range.
$needSyncAll=$db->query("SELECT episode_number FROM episodes WHERE verification_required=1 OR synopsis IS NULL OR synopsis='' OR title NOT LIKE 'Episode #% - %' ORDER BY episode_number ASC")->fetchAll(PDO::FETCH_COLUMN);

$tablesReady = stabilityTablesExist();
$lockHeld   = lockIsHeld(LOCK_NAME);
$cronLocked = lockIsHeld('cron');
$lastEp     = (int)stateGet('sync_last_ep', 0);
$canResume  = !$lockHeld && $lastEp > 0 && $lastEp < $totalEps;

$recentLog = getRecentActivity(15, 'sync');
?>

<div class="at"><h1>Auto Sync Episodes</h1><p>Fetch real episode data from Wikipedia + myrm.tv for all incomplete episodes.</p></div>

<?php if (!$tablesReady): ?>
<div class="alert alert-warn" style="margin-bottom:1rem">Stability tables not installed yet — resume/lock/activity-log features are disabled, but syncing still works normally.
  Run <code style="background:rgba(0,0,0,.2);padding:1px 6px;border-radius:3px">database/stability_patch.sql</code> in phpMyAdmin to enable them.
</div>
<?php endif; ?>

<?php if ($cronLocked): ?>
<div class="alert alert-warn" style="margin-bottom:1rem">Weekly Update (cron) is currently running. Starting a sync now could cause conflicting writes — wait for it to finish, or check <a href="cron.php" style="color:inherit;text-decoration:underline">Weekly Update</a> status.
</div>
<?php endif; ?>

<?php if ($lockHeld): ?>
<div class="alert alert-info" style="margin-bottom:1rem" id="lockBanner">A sync is already running (started <?= h(stateGet('sync_started_at','')) ?>) — possibly in another browser tab.
  Progress: <span id="lockProgress"><?= (int)stateGet('sync_done',0) ?> / <?= (int)stateGet('sync_total',0) ?></span>
  <button class="btn btn-ghost btn-sm" style="margin-left:.5rem" onclick="forceUnlock()">Force unlock (only if truly stuck)</button>
</div>
<?php elseif ($canResume): ?>
<div class="alert alert-info" style="margin-bottom:1rem">Previous sync stopped at EP<?= str_pad($lastEp,3,'0',STR_PAD_LEFT) ?>.
  <button class="btn btn-sm" style="margin-left:.5rem" onclick="resumeSync()">▶ Resume from EP<?= str_pad($lastEp+1,3,'0',STR_PAD_LEFT) ?></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem;margin-bottom:1.5rem">
  <?php foreach ([['Total Episodes',$totalEps,'#FFD700'],['Unverified',$unverified,'#fcd34d'],['No Synopsis',$noSynopsis,'#fcd34d']] as [$l,$n,$c]): ?>
  <div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:12px;padding:1.2rem;text-align:center">
    <div style="font-size:1.7rem;font-weight:900;letter-spacing:-.04em;color:<?= $c ?>"><?= number_format($n) ?></div>
    <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-top:.18rem"><?= $l ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Controls -->
<div class="ap">
  <div class="sh">Sync Controls</div>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem">
    <button class="btn" onclick="startSync('incomplete')" id="btnIncomplete" <?= $lockHeld?'disabled':'' ?>>Sync All <?= $noSynopsis ?> Incomplete</button>
    <button class="btn btn-dark" onclick="startSync('range')" id="btnRange" <?= $lockHeld?'disabled':'' ?>>Sync Range</button>
    <button class="btn btn-dark" onclick="startSync('everything')" id="btnEverything" <?= $lockHeld?'disabled':'' ?>>Sync All (1&ndash;<?= $totalEps ?>)</button>
    <button class="btn btn-ghost" onclick="stopSync()" id="btnStop" disabled>Stop</button>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center;font-size:.8rem;color:rgba(255,255,255,.35);flex-wrap:wrap">From EP <input type="number" id="sFrom" value="1" style="width:70px;padding:.35rem .5rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:6px;color:#eef2f8;font-size:.8rem;outline:none">
    to EP   <input type="number" id="sTo" value="<?= $totalEps ?>" style="width:70px;padding:.35rem .5rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:6px;color:#eef2f8;font-size:.8rem;outline:none">
    <span style="margin-left:.4rem">delay</span> <input type="number" id="sDelay" value="1.5" step="0.1" min="0.3" style="width:60px;padding:.35rem .5rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:6px;color:#eef2f8;font-size:.8rem;outline:none"> s
    <span>· auto-retries on network errors · lower delay = faster but higher risk of rate-limiting</span>
  </div>
</div>

<!-- Progress -->
<div id="progWrap" style="display:none" class="ap">
  <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:.5rem">
    <span id="progLbl" style="color:rgba(255,255,255,.45)">Starting…</span>
    <span><span style="color:#86efac">✓ <span id="cOk">0</span></span> &nbsp; <span style="color:rgba(255,255,255,.3)">– <span id="cSkip">0</span></span> &nbsp; <span id="cPct" style="color:rgba(255,255,255,.4)">0%</span></span>
  </div>
  <div style="background:#141c2c;border-radius:99px;height:4px;overflow:hidden;margin-bottom:.85rem">
    <div id="progBar" style="background:#29ABE2;height:100%;border-radius:99px;width:0;transition:width .3s"></div>
  </div>
  <div id="syncLog" style="max-height:220px;overflow-y:auto;font-size:.72rem"></div>
</div>

<!-- Episode chips -->
<?php if ($needSync): ?>
<div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(41,171,226,.4);margin-bottom:.65rem">Episodes Needing Sync (<?= $noSynopsis ?><?= $noSynopsis > 200 ? ' — showing first 200' : '' ?>)
</div>
<div style="display:flex;flex-wrap:wrap;gap:.3rem;max-height:140px;overflow-y:auto;margin-bottom:1.5rem">
  <?php foreach ($needSync as $n): ?>
  <button onclick="syncOne(<?= $n ?>,this)" id="chip<?= $n ?>" <?= $lockHeld?'disabled':'' ?>
    style="background:rgba(245,158,11,.1);color:#f59e0b;border:1px solid rgba(245,158,11,.2);border-radius:4px;padding:2px 9px;font-size:.7rem;font-weight:700;cursor:pointer;transition:.15s">EP<?= str_pad($n,3,'0',STR_PAD_LEFT) ?>
  </button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Recent activity (persistent — survives refresh) -->
<?php if ($recentLog): ?>
<div class="ap">
  <div class="sh">Recent Sync Activity (persistent log)</div>
  <div style="max-height:200px;overflow-y:auto;font-size:.74rem">
    <?php foreach ($recentLog as $r): ?>
    <div style="padding:.25rem 0;border-bottom:1px solid rgba(41,171,226,.05);display:flex;gap:.5rem;align-items:baseline">
      <span style="color:rgba(255,255,255,.25);font-size:.68rem;min-width:90px"><?= h($r['created_at']) ?></span>
      <span style="color:<?= $r['status']==='success'?'#86efac':'#fca5a5' ?>"><?= $r['status']==='success'?'✓':'✗' ?></span>
      <span style="color:rgba(255,255,255,.5)"><?= $r['episode_number'] ? 'EP'.str_pad($r['episode_number'],3,'0',STR_PAD_LEFT) : '(bulk)' ?></span>
      <span style="color:rgba(255,255,255,.35);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($r['message']) ?></span>
      <?php if ($r['duration_ms']): ?><span style="color:rgba(255,255,255,.2);font-size:.65rem"><?= $r['duration_ms'] ?>ms</span><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</main></div>
<script>
const BP='<?= bp() ?>',running={v:false},stopFlag={v:false};
function pad(n){return String(n).padStart(3,'0')}
function toast(m){var t=document.getElementById('toast');if(!t){t=document.createElement('div');t.id='toast';document.body.appendChild(t)}t.textContent=m;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),2600)}

async function syncOne(n,btn){
  // NOTE: was pointing at the wrong filename (sync.php) before — fixed to auto_sync.php
  if(btn){btn.textContent='';btn.disabled=true;btn.style.background='rgba(255,255,255,.05)'}
  try{
    var r=await fetch(BP+'/admin/auto_sync.php?a=sync&ep='+n);
    var d=await r.json();
    if(btn){
      if(d.ok){btn.textContent='✓ EP'+pad(n);btn.style.background='rgba(34,197,94,.1)';btn.style.color='#86efac';btn.style.borderColor='rgba(34,197,94,.25)'}
      else{btn.textContent='– EP'+pad(n);btn.style.opacity='.4'}
    }
    if(d.ok) toast('EP'+pad(n)+' synced'+(d.title?' — '+d.title.substring(0,35):''));
    else toast('EP'+pad(n)+' failed: '+(d.msg||'unknown'));
  }catch(e){if(btn){btn.textContent='✗';btn.style.opacity='.4'}toast('Network error on EP'+pad(n))}
}

async function forceUnlock(){
  if(!confirm('Only do this if the sync genuinely crashed and is not actually running.\n\nForce-unlock?')) return;
  await fetch(BP+'/admin/auto_sync.php?a=lock_release');
  location.reload();
}

function resumeSync(){ startSync('resume'); }

async function startSync(mode){
  if(running.v){toast('Already running');return}

  // Check server-side lock first — protects against double-tab / cron clash
  var lockCheck = await fetch(BP+'/admin/auto_sync.php?a=lock_status').then(r=>r.json());
  if(lockCheck.locked){ toast('A sync is already running elsewhere'); location.reload(); return; }

  var eps=[];
  if(mode==='resume'){
    var lastEp = lockCheck.last_ep || 0;
    var t=+document.getElementById('sTo').value||<?= $totalEps ?>;
    for(var i=lastEp+1;i<=t;i++) eps.push(i);
  } else if(mode==='incomplete'){
    // Sync ONLY the episodes flagged incomplete server-side (missing
    // synopsis, unverified, or malformed title) — not a blind range.
    eps = <?= json_encode(array_map('intval', $needSyncAll)) ?>;
    if(!eps.length){ toast('Nothing to sync — no incomplete episodes'); return; }
  } else if(mode==='everything'){
    // Sync every episode from 1 to the highest episode number, regardless
    // of whether it's already complete (full re-sync / refresh).
    for(var i=1;i<=<?= $totalEps ?>;i++) eps.push(i);
  } else {
    // 'range' — sync exactly the From/To inputs.
    var f=+document.getElementById('sFrom').value,t=+document.getElementById('sTo').value;
    if(!f||!t||f>t){ toast('Enter a valid From / To range'); return; }
    for(var i=f;i<=t;i++) eps.push(i);
  }

  var lockStart = await fetch(BP+'/admin/auto_sync.php?a=lock_start&total='+eps.length).then(r=>r.json());
  if(!lockStart.ok){ toast(lockStart.msg||'Could not acquire lock'); return; }

  stopFlag.v=false;running.v=true;
  document.getElementById('progWrap').style.display='block';
  document.getElementById('syncLog').innerHTML='';
  document.getElementById('btnIncomplete').disabled=true;
  document.getElementById('btnRange').disabled=true;
  document.getElementById('btnEverything').disabled=true;
  document.getElementById('btnStop').disabled=false;

  var total=eps.length,done=0,ok=0,skip=0;
  for(var i=0;i<eps.length;i++){
    if(stopFlag.v)break;
    var n=eps[i];
    document.getElementById('progLbl').textContent='EP'+pad(n)+' ('+done+'/'+total+')';
    document.getElementById('cPct').textContent=Math.round(done/total*100)+'%';
    document.getElementById('progBar').style.width=(done/total*100)+'%';
    var chip=document.getElementById('chip'+n);if(chip){chip.textContent='';chip.disabled=true}
    try{
      var r=await fetch(BP+'/admin/auto_sync.php?a=sync&ep='+n);
      var d=await r.json();
      var li=document.createElement('div');li.style.cssText='padding:.14rem 0;border-bottom:1px solid rgba(41,171,226,.05)';
      if(d.ok){ok++;li.innerHTML='<span style="color:#86efac;font-weight:700">✓</span>EP'+pad(n)+(d.title?' <span style="color:rgba(255,255,255,.4)">'+d.title.substring(0,45)+'</span>':'')+(d.guests>0?' <span style="color:rgba(41,171,226,.7);font-size:.68rem">'+d.guests+' guests</span>':'')+' <span style="color:rgba(255,255,255,.2);font-size:.65rem">['+d.source+']</span>';if(chip){chip.textContent='✓ EP'+pad(n);chip.style.background='rgba(34,197,94,.1)';chip.style.color='#86efac';chip.style.borderColor='rgba(34,197,94,.25)'}}
      else{skip++;li.innerHTML='<span style="color:rgba(255,255,255,.3)">–</span>EP'+pad(n)+' <span style="color:rgba(255,255,255,.2)">'+(d.msg||'no data')+'</span>';if(chip){chip.textContent='– EP'+pad(n);chip.style.opacity='.4'}}
      var log=document.getElementById('syncLog');log.appendChild(li);log.scrollTop=log.scrollHeight;
    }catch(e){skip++}
    done++;
    document.getElementById('cOk').textContent=ok;document.getElementById('cSkip').textContent=skip;

    // Persist progress every iteration — survives refresh, acts as heartbeat for the lock
    fetch(BP+'/admin/auto_sync.php?a=progress&done='+done+'&ep='+n).catch(()=>{});

    // Delay between requests is user-configurable (see #sDelay). Clamp to
    // a 0.3s floor so an accidental 0 doesn't hammer the upstream sites and
    // risk an IP block (we already saw MyDramaList hard-block this network).
    var delayMs = Math.max(300, (parseFloat(document.getElementById('sDelay').value)||1.5)*1000);
    await new Promise(r=>setTimeout(r,delayMs));
  }

  await fetch(BP+'/admin/auto_sync.php?a=lock_release');

  document.getElementById('progLbl').textContent=(stopFlag.v?'Stopped — ':'Complete — ')+ok+' synced, '+skip+' skipped';
  document.getElementById('progBar').style.width='100%';document.getElementById('progBar').style.background=ok>0?'#22c55e':'#29ABE2';
  document.getElementById('cPct').textContent='100%';running.v=false;stopFlag.v=false;
  document.getElementById('btnIncomplete').disabled=false;document.getElementById('btnRange').disabled=false;document.getElementById('btnEverything').disabled=false;document.getElementById('btnStop').disabled=true;
  toast('Sync complete: '+ok+' episodes updated');
}

async function stopSync(){
  stopFlag.v=true;
  toast('Stopping…');
}
</script>
</body></html>
