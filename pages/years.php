<?php
require_once __DIR__ . '/../includes/functions.php';
$yearStats = getYearStats();
$totalEps  = array_sum(array_column($yearStats, 'total_episodes'));
$pageTitle = 'Browse by Year';
include __DIR__ . '/../includes/header.php';
?>
<div style="padding-top:2rem">
<h1 class="page-title">Browse by Year</h1>

<?php if ($totalEps === 0): ?>
<div class="alert alert-warn">
  ⚠ No episodes imported yet. Please import SQL files from the database setup package.
  <a href="<?= bp() ?>/admin/" style="color:var(--yel);margin-left:.5rem">Go to Admin →</a>
</div>
<?php else: ?>

<?php if ($totalEps < 200): ?>
<div class="alert alert-info" style="margin-bottom:1.5rem">
  ℹ <?= number_format($totalEps) ?> episodes loaded. Import all SQL files to see 2010–2023.
  <a href="<?= bp() ?>/admin/" style="color:var(--blue);margin-left:.5rem">Admin →</a>
</div>
<?php endif; ?>

<div class="year-grid" style="margin-bottom:3rem">
<?php foreach (array_reverse($yearStats) as $y):
  if (!$y['total_episodes']) continue; ?>
<a href="<?= bp() ?>/search.php?year=<?= $y['year_label'] ?>" class="year-card">
  <div class="yr"><?= $y['year_label'] ?></div>
  <div class="yr-range">
    EP<?= str_pad($y['first_ep'],3,'0',STR_PAD_LEFT) ?>–EP<?= str_pad($y['last_ep'],3,'0',STR_PAD_LEFT) ?>
  </div>
  <div class="yr-count"><?= $y['total_episodes'] ?> episodes</div>
  <?php if ($y['specials']??0): ?>
  <div style="font-size:.68rem;color:var(--t4);margin-top:.18rem"><?= $y['specials'] ?> specials</div>
  <?php endif; ?>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
