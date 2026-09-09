<?php
// AJAX must run before layout.php — see auto_sync.php for why.
$adminTitle = 'Fetch Latest';
$adminPage  = 'fetch';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scraper.php';
require_once __DIR__ . '/../includes/system.php';
adminCheck();

$db    = getDB();
$dbMax = (int)$db->query("SELECT MAX(episode_number) FROM episodes")->fetchColumn();
$msg = $err = '';

// ── AJAX ──────────────────────────────────────────────────────
if (isset($_GET['a'])) {
    header('Content-Type: application/json');
    set_time_limit(60);

    if ($_GET['a'] === 'check') {
        // Runs the actual PR4 research pipeline (multi-signal latest
        // detection + per-candidate collect/resolve against live
        // sources) rather than trusting one source's episode count —
        // see RmLatestEpisode::detectDetailed().
        $d = rmGetLatestEpDetection();
        echo json_encode($d);
        exit;
    }

    if ($_GET['a'] === 'scrape' && isset($_GET['ep'])) {
        $n = (int)$_GET['ep'];
        $d = rmScrapeEpisode($n);

        // Keep the response to what the form needs, plus a compact
        // per-field confidence map so the operator can see which values
        // several sources agreed on and which rest on a single weak one
        // before saving them.
        $conf = [];
        foreach ((array)($d['_resolved'] ?? []) as $field => $r) {
            if (($r['value'] ?? null) === null) continue;
            $conf[$field] = ['confidence' => $r['confidence'] ?? null,
                             'source'     => $r['source'] ?? null,
                             'agreed_by'  => $r['sources'] ?? []];
        }
        $failed = [];
        foreach ((array)($d['_meta'] ?? []) as $src => $m) {
            if (!empty($m['error']) && ($m['status'] ?? '') !== 'skipped') {
                $failed[$src] = mb_substr((string)$m['error'], 0, 120);
            }
        }
        unset($d['_resolved'], $d['_meta']);
        $d['confidence']     = $conf;
        $d['source_failures']= $failed;

        $hasData = !empty($d['synopsis']) || !empty($d['air_date']) || !empty($d['guests']);
        logActivity('fetch', $n, $hasData ? 'success' : 'failed',
            $hasData ? ('Scraped from ' . ($d['source'] ?? 'unknown')) : 'No usable data from any source');
        echo json_encode($d);
        exit;
    }

    if ($_GET['a'] === 'thumb' && isset($_GET['ep'])) {
        $n = (int)$_GET['ep'];
        $d = rmScrapeEpisode($n);
        if ($d && !empty($d['image_url'])) {
            $wp = rmDownloadThumb($d['image_url'], $n, rmYear($n));
            if ($wp) {
                $db->prepare("INSERT INTO thumbnails (episode_number,local_path,thumbnail_url,verified) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE local_path=VALUES(local_path),thumbnail_url=VALUES(thumbnail_url),verified=1")->execute([$n,$wp,$d['image_url']]);
                $db->prepare("UPDATE episodes SET thumbnail_id=(SELECT thumbnail_id FROM thumbnails WHERE episode_number=? LIMIT 1) WHERE episode_number=?")->execute([$n,$n]);
                logActivity('fetch', $n, 'success', 'Thumbnail saved');
                echo json_encode(['ok'=>true,'path'=>$wp]);
                exit;
            }
        }
        logActivity('fetch', $n, 'failed', 'No image available');
        echo json_encode(['ok'=>false,'error'=>'No image']);
        exit;
    }
    exit;
}

// ── SAVE ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save'])) {
    $n       = (int)$_POST['ep'];
    $yr      = rmYear($n);
    $padded  = str_pad($n,3,'0',STR_PAD_LEFT);
    $raw_t   = trim($_POST['title'] ?? '');
    $title   = $raw_t ?: "Episode #$padded";
    if ($title && !preg_match('/^Episode\s*#\d+/i',$title)) {
        $desc = preg_replace('/^Running\s*Man\s*[—\-]?\s*(Episode\s*#?\d+\s*[-–]?\s*)?/i','',$title);
        $title = strlen(trim($desc))>4 ? "Episode #$padded - ".trim($desc) : "Episode #$padded";
    }

    // BUGFIX: same dead-code pattern as includes/functions.php's
    // getEpisodeList() — execute() returns bool not the row value, so
    // this never could have produced a real year_id. It ran the SELECT
    // twice more for nothing; $yStmt below is the one real lookup.
    $yStmt = $db->prepare("SELECT year_id FROM years WHERE year_label=?");
    $yStmt->execute([$yr]); $yrId = (int)$yStmt->fetchColumn();
    if (!$yrId) { $db->prepare("INSERT INTO years (year_label,total_eps) VALUES (?,0)")->execute([$yr]); $yrId=(int)$db->lastInsertId(); }

    $exists = $db->prepare("SELECT COUNT(*) FROM episodes WHERE episode_number=?"); $exists->execute([$n]);
    if ($exists->fetchColumn()>0) { $err="EP$padded already exists."; }
    else {
        try {
            $db->beginTransaction();
            $imgUrl = trim($_POST['img']??'');
            $wp     = $imgUrl ? rmDownloadThumb($imgUrl,$n,$yr) : null;
            $dbPath = $wp ?: bp()."/thumbnails/$yr/ep$padded.jpg";
            $db->prepare("INSERT IGNORE INTO thumbnails (episode_number,local_path,thumbnail_url,verified) VALUES (?,?,?,?)")
               ->execute([$n,$dbPath,$imgUrl?:null,$wp?1:0]);
            $tRow = $db->prepare("SELECT thumbnail_id FROM thumbnails WHERE episode_number=? LIMIT 1"); $tRow->execute([$n]); $tid=(int)$tRow->fetchColumn();
            $db->prepare("INSERT INTO episodes (episode_number,year_id,title,air_date,runtime_minutes,synopsis,thumbnail_id,verification_required) VALUES (?,?,?,?,90,?,?,1)")
               ->execute([$n,$yrId,$title,trim($_POST['date']??'')?:null,trim($_POST['synopsis']??'')?:null,$tid?:null]);
            $eid=(int)$db->lastInsertId();
            if ($tid) $db->prepare("UPDATE episodes SET thumbnail_id=? WHERE episode_id=?")->execute([$tid,$eid]);
            // Guest identity goes through the resolver, so an alternate
            // spelling attaches to the existing person rather than
            // creating a second row for the same guest. A name that is
            // merely SIMILAR to an existing one is still created, but
            // flagged for review — it is not merged on a guess.
            $guestResolver = new RmGuestResolver($db);
            foreach (array_filter(array_map('trim',explode("\n",$_POST['guests']??''))) as $gn) {
                $gr = $guestResolver->resolve($gn, $n, 'manual');
                if (!$gr['guest_id']) continue;
                $db->prepare("INSERT IGNORE INTO episode_guests (episode_id,guest_id) VALUES (?,?)")->execute([$eid,(int)$gr['guest_id']]);
            }
            $db->prepare("UPDATE years SET total_eps=(SELECT COUNT(*) FROM episodes WHERE year_id=?) WHERE year_id=?")->execute([$yrId,$yrId]);
            $db->commit();
            // Clear stats cache
            @unlink(sys_get_temp_dir().'/rm_stats.json');
            $msg="✅ Episode #$padded saved!".($wp?' Thumbnail downloaded.':'');
            $dbMax=$n;
        } catch(Exception $e){$db->rollBack();$err=$e->getMessage();}
    }
}

// A ?a= request that reached this point matched no handler above.
// Rendering the page would hand a JSON caller an HTML document.
rmJsonRejectUnknownAction();

require_once __DIR__ . '/layout.php';

$recent=$db->query("SELECT episode_number,title,air_date,verification_required FROM episodes ORDER BY episode_number DESC LIMIT 5")->fetchAll();
$missingThumbs=(int)$db->query("SELECT COUNT(*) FROM episodes e WHERE NOT EXISTS(SELECT 1 FROM thumbnails t WHERE t.thumbnail_id=e.thumbnail_id AND t.verified=1 AND t.local_path IS NOT NULL)")->fetchColumn();
?>

<div class="at"><h1>⚡ Fetch Latest Episode</h1><p>Multi-source research detection → scrape → save.</p></div>

<?php if ($msg): ?><div class="aok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="aerr">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Status hero -->
<div class="ap" style="background:linear-gradient(135deg,#0e1420 0%,#062040 100%);margin-bottom:1.5rem">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1.5rem">
    <div style="display:flex;gap:1.75rem;flex-wrap:wrap">
      <div>
        <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.32);margin-bottom:.25rem">Database</div>
        <div style="font-size:1.1rem;font-weight:800;color:#FFD700">EP<?= str_pad($dbMax,3,'0',STR_PAD_LEFT) ?></div>
      </div>
      <div>
        <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.32);margin-bottom:.25rem">Latest Aired</div>
        <div id="latestAiredVal" style="font-size:1.1rem;font-weight:800;color:#86efac">Checking…</div>
      </div>
      <div id="upcomingBox" style="display:none">
        <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.32);margin-bottom:.25rem">Upcoming</div>
        <div id="upcomingVal" style="font-size:1.1rem;font-weight:800;color:#29ABE2"></div>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:.5rem;align-items:flex-end">
      <button class="btn" id="btnCheck" onclick="checkNew()">🔍 Check for New EPs</button>
      <button class="btn btn-dark btn-sm" id="btnQuick" style="display:none"></button>
      <button class="btn btn-dark btn-sm" onclick="var n=prompt('Enter EP number:');if(n&&+n>0)fetchEp(+n)">🔢 Manual EP entry</button>
    </div>
  </div>
</div>

<!-- Steps -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border:1px solid rgba(41,171,226,.15);border-radius:8px;overflow:hidden;margin-bottom:1.5rem">
  <?php foreach (['Detect','Scrape','Review','Save'] as $i=>$s): ?>
  <div id="step<?= $i+1 ?>" style="padding:.5rem;text-align:center;font-size:.72rem;font-weight:700;background:#141c2c;color:rgba(255,255,255,.35);<?= $i<3?'border-right:1px solid rgba(41,171,226,.1)':'' ?>;transition:.2s">
    <div style="font-size:.58rem;opacity:.5;margin-bottom:.1rem">Step <?= $i+1 ?></div><?= $s ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Detect result -->
<div class="ap" id="detectPanel">
  <div class="sh">Decision</div>
  <div id="detectMsg" style="color:rgba(255,255,255,.4);font-size:.85rem;margin-bottom:1rem">Click "Check for New EPs" to run detection.</div>
  <div id="newEpBtns" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem"></div>
  <div id="sourceStatusWrap" style="display:none">
    <div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-bottom:.5rem">Source Status</div>
    <div id="sourceStatus" style="display:flex;gap:.4rem;flex-wrap:wrap"></div>
  </div>
</div>

<!-- Scrape + form (hidden until scrape) -->
<div id="scrapeWrap" style="display:none">
<div class="ap">
  <div class="sh">Scraped Data — Review &amp; Save</div>
  <div id="scrapeMsg" style="font-size:.82rem;color:rgba(255,255,255,.4);margin-bottom:1rem"></div>

  <div style="display:flex;gap:1.2rem;margin-bottom:1.4rem;align-items:flex-start">
    <div id="scThumb" style="width:160px;height:90px;border-radius:8px;background:#141c2c;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:rgba(255,255,255,.2)">📺</div>
    <div style="flex:1">
      <div id="scEp" style="font-size:.68rem;font-weight:800;color:#29ABE2;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.2rem"></div>
      <div id="scTitle" style="font-size:1rem;font-weight:700;color:#eef2f8;margin-bottom:.2rem"></div>
      <div id="scDate"  style="font-size:.78rem;color:rgba(255,255,255,.4)"></div>
      <div id="scBadges" style="display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.45rem"></div>
    </div>
  </div>

  <form method="POST">
    <input type="hidden" name="save" value="1">
    <input type="hidden" name="ep"  id="fEp">
    <input type="hidden" name="img" id="fImg">
    <div class="g3">
      <div class="fld"><label>Air Date</label><input type="date" name="date" id="fDate"></div>
      <div class="fld"><label>Runtime (min)</label><input type="number" name="runtime" value="90"></div>
      <div class="fld"><label>Episode #</label><input type="text" id="fEpShow" readonly></div>
    </div>
    <div class="fld"><label>Title</label><input type="text" name="title" id="fTitle"></div>
    <div class="fld"><label>Synopsis</label><textarea name="synopsis" id="fSyn"></textarea></div>
    <div class="fld"><label>Guests (one per line)</label><textarea name="guests" id="fGuests" style="min-height:60px"></textarea></div>
    <div class="ar">
      <button type="submit" class="btn">💾 Save Episode</button>
      <button type="button" class="btn btn-dark" onclick="document.getElementById('scrapeWrap').style.display='none'">Cancel</button>
    </div>
  </form>
</div>
</div>

<!-- Thumbnail batch -->
<div class="ap">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.7rem">
    <div class="sh" style="margin-bottom:0">🖼️ Bulk Thumbnail Grabber</div>
    <span style="font-size:.78rem;color:<?= $missingThumbs>0?'#fcd34d':'#86efac' ?>"><?= $missingThumbs ?> missing</span>
  </div>
  <div style="display:flex;gap:.55rem;margin-bottom:.85rem;flex-wrap:wrap;align-items:center">
    <button class="btn" onclick="batchThumb(1,<?= $dbMax ?>)" id="btnBatch">🖼️ Grab All Missing</button>
    <span style="color:rgba(255,255,255,.3);font-size:.8rem">or range:</span>
    <input type="number" id="bFrom" value="1" style="width:68px;padding:.35rem .5rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.8rem;outline:none">
    <span style="color:rgba(255,255,255,.3);font-size:.8rem">to</span>
    <input type="number" id="bTo" value="<?= $dbMax ?>" style="width:68px;padding:.35rem .5rem;background:#141c2c;border:1px solid rgba(41,171,226,.14);border-radius:7px;color:#eef2f8;font-size:.8rem;outline:none">
    <button class="btn btn-dark btn-sm" onclick="batchThumb(+document.getElementById('bFrom').value,+document.getElementById('bTo').value)">Range</button>
    <button class="btn btn-ghost btn-sm" id="btnStop" onclick="batchStop=true" style="display:none">⏹ Stop</button>
  </div>
  <div id="bProg" style="display:none;margin-bottom:.75rem">
    <div style="display:flex;justify-content:space-between;font-size:.72rem;color:rgba(255,255,255,.35);margin-bottom:.3rem">
      <span id="bLbl"></span><span id="bPct"></span>
    </div>
    <div style="background:#141c2c;border-radius:99px;height:4px;overflow:hidden">
      <div id="bBar" style="background:#29ABE2;height:100%;border-radius:99px;width:0;transition:width .3s"></div>
    </div>
  </div>
  <div id="bLog" style="max-height:180px;overflow-y:auto;font-size:.72px"></div>
</div>

<!-- Recent -->
<div class="ap">
  <div class="sh">Latest in Database</div>
  <?php foreach ($recent as $ep): ?>
  <div style="display:flex;align-items:center;gap:.75rem;padding:.5rem 0;border-bottom:1px solid rgba(41,171,226,.05)">
    <span style="font-size:.72rem;font-weight:800;color:#FFD700;min-width:52px">#<?= str_pad($ep['episode_number'],3,'0',STR_PAD_LEFT) ?></span>
    <span style="font-size:.8rem;color:rgba(255,255,255,.5);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($ep['title']??'—') ?></span>
    <span style="font-size:.7rem;color:rgba(255,255,255,.22)"><?= $ep['air_date']??'—' ?></span>
    <?= $ep['verification_required']?'<span style="background:rgba(245,158,11,.12);color:#f59e0b;font-size:.65rem;font-weight:700;padding:2px 6px;border-radius:3px">Unverified</span>':'<span style="background:rgba(34,197,94,.1);color:#22c55e;font-size:.65rem;font-weight:700;padding:2px 6px;border-radius:3px">✓</span>' ?>
  </div>
  <?php endforeach; ?>
</div>

</main></div>
<script>
const BP='<?= bp() ?>', dbMax=<?= $dbMax ?>;
function pad(n){return String(n).padStart(3,'0')}
function step(n){[1,2,3,4].forEach(i=>{var e=document.getElementById('step'+i);e.style.background=i<n?'rgba(34,197,94,.1)':i===n?'rgba(41,171,226,.12)':'#141c2c';e.style.color=i<n?'#86efac':i===n?'#29ABE2':'rgba(255,255,255,.35)'})}
function toast(m){var t=document.getElementById('toast');if(!t){t=document.createElement('div');t.id='toast';document.body.appendChild(t)}t.textContent=m;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),2600)}

window.addEventListener('load',()=>setTimeout(checkNew,700));

var STATUS_COLOR={ok:'#86efac',unavailable:'#f59e0b',disabled:'rgba(255,255,255,.25)',no_signal:'rgba(255,255,255,.35)'};
var STATUS_LABEL={ok:null,unavailable:'unavailable',disabled:'disabled',no_signal:'no signal'};
var DECISION_COLOR={missing_episodes:'#29ABE2',source_disagreement:'#fcd34d',source_unavailable:'#fcd34d',insufficient_evidence:'#fcd34d',up_to_date:'#86efac'};
var DECISION_ICON={missing_episodes:'🆕',source_disagreement:'⚠',source_unavailable:'⚠',insufficient_evidence:'⚠',up_to_date:'✓'};

function checkNew(){
  var btn=document.getElementById('btnCheck');
  btn.innerHTML='<span class="spin"></span> Checking…';btn.disabled=true;
  fetch(BP+'/admin/fetch.php?a=check')
    .then(r=>r.json()).then(d=>{
      btn.innerHTML='🔍 Check for New EPs';btn.disabled=false;

      document.getElementById('latestAiredVal').textContent = d.latest_aired ? 'EP'+pad(d.latest_aired) : '—';

      var ub=document.getElementById('upcomingBox'), uv=document.getElementById('upcomingVal');
      if(d.upcoming && d.upcoming.length){
        ub.style.display='';
        uv.textContent='EP'+pad(d.upcoming[0].episode)+(d.upcoming[0].air_date?' ('+d.upcoming[0].air_date+')':'');
      } else ub.style.display='none';

      var ss=document.getElementById('sourceStatus'), ssw=document.getElementById('sourceStatusWrap');
      if(d.source_status){
        ssw.style.display='';
        ss.innerHTML=Object.keys(d.source_status).map(function(k){
          var s=d.source_status[k], color=STATUS_COLOR[s.status]||'rgba(255,255,255,.35)';
          var text=s.status==='ok' ? 'EP'+pad(s.value) : (STATUS_LABEL[s.status]||s.status);
          return '<span style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:5px;padding:3px 8px;font-size:.7rem;color:'+color+'"><strong style="color:rgba(255,255,255,.5)">'+s.label+':</strong> '+text+'</span>';
        }).join('');
      } else ssw.style.display='none';

      var dm=document.getElementById('detectMsg');
      var bb=document.getElementById('newEpBtns');
      var color=DECISION_COLOR[d.decision]||'rgba(255,255,255,.4)', icon=DECISION_ICON[d.decision]||'';
      dm.innerHTML='<span style="color:'+color+'">'+icon+' '+(d.decision_note||'')+'</span>';
      bb.innerHTML='';

      var quick=document.getElementById('btnQuick');
      if(d.decision==='missing_episodes' && d.missing_aired.length){
        bb.innerHTML=d.missing_aired.slice(0,10).map(function(e){
          return '<button onclick="fetchEp('+e.episode+')" class="btn btn-sm">⚡ EP'+pad(e.episode)+'<span style="opacity:.6;font-weight:400"> · '+e.air_date+(e.confidence?' · '+e.confidence:'')+'</span></button>';
        }).join('');
        quick.style.display='';
        quick.textContent='⚡ Quick: EP'+pad(d.missing_aired[0].episode);
        quick.onclick=function(){fetchEp(d.missing_aired[0].episode)};
        step(2); fetchEp(d.missing_aired[0].episode);
      } else {
        quick.style.display='none';
        step(1);
      }

      if(d.insufficient_evidence && d.insufficient_evidence.length && d.decision!=='missing_episodes'){
        bb.innerHTML='<span style="color:rgba(255,255,255,.35);font-size:.78rem;align-self:center">Unconfirmed candidate(s): '+d.insufficient_evidence.map(pad).join(', ')+' — use manual entry if you can verify the air date yourself.</span>';
      }
    }).catch(()=>{btn.innerHTML='🔍 Check for New EPs';btn.disabled=false;});
}

function fetchEp(n){
  document.getElementById('scrapeWrap').style.display='block';
  document.getElementById('scrapeMsg').innerHTML='<span class="spin"></span> Scraping EP'+pad(n)+'…';
  step(2);
  fetch(BP+'/admin/fetch.php?a=scrape&ep='+n).then(r=>r.json()).then(d=>{
    if(d.error){document.getElementById('scrapeMsg').innerHTML='<span style="color:#fcd34d">⚠ '+d.error+' — fill manually</span>';fill(n,{});}
    else{document.getElementById('scrapeMsg').innerHTML='<span style="color:#86efac">✓ Scraped from '+d.source+'</span>';fill(n,d);step(3);}
  }).catch(()=>{document.getElementById('scrapeMsg').innerHTML='⚠ Fetch failed';fill(n,{});});
}

function fill(n,d){
  var p=pad(n);
  document.getElementById('fEp').value=n;
  document.getElementById('fEpShow').value='Episode #'+p;
  document.getElementById('fTitle').value=d.title||'Episode #'+p;
  document.getElementById('fDate').value=d.air_date||'';
  document.getElementById('fSyn').value=d.synopsis||'';
  document.getElementById('fImg').value=d.image_url||'';
  document.getElementById('fGuests').value=(d.guests||[]).join('\n');
  document.getElementById('scEp').textContent='EP'+p;
  document.getElementById('scTitle').textContent=d.title||'Episode #'+p;
  document.getElementById('scDate').textContent=d.air_date||'Unknown date';
  var badges='';
  if(d.title&&d.title.includes(' - '))badges+='<span style="background:rgba(34,197,94,.1);color:#86efac;font-size:.65rem;padding:2px 7px;border-radius:3px;font-weight:700">Title ✓</span>';
  if(d.air_date)badges+='<span style="background:rgba(34,197,94,.1);color:#86efac;font-size:.65rem;padding:2px 7px;border-radius:3px;font-weight:700">Date ✓</span>';
  if(d.synopsis)badges+='<span style="background:rgba(34,197,94,.1);color:#86efac;font-size:.65rem;padding:2px 7px;border-radius:3px;font-weight:700">Synopsis ✓</span>';
  if(d.image_url)badges+='<span style="background:rgba(34,197,94,.1);color:#86efac;font-size:.65rem;padding:2px 7px;border-radius:3px;font-weight:700">Thumbnail ✓</span>';
  if(d.guests&&d.guests.length)badges+='<span style="background:rgba(41,171,226,.1);color:#29ABE2;font-size:.65rem;padding:2px 7px;border-radius:3px;font-weight:700">'+d.guests.length+' guests</span>';
  document.getElementById('scBadges').innerHTML=badges||'<span style="color:rgba(255,255,255,.3);font-size:.75rem">Fill manually</span>';
  var tp=document.getElementById('scThumb');
  tp.innerHTML=d.image_url?'<img src="'+d.image_url+'" style="width:100%;height:100%;object-fit:cover" onerror="this.parentNode.innerHTML=\'📺\'">':"📺";
  document.getElementById('scrapeWrap').scrollIntoView({behavior:'smooth',block:'start'});
}

// Batch thumbnail
var batchStop=false;
async function batchThumb(from,to){
  if(!confirm('Grab thumbnails for EP'+pad(from)+' to EP'+pad(to)+'?')) return;
  batchStop=false;
  document.getElementById('btnBatch').disabled=true;
  document.getElementById('btnStop').style.display='';
  document.getElementById('bProg').style.display='block';
  document.getElementById('bLog').innerHTML='';
  var total=to-from+1,done=0,ok=0;
  for(var i=from;i<=to;i++){
    if(batchStop)break;
    document.getElementById('bLbl').textContent='EP'+pad(i)+' ('+done+'/'+total+')';
    document.getElementById('bPct').textContent=Math.round(done/total*100)+'%';
    document.getElementById('bBar').style.width=(done/total*100)+'%';
    try{
      var r=await fetch(BP+'/admin/fetch.php?a=thumb&ep='+i);
      var d=await r.json();
      var li=document.createElement('div');li.style.cssText='padding:.15rem 0;border-bottom:1px solid rgba(41,171,226,.05)';
      li.innerHTML=(d.ok?'<span style="color:#86efac">✓</span>':'<span style="color:rgba(255,255,255,.3)">–</span>')+' EP'+pad(i)+(d.ok?' <span style="color:rgba(255,255,255,.25)">saved</span>':' <span style="color:rgba(255,255,255,.2)">'+(d.error||'skip')+'</span>');
      if(d.ok)ok++;
      var log=document.getElementById('bLog');log.appendChild(li);log.scrollTop=log.scrollHeight;
    }catch(e){}
    done++;
    await new Promise(r=>setTimeout(r,700));
  }
  document.getElementById('bLbl').textContent=(batchStop?'Stopped':'Done')+' — '+ok+' saved';
  document.getElementById('bBar').style.width='100%';
  document.getElementById('bBar').style.background=ok>0?'#22c55e':'#29ABE2';
  document.getElementById('btnBatch').disabled=false;
  document.getElementById('btnStop').style.display='none';
  toast('Done: '+ok+' thumbnails saved');
}
</script>
</body></html>
