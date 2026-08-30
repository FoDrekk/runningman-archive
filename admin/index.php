<?php
// BUGFIX: AJAX/action handlers MUST run before layout.php — same rule
// followed everywhere else in /admin (see auto_sync.php, fetch.php,
// health.php, thumbnails.php for the identical comment). This was the
// one file that didn't follow it: layout.php prints the full HTML page
// the instant it's required (no output buffering), so by the time the
// "?s=1" check ran below, the entire dashboard page had already been
// sent to the browser. The JSON response ended up as
// "<html>...sidebar...<main>{"total":807,...}" — invalid JSON. The
// dashboard's own fetch('index.php?s=1').then(r=>r.json()) at the
// bottom of this page silently failed and hit .catch(()=>{}), which is
// exactly why the 5 stat boxes (Total Episodes, Missing #s, etc.) and
// the progress bar stayed stuck on the loading skeleton/"Loading…"
// forever — no error ever surfaced, it just never populated.
if (isset($_GET['s'])) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/functions.php';
    adminCheck();
    header('Content-Type: application/json');
    echo json_encode(getStats());
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

<!-- Stats — skeleton, loaded async -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.9rem;margin-bottom:1.6rem">
  <?php foreach ([
    ['st-total', 'Episodes',      '#FFD700'],
    ['st-miss',  'Missing #s',    '#86efac'],
    ['st-syn',   'No Synopsis',   '#fcd34d'],
    ['st-thumb', 'No Thumbnail',  '#fcd34d'],
    ['st-pct',   'Complete',      '#29ABE2'],
  ] as [$id,$lbl,$col]): ?>
  <div style="background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:13px;padding:1.3rem 1.1rem;text-align:center">
    <div id="<?= $id ?>" style="font-size:1.8rem;font-weight:900;letter-spacing:-.04em;margin-bottom:.18rem;color:<?= $col ?>">
      <div style="background:rgba(41,171,226,.08);border-radius:4px;height:2.2rem;animation:sk 1.2s infinite"></div>
    </div>
    <div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28)"><?= $lbl ?></div>
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
    ['⚡','Fetch Latest EP',  'fetch.php',      true],
    ['⏱️','Weekly Update',    'cron.php',       true],
    ['🔄','Auto Sync Data',   'auto_sync.php',       false],
    ['🖼️','Grab Thumbnails', 'thumbnails.php', false],
    ['📥','Import Excel/CSV', 'import.php',      false],
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
    c('st-miss',  d.missing,    d.missing>0?'#fca5a5':'#86efac');
    c('st-syn',   d.no_synopsis,d.no_synopsis>0?'#fcd34d':'#86efac');
    c('st-thumb', d.thumb_missing,d.thumb_missing>0?'#fcd34d':'#86efac');
    c('st-pct',   d.pct+'%',   d.pct>=100?'#86efac':'#29ABE2');
    document.getElementById('prog-lbl').textContent='EP'+d.first_ep+'–EP'+d.last_ep;
    document.getElementById('prog-val').textContent=d.total+' / '+d.expected+' · '+d.pct+'%';
    setTimeout(()=>document.getElementById('prog-bar').style.width=Math.min(d.pct,100)+'%',120);
  }).catch(()=>{});
</script>
</body></html>
