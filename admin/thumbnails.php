<?php
// AJAX must run before layout.php — see auto_sync.php for why.
$adminTitle = 'Thumbnails';
$adminPage  = 'thumbs';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';
adminCheck();

$db = getDB();

// AJAX grab one thumbnail
// Goes through the thumbnail engine, so a single episode now tries EVERY
// source that offered an image (in image_url priority order) instead of
// giving up when the first one has a dead link, and each candidate is
// validated as a real image of a real size before anything is saved.
// An identical image already on disk is not re-downloaded.
if (isset($_GET['a']) && $_GET['a']==='grab' && isset($_GET['ep'])) {
    header('Content-Type: application/json');
    set_time_limit(60);
    $n = (int)$_GET['ep'];

    try {
        $engine    = new RmScrapingEngine();
        $collected = $engine->collect($n, ['image_url']);

        // Candidates in this field's configured priority order.
        $candidates = [];
        foreach ((array)rmScrapeConfig('field_priority.image_url', []) as $src) {
            $url = $collected['payloads'][$src]['image_url'] ?? null;
            if (is_string($url) && $url !== '') $candidates[] = ['url' => $url, 'source' => $src];
        }

        if (!$candidates) {
            $why = [];
            foreach ($collected['meta'] as $src => $m) {
                if (!empty($m['error'])) $why[] = "$src: " . mb_substr((string)$m['error'], 0, 60);
            }
            logActivity('thumbnail', $n, 'failed', 'No image offered by any source');
            echo json_encode(['ok'=>false,'ep'=>$n,'error'=>'No image found',
                              'detail'=>$why ? implode(' · ', array_slice($why, 0, 3)) : 'No source returned an image URL']);
            exit;
        }

        $te  = new RmThumbnailEngine($db);
        $res = $te->acquire($n, rmYear($n), $candidates);

        if ($res['ok'] && $res['path']) {
            $te->link($n, $res['path'], $res['url']);
            @unlink(sys_get_temp_dir().'/rm_stats.json');
            logActivity('thumbnail', $n, 'success',
                ($res['skipped'] ? 'Already current' : 'Downloaded') . ' from ' . ($res['source'] ?? '?')
                . ($res['width'] ? " ({$res['width']}×{$res['height']})" : ''));
            echo json_encode(['ok'=>true,'ep'=>$n,'path'=>$res['path'],'source'=>$res['source'],
                              'width'=>$res['width'],'height'=>$res['height'],
                              'skipped'=>$res['skipped'],'note'=>$res['reason']]);
            exit;
        }

        logActivity('thumbnail', $n, 'failed', (string)($res['reason'] ?? 'No usable image'));
        echo json_encode(['ok'=>false,'ep'=>$n,'error'=>'No usable image',
                          'detail'=>$res['reason'],
                          'tried'=>array_map(fn($a) => ($a['source'] ?? '?') . ': ' . ($a['reason'] ?? 'ok'), (array)$res['attempts'])]);
    } catch (Throwable $e) {
        logActivity('thumbnail', $n, 'failed', $e->getMessage());
        echo json_encode(['ok'=>false,'ep'=>$n,'error'=>$e->getMessage()]);
    }
    exit;
}

// AJAX — bulk thumbnail quality check (PR #4, section 26). Re-runs the
// SAME validation used at download time (RmThumbnailEngine::verify(),
// which itself calls RmValidator::imageBytes()) against every thumbnail
// already on disk, so "corrupted since the last check" and "was fine on
// arrival" are told apart. Read-only except for the status column of
// thumbnail_meta, which just records what this check found — nothing
// about an episode's actual thumbnail assignment changes.
if (isset($_GET['a']) && $_GET['a'] === 'quality') {
    header('Content-Type: application/json');
    set_time_limit(120);
    if (ob_get_level() > 0) ob_clean();
    try {
        require_once __DIR__ . '/../includes/scraping/bootstrap.php';
        $te = new RmThumbnailEngine($db);
        $eps = $db->query(
            "SELECT episode_number FROM episodes WHERE thumbnail_id IS NOT NULL ORDER BY episode_number"
        )->fetchAll(PDO::FETCH_COLUMN);

        $byStatus = ['ok' => 0, 'broken' => 0, 'missing' => 0];
        $bad = [];
        foreach ($eps as $ep) {
            $r = $te->verify((int)$ep);
            $status = $r['status'] ?? ($r['ok'] ? 'ok' : 'broken');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            if (!$r['ok']) $bad[] = ['episode' => (int)$ep, 'status' => $status, 'reason' => $r['reason']];
        }

        // Duplicate images across episodes — only meaningful once
        // thumbnail_meta (content_hash) is installed.
        $duplicates = [];
        try {
            $duplicates = $db->query(
                "SELECT content_hash, GROUP_CONCAT(episode_number ORDER BY episode_number) episodes, COUNT(*) c
                   FROM thumbnail_meta WHERE content_hash IS NOT NULL AND content_hash <> ''
                  GROUP BY content_hash HAVING c > 1"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* thumbnail_meta not installed — skip */ }

        $missingThumb = (int)$db->query(
            "SELECT COUNT(*) FROM episodes WHERE thumbnail_id IS NULL"
        )->fetchColumn();

        echo json_encode([
            'ok' => true, 'checked' => count($eps), 'by_status' => $byStatus,
            'bad' => $bad, 'duplicates' => $duplicates, 'no_thumbnail_at_all' => $missingThumb,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX — actually scan disk and correct the DB verified flag to match
// reality. The DB flag alone has drifted from the real files before
// (e.g. records created before a download genuinely succeeded), which
// is why this page could show "0 missing" while the homepage still
// shows placeholders. This is the source of truth fix for that.
if (isset($_GET['a']) && $_GET['a']==='reverify') {
    header('Content-Type: application/json');
    set_time_limit(30);
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $rows = $db->query("SELECT thumbnail_id, episode_number, local_path, verified FROM thumbnails")->fetchAll();
    $corrected = 0; $realCount = 0;
    foreach ($rows as $r) {
        $diskPath   = $r['local_path'] ? $docRoot . $r['local_path'] : '';
        $realExists = $diskPath && file_exists($diskPath) && filesize($diskPath) > 1000;
        if ($realExists)  $realCount++;
        if ($realExists && (int)$r['verified'] !== 1) {
            $db->prepare("UPDATE thumbnails SET verified=1 WHERE thumbnail_id=?")->execute([$r['thumbnail_id']]);
            $corrected++;
        } elseif (!$realExists && (int)$r['verified'] === 1) {
            $db->prepare("UPDATE thumbnails SET verified=0 WHERE thumbnail_id=?")->execute([$r['thumbnail_id']]);
            $corrected++;
        }
    }
    @unlink(sys_get_temp_dir().'/rm_stats.json');
    @unlink(sys_get_temp_dir().'/rm_thumb_realcheck.json');
    echo json_encode(['scanned'=>count($rows),'real_files'=>$realCount,'corrected'=>$corrected]);
    exit;
}

// A ?a= request that reached this point matched no handler above.
// Rendering the page would hand a JSON caller an HTML document.
rmJsonRejectUnknownAction();

require_once __DIR__ . '/layout.php';

$total = (int)$db->query("SELECT COUNT(*) FROM episodes")->fetchColumn();

// Real-file-aware verified count, cached briefly (scanning 800+ files via
// file_exists() is fast, but no need to redo it on every single page
// load). Falls back to the DB-only flag the very first time so the page
// still renders instantly before anyone has run "Re-verify" yet.
$cacheFile = sys_get_temp_dir().'/rm_thumb_realcheck.json';
if (file_exists($cacheFile) && (time()-filemtime($cacheFile)) < 300) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    $verified = $cached['real_files'] ?? 0;
} else {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $rows = $db->query("SELECT local_path FROM thumbnails WHERE verified=1")->fetchAll(PDO::FETCH_COLUMN);
    $verified = 0;
    foreach ($rows as $path) {
        if ($path && file_exists($docRoot.$path) && filesize($docRoot.$path) > 1000) $verified++;
    }
    @file_put_contents($cacheFile, json_encode(['real_files'=>$verified, 'checked_at'=>date('c')]));
}

$missing  = $total - $verified;
$dbMax    = (int)$db->query("SELECT MAX(episode_number) FROM episodes")->fetchColumn();
$pct      = $total > 0 ? round($verified/$total*100) : 0;
?>

<div class="at"><h1>🖼️ Thumbnails</h1><p>Download episode thumbnails — HD, cropped to fit, from myrunningman.com.</p></div>

<div class="ap" style="margin-bottom:1rem">
  <button class="btn btn-dark btn-sm" onclick="reverifyThumbs()" id="btnReverify">🔍 Re-verify Against Real Files</button>
  <span style="font-size:.75rem;color:rgba(255,255,255,.35);margin-left:.5rem">
    Scans disk directly and fixes the database if it's out of sync with what's actually there —
    use this if the homepage still shows placeholders despite this page saying "0 missing."
  </span>
  <div id="reverifyResult" style="margin-top:.6rem;font-size:.82rem"></div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.9rem;margin-bottom:1.5rem">
  <div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:12px;padding:1.2rem;text-align:center">
    <div style="font-size:1.7rem;font-weight:900;color:#FFD700"><?= $total ?></div>
    <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-top:.18rem">Total Episodes</div>
  </div>
  <div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:12px;padding:1.2rem;text-align:center">
    <div style="font-size:1.7rem;font-weight:900;color:#86efac"><?= $verified ?></div>
    <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-top:.18rem">Have Thumbnail</div>
  </div>
  <div style="background:#0e1420;border:1px solid <?= $missing>0?'rgba(245,158,11,.2)':'rgba(34,197,94,.15)' ?>;border-radius:12px;padding:1.2rem;text-align:center">
    <div style="font-size:1.7rem;font-weight:900;color:<?= $missing>0?'#fcd34d':'#86efac' ?>"><?= $missing ?></div>
    <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-top:.18rem">Missing</div>
  </div>
</div>

<!-- Progress bar -->
<div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:12px;padding:1.2rem;margin-bottom:1.5rem">
  <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:.45rem">
    <span style="color:rgba(255,255,255,.35)">Coverage</span>
    <strong><?= $pct ?>%</strong>
  </div>
  <div style="background:#141c2c;border-radius:99px;height:5px;overflow:hidden">
    <div style="background:linear-gradient(90deg,#166534,#22c55e);height:100%;border-radius:99px;width:<?= $pct ?>%"></div>
  </div>
</div>

<!-- Batch controls -->
<div class="ap">
  <div class="sh">Batch Scraper</div>
  <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:.85rem">
    <button class="btn" onclick="batchGrab(1,<?= $dbMax ?>)" id="btnAll">
      🖼️ Grab All Missing (<?= $missing ?>)
    </button>
    <span style="color:rgba(255,255,255,.3);font-size:.8rem">or range:</span>
    <input type="number" id="bFrom" value="1" style="width:70px;padding:.35rem .5rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:6px;color:#eef2f8;font-size:.8rem;outline:none">
    <span style="color:rgba(255,255,255,.3);font-size:.8rem">to</span>
    <input type="number" id="bTo" value="<?= $dbMax ?>" style="width:70px;padding:.35rem .5rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:6px;color:#eef2f8;font-size:.8rem;outline:none">
    <button class="btn btn-dark btn-sm" onclick="batchGrab(+document.getElementById('bFrom').value,+document.getElementById('bTo').value)">Grab Range</button>
    <button class="btn btn-ghost btn-sm" id="btnStop" onclick="stopFlag=true" style="display:none">⏹ Stop</button>
  </div>
  <div id="bProg" style="display:none;margin-bottom:.75rem">
    <div style="display:flex;justify-content:space-between;font-size:.72rem;color:rgba(255,255,255,.35);margin-bottom:.3rem">
      <span id="bLbl"></span><span id="bPct"></span>
    </div>
    <div style="background:#141c2c;border-radius:99px;height:4px;overflow:hidden">
      <div id="bBar" style="background:#29ABE2;height:100%;border-radius:99px;width:0;transition:width .3s"></div>
    </div>
  </div>
  <div id="bLog" style="max-height:200px;overflow-y:auto;font-size:.72rem"></div>
</div>

<!-- Thumbnail Quality Check (PR #4, section 26) -->
<div class="ap">
  <div class="sh">Thumbnail Quality Check</div>
  <p style="font-size:.78rem;color:rgba(255,255,255,.35);margin-bottom:.8rem">
    Re-validates every thumbnail already on disk — missing file, corrupted image, wrong dimensions —
    and finds duplicate images across episodes (once <code>thumbnail_meta</code> is installed). Read-only.
  </p>
  <button class="btn btn-sm" onclick="runQualityCheck()" id="btnQuality">🔍 Find Bad Thumbnails</button>
  <div id="qualityResult" style="margin-top:1rem"></div>
</div>

</main></div>
<script>
var BP='<?= bp() ?>',stopFlag=false,bRunning=false;
function pad(n){return String(n).padStart(3,'0')}
function toast(m){var t=document.getElementById('toast');if(!t){t=document.createElement('div');t.id='toast';document.body.appendChild(t)}t.textContent=m;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),2600)}

async function runQualityCheck(){
  var btn=document.getElementById('btnQuality'), out=document.getElementById('qualityResult');
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Checking…';
  out.innerHTML='';
  try{
    var r=await fetch(BP+'/admin/thumbnails.php?a=quality');
    var d=await r.json();
    if(!d.ok){ out.innerHTML='<div class="alert alert-err">'+d.error+'</div>'; return; }
    var html='<div style="display:flex;gap:1.5rem;margin-bottom:.8rem;font-size:.8rem">'
      +'<span>✓ OK: <strong style="color:#86efac">'+(d.by_status.ok||0)+'</strong></span>'
      +'<span>✗ Broken: <strong style="color:#fca5a5">'+(d.by_status.broken||0)+'</strong></span>'
      +'<span>— No thumbnail at all: <strong style="color:#fcd34d">'+d.no_thumbnail_at_all+'</strong></span>'
      +'</div>';
    if(d.bad.length){
      html+='<div style="font-weight:700;font-size:.8rem;color:#fca5a5;margin-bottom:.4rem">Bad thumbnails ('+d.bad.length+')</div>'
        +'<div class="sc-log" style="max-height:200px;overflow-y:auto;font-size:.74rem;margin-bottom:.8rem">'
        +d.bad.map(function(b){return 'EP'+pad(b.episode)+' — '+b.status+': '+(b.reason||'');}).join('\n')+'</div>';
    }
    if(d.duplicates.length){
      html+='<div style="font-weight:700;font-size:.8rem;color:#fcd34d;margin-bottom:.4rem">Duplicate images across episodes ('+d.duplicates.length+')</div>'
        +'<div class="sc-log" style="max-height:160px;overflow-y:auto;font-size:.74rem">'
        +d.duplicates.map(function(g){return 'EP'+g.episodes.split(',').join(', EP')+' share one image';}).join('\n')+'</div>';
    }
    if(!d.bad.length && !d.duplicates.length){
      html+='<div class="alert alert-ok">✓ No bad or duplicate thumbnails found across '+d.checked+' checked.</div>';
    }
    out.innerHTML=html;
  }catch(e){ out.innerHTML='<div class="alert alert-err">Request failed: '+e.message+'</div>'; }
  btn.disabled=false; btn.innerHTML='🔍 Find Bad Thumbnails';
}
async function reverifyThumbs(){
  var btn=document.getElementById('btnReverify'), out=document.getElementById('reverifyResult');
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Scanning disk…';
  out.innerHTML='';
  try{
    var r=await fetch(BP+'/admin/thumbnails.php?a=reverify');
    var d=await r.json();
    out.innerHTML = '<span style="color:#86efac">✓ Scanned '+d.scanned+' records — '+d.real_files+' real files found on disk'
      + (d.corrected>0 ? ', corrected '+d.corrected+' mismatched flag'+(d.corrected>1?'s':'')+'.' : ', database already matched reality.')
      + '</span> <a href="javascript:location.reload()" style="margin-left:.5rem">Refresh page →</a>';
    toast('Re-verify complete: '+d.real_files+' real, '+d.corrected+' corrected');
  }catch(e){ out.innerHTML='<span style="color:#fca5a5">Failed: '+e.message+'</span>'; }
  btn.disabled=false; btn.innerHTML='🔍 Re-verify Against Real Files';
}

async function batchGrab(from,to){
  if(bRunning){toast('Already running');return}
  bRunning=true;stopFlag=false;
  document.getElementById('btnAll').disabled=true;
  document.getElementById('btnStop').style.display='';
  document.getElementById('bProg').style.display='block';
  document.getElementById('bLog').innerHTML='';
  var total=to-from+1,done=0,ok=0;
  for(var i=from;i<=to;i++){
    if(stopFlag)break;
    document.getElementById('bLbl').textContent='EP'+pad(i)+' ('+done+'/'+total+')';
    document.getElementById('bPct').textContent=Math.round(done/total*100)+'%';
    document.getElementById('bBar').style.width=(done/total*100)+'%';
    try{
      var r=await fetch(BP+'/admin/thumbnails.php?a=grab&ep='+i);
      var d=await r.json();
      var li=document.createElement('div');li.style.cssText='padding:.14rem 0;border-bottom:1px solid rgba(41,171,226,.05)';
      if(d.ok){ok++;li.innerHTML='<span style="color:#86efac;font-weight:700">✓</span> EP'+pad(i)+' <span style="color:rgba(255,255,255,.25)">saved</span>'}
      else{li.innerHTML='<span style="color:rgba(255,255,255,.3)">–</span> EP'+pad(i)+' <span style="color:rgba(255,255,255,.2)">'+(d.error||'skip')+'</span>'}
      var log=document.getElementById('bLog');log.appendChild(li);log.scrollTop=log.scrollHeight;
    }catch(e){}
    done++;
    await new Promise(r=>setTimeout(r,700));
  }
  document.getElementById('bLbl').textContent=(stopFlag?'Stopped':'Done')+' — '+ok+' saved';
  document.getElementById('bBar').style.width='100%';
  document.getElementById('bBar').style.background=ok>0?'#22c55e':'#29ABE2';
  bRunning=false;document.getElementById('btnAll').disabled=false;document.getElementById('btnStop').style.display='none';
  toast('Done: '+ok+' thumbnails saved');
}
</script>
</body></html>
