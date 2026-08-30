<?php
require_once __DIR__ . '/includes/functions.php';

$q         = trim($_GET['q']      ?? '');
$year      = (int)($_GET['year']  ?? 0);
$themeId   = (int)($_GET['theme_id'] ?? 0);
$isSpecial = isset($_GET['special']) ? (int)$_GET['special'] : null;
$specType  = trim($_GET['type']   ?? '');
$page      = max(1,(int)($_GET['page'] ?? 1));

$filters = [];
if ($q)                  $filters['search']      = $q;
if ($year)               $filters['year']        = $year;
if ($themeId)            $filters['theme_id']    = $themeId;
if ($isSpecial !== null) $filters['is_special']  = $isSpecial;
if ($specType)           $filters['special_type']= $specType;

$result   = getEpisodeList($page, 24, $filters);
$episodes = $result['episodes'];
$pg       = $result['pagination'];
$themes   = getThemes();
$years    = getYearStats();

if ($q)              $pageTitle = "Search: $q";
elseif ($year)       $pageTitle = "$year Episodes";
elseif ($isSpecial===1) $pageTitle = "Special Episodes";
else                 $pageTitle = "All Episodes";

include __DIR__ . '/includes/header.php';
$bp2 = bp();
?>
<div style="padding-top:2rem">

<div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem">
  <h1 class="page-title" style="margin-bottom:0">
    <?php if ($q): ?>Results for <em style="color:var(--yel)">"<?= h($q) ?>"</em>
    <?php elseif ($year): ?><?= $year ?> Episodes
    <?php elseif ($isSpecial===1): ?>Special Episodes
    <?php else: ?>All Episodes<?php endif; ?>
  </h1>
  <?php if ($pg['total']>0): ?>
  <span style="font-size:.8rem;color:var(--t4)">
    <?= number_format($pg['total']) ?> episodes
    <?php if ($pg['total_pages']>1): ?> · page <?= $pg['current_page'] ?> of <?= $pg['total_pages'] ?><?php endif; ?>
  </span>
  <?php endif; ?>
</div>

<form method="GET" action="<?= $bp2 ?>/search.php">
  <div class="search-wrap" style="margin-bottom:1rem">
    <span class="search-icon">🔍</span>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search episodes…" autocomplete="off">
    <?php if ($q||$year||$themeId||$isSpecial!==null): ?>
      <a href="<?= $bp2 ?>/search.php" class="btn btn-ghost btn-sm" style="margin:.38rem 0 .38rem .38rem">✕</a>
    <?php endif; ?>
    <button type="submit">Search</button>
  </div>
  <div class="filter-bar" style="margin-bottom:1.5rem">
    <span class="filter-label">Filter:</span>
    <select name="year">
      <option value="">All Years</option>
      <?php foreach (array_reverse($years) as $y): if (!$y['total_episodes']) continue; ?>
      <option value="<?= $y['year_label'] ?>" <?= ($year==$y['year_label'])?'selected':'' ?>>
        <?= $y['year_label'] ?> (<?= $y['total_episodes'] ?>)
      </option>
      <?php endforeach; ?>
    </select>
    <select name="theme_id">
      <option value="">All Themes</option>
      <?php foreach ($themes as $t): ?>
      <option value="<?= $t['theme_id'] ?>" <?= ($themeId==$t['theme_id'])?'selected':'' ?>>
        <?= h($t['name']) ?> (<?= $t['episode_count'] ?>)
      </option>
      <?php endforeach; ?>
    </select>
    <select name="special">
      <option value="">All Episodes</option>
      <option value="1" <?= ($isSpecial===1)?'selected':'' ?>>Specials Only</option>
      <option value="0" <?= ($isSpecial===0)?'selected':'' ?>>Regular Only</option>
    </select>
  </div>
</form>

<?php if ($pg['total']===0): ?>
<div class="empty-state">
  <span class="ei">🏃</span>
  <h3>No episodes found</h3>
  <p>Try different search terms or clear your filters.</p>
  <a href="<?= $bp2 ?>/search.php" class="btn">Clear filters</a>
</div>
<?php else: ?>
<div class="ep-grid">
<?php foreach ($episodes as $ep):
  $n = str_pad($ep['episode_number'],3,'0',STR_PAD_LEFT);
  $src = thumbSrc($ep);
  $dispTitle = $ep['title'] ?? '';
  if (preg_match('/^Episode\s*#\d+\s*-\s*(.+)$/i',$dispTitle,$m)) $dispTitle=$m[1];
  elseif (preg_match('/^Episode\s*#\d+$/i',$dispTitle)) $dispTitle='Running Man';
?>
<div class="card ep-card" data-href="<?= episodeUrl($ep['episode_number']) ?>" tabindex="0">
  <div class="ep-thumb">
    <?php if ($src): ?><img src="<?= h($src) ?>" alt="Episode #<?= $n ?>" loading="lazy">
    <?php else: ?><div class="thumb-ph">R</div><?php endif; ?>
    <span class="ep-num-badge">EP<?= $n ?></span>
  </div>
  <div class="ep-body">
    <span class="ep-num">Episode #<?= $n ?></span>
    <div class="ep-title"><?= h($dispTitle ?: 'Running Man') ?></div>
    <div class="ep-date"><?= h($ep['air_date'] ?? '') ?></div>
    <div class="ep-meta">
      <?php if ($ep['is_special']): ?><span class="badge b-yel">⭐ Special</span><?php endif; ?>
      <?php if ($ep['theme_name']): ?><span class="badge b-gray"><?= h($ep['theme_name']) ?></span><?php endif; ?>
      <?php if ($ep['verification_required']): ?><span class="badge b-warn">Unverified</span><?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php if ($pg['total_pages']>1):
  // BUGFIX: was plain array_filter($base) with no callback — PHP's
  // default array_filter() removes "0" along with null/''/false. That
  // silently dropped the special=0 ("Regular Only") filter from every
  // pagination link, so clicking page 2 while filtering "Regular Only"
  // would reset back to showing all episodes. Use the same !==null
  // callback as pgUrl() below, so only genuinely-unset filters are
  // dropped and "0" survives.
  $base = array_filter(['q'=>$q,'year'=>$year?:null,'theme_id'=>$themeId?:null,'special'=>$isSpecial!==null?(string)$isSpecial:null], fn($v)=>$v!==null);
  function pgUrl(array $b,int $p): string { global $bp2; return $bp2.'/search.php?'.http_build_query(array_merge(array_filter($b,fn($v)=>$v!==null),['page'=>$p])); }
  $c=$pg['current_page']; $tot=$pg['total_pages'];
?>
<nav class="pagination">
  <?php if ($pg['has_prev']): ?><a href="<?= pgUrl($base,$c-1) ?>">&laquo;</a><?php endif; ?>
  <?php
  $s=max(1,$c-2); $e=min($tot,$c+2);
  if ($s>1){ echo '<a href="'.pgUrl($base,1).'">1</a>'; if($s>2) echo '<span class="dots">…</span>'; }
  for($i=$s;$i<=$e;$i++) echo $i===$c ? "<span class=\"cur\">$i</span>" : '<a href="'.pgUrl($base,$i).'">'.$i.'</a>';
  if ($e<$tot){ if($e<$tot-1) echo '<span class="dots">…</span>'; echo '<a href="'.pgUrl($base,$tot).'">'.$tot.'</a>'; }
  ?>
  <?php if ($pg['has_next']): ?><a href="<?= pgUrl($base,$c+1) ?>">&raquo;</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
