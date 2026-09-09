<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/components.php';
$yearStats = getYearStats();
$totalEps  = array_sum(array_column($yearStats, 'total_episodes'));
$pageTitle = 'Browse by Year';
$pageDescription = 'Browse Running Man episodes year by year, from 2010 to present.';
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
  if (!$y['total_episodes']) continue;
  echo renderYearCard($y);
endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
