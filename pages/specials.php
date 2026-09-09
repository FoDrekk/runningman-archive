<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/components.php';

$type = trim($_GET['type'] ?? '');
$page = max(1,(int)($_GET['page'] ?? 1));
$db   = getDB();

// Count total episodes for context
$totalEps = (int)$db->query("SELECT COUNT(*) FROM episodes")->fetchColumn();
$hasSpecials = (int)$db->query("SELECT COUNT(*) FROM episodes WHERE is_special=1")->fetchColumn();

$types = [
    ''              => ['label'=>'All Specials',    'icon'=>'⭐'],
    'chuseok'       => ['label'=>'Chuseok',         'icon'=>'🌕'],
    'lunar_new_year'=> ['label'=>'Lunar New Year',  'icon'=>'🧧'],
    'anniversary'   => ['label'=>'Anniversary',     'icon'=>'🎂'],
    'milestone'     => ['label'=>'Milestone',       'icon'=>'🏆'],
    'overseas'      => ['label'=>'Overseas',        'icon'=>'✈️'],
    'halloween'     => ['label'=>'Halloween',       'icon'=>'🎃'],
    'farewell'      => ['label'=>'Farewell',        'icon'=>'👋'],
    'year_end'      => ['label'=>'Year-End',        'icon'=>'🎆'],
    'fan_meeting'   => ['label'=>'Fan Meeting',     'icon'=>'🎤'],
];

$filters = ['is_special' => 1];
if ($type) $filters['special_type'] = $type;
$result   = getEpisodeList($page, 24, $filters);
$episodes = $result['episodes'];
$pg       = $result['pagination'];
$pageTitle= ($type && isset($types[$type])) ? $types[$type]['label'].' Specials' : 'Special Episodes';
$pageDescription = 'Chuseok, overseas trips, milestones, farewells and other special Running Man episodes.';

include __DIR__ . '/../includes/header.php';
$bp2 = bp();
?>
<div style="padding-top:2rem">
<h1 class="page-title">Special Episodes</h1>

<!-- Type tabs -->
<div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1.75rem;padding-bottom:1rem;border-bottom:1px solid var(--b1)">
<?php foreach ($types as $key=>$t): ?>
<a href="<?= $bp2 ?>/pages/specials.php<?= $key?'?type='.$key:'' ?>"
   style="display:inline-flex;align-items:center;gap:.35rem;padding:.38rem .85rem;border-radius:var(--rxl);font-size:.8rem;font-weight:600;text-decoration:none;transition:.12s;
          <?= ($type===$key)?'background:var(--blue);color:#fff;':'background:var(--s2);color:var(--t3);border:1px solid var(--b1);' ?>">
  <?= $t['icon'] ?> <?= $t['label'] ?>
</a>
<?php endforeach; ?>
</div>

<?php if ($hasSpecials === 0): ?>
<!-- No specials marked yet — show helpful message + title-based search -->
<div class="alert alert-info" style="margin-bottom:1.5rem">
  ℹ No episodes marked as specials yet. Specials are set automatically when you sync episode data, or you can run the SQL below to mark known specials.
</div>

<?php
// Try to find specials from title keywords
$titleSpecials = $db->query("
    SELECT episode_number, title, air_date FROM episodes
    WHERE title REGEXP 'Chuseok|추석|Lunar|설날|Halloween|Christmas|Anniversary|Farewell|Finale|Special|Overseas|Japan|China|Taiwan|Hong Kong|Thailand|Singapore|Australia|USA|Europe'
    ORDER BY episode_number ASC LIMIT 30
")->fetchAll();

if ($titleSpecials):
?>
<div class="ap" style="margin-bottom:1.5rem">
  <div class="sh">Detected Specials from Titles (<?= count($titleSpecials) ?> found)</div>
  <p style="font-size:.8rem;color:rgba(255,255,255,.4);margin-bottom:1rem">
    These episodes appear to be specials based on their titles. Sync data or run
    <strong style="color:#FFD700">Admin → Auto Sync</strong> to properly mark them.
  </p>
  <div style="display:flex;flex-wrap:wrap;gap:.4rem">
    <?php foreach ($titleSpecials as $ep): ?>
    <a href="<?= episodeUrl($ep['episode_number']) ?>"
       style="background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.2);border-radius:6px;padding:3px 9px;font-size:.72rem;color:#FFD700;text-decoration:none;transition:.1s"
       title="<?= h($ep['title']) ?>">
      EP<?= str_pad($ep['episode_number'],3,'0',STR_PAD_LEFT) ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php else: ?>

<?php if ($pg['total']>0): ?>
<div style="font-size:.8rem;color:var(--t4);margin-bottom:1.2rem">
  <?= number_format($pg['total']) ?> episode<?= $pg['total']!==1?'s':'' ?>
</div>
<div class="ep-grid">
<?php foreach ($episodes as $ep): echo renderEpisodeCard($ep); endforeach; ?>
</div>

<?php if ($pg['total_pages']>1):
  $base = array_filter(['type'=>$type]);
  $c=$pg['current_page']; $tot=$pg['total_pages'];
?>
<nav class="pagination">
  <?php if ($pg['has_prev']): ?><a href="<?= $bp2.'/pages/specials.php?'.http_build_query(array_merge($base,['page'=>$c-1])) ?>">&laquo;</a><?php endif; ?>
  <?php for($i=max(1,$c-2);$i<=min($tot,$c+2);$i++) echo $i===$c?"<span class=\"cur\">$i</span>":'<a href="'.$bp2.'/pages/specials.php?'.http_build_query(array_merge($base,['page'=>$i])).'">'.$i.'</a>'; ?>
  <?php if ($pg['has_next']): ?><a href="<?= $bp2.'/pages/specials.php?'.http_build_query(array_merge($base,['page'=>$c+1])) ?>">&raquo;</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php else: ?>
<div class="empty-state">
  <span class="ei">⭐</span>
  <h3>No <?= h($pageTitle) ?> found</h3>
  <p>This category has no episodes yet.</p>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
