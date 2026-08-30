<?php
require_once __DIR__ . '/../includes/functions.php';
$db      = getDB();
$themes  = getThemes(); // Only themes that have episodes linked
$allThemes = $db->query("SELECT * FROM themes ORDER BY name")->fetchAll();
$totalEps  = (int)$db->query("SELECT COUNT(*) FROM episodes")->fetchColumn();
$hasThemed = (int)$db->query("SELECT COUNT(*) FROM episodes WHERE theme_id IS NOT NULL")->fetchColumn();
$pageTitle = 'Themes';
include __DIR__ . '/../includes/header.php';
?>
<div style="padding-top:2rem">
<h1 class="page-title">Themes</h1>

<?php if ($hasThemed === 0): ?>
<div class="alert alert-info" style="margin-bottom:1.5rem">
  ℹ Theme assignments require syncing episode data from myrm.tv.
  Go to <a href="<?= bp() ?>/admin/auto_sync.php" style="color:var(--blue);font-weight:600">Admin → Auto Sync</a> to populate themes automatically.
</div>

<?php if (!empty($allThemes)): ?>
<div class="sh">Available Themes</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;margin-bottom:2rem">
<?php foreach ($allThemes as $t): ?>
<div style="background:var(--s1);border:1px solid var(--b1);border-radius:var(--rm);padding:1.1rem;opacity:.6">
  <div style="font-weight:700;font-size:.88rem;color:var(--t1);margin-bottom:.25rem"><?= h($t['name']) ?></div>
  <?php if ($t['description']): ?>
  <div style="font-size:.75rem;color:var(--t4)"><?= h($t['description']) ?></div>
  <?php endif; ?>
  <div style="font-size:.7rem;color:var(--t4);margin-top:.5rem">No episodes assigned yet</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;margin-bottom:3rem">
<?php foreach ($themes as $t): ?>
<a href="<?= bp() ?>/search.php?theme_id=<?= $t['theme_id'] ?>"
   class="card" style="padding:1.4rem;text-decoration:none;color:inherit;display:block">
  <div style="font-weight:700;font-size:.95rem;color:var(--t1);margin-bottom:.35rem"><?= h($t['name']) ?></div>
  <?php if ($t['description']): ?>
  <div style="font-size:.78rem;color:var(--t3);margin-bottom:.65rem;line-height:1.5"><?= h($t['description']) ?></div>
  <?php endif; ?>
  <span class="badge b-blue"><?= $t['episode_count'] ?> episodes</span>
</a>
<?php endforeach; ?>
</div>

<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
