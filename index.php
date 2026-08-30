<?php
require_once __DIR__ . '/includes/functions.php';
$bp        = bp();
$cssV      = @filemtime(__DIR__.'/assets/css/style.css') ?: 1;
$stats     = getStats();
$yearStats = getYearStats();
$recent    = getEpisodeList(1, 10)['episodes'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1200">
<title>Home | Running Man Archive</title>
<link rel="stylesheet" href="<?= $bp ?>/assets/css/style.css?v=<?= $cssV ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Noto+Sans+KR:wght@400;500;700;900&display=swap" rel="stylesheet">
</head>
<body>

<header class="site-header">
  <div class="container">
    <div class="header-inner">
      <a href="<?= $bp ?>/index.php" class="site-logo">
        <div class="logo-circle"><span class="logo-r">R</span></div>
        <div class="logo-text">
          <span class="logo-name">Running Man</span>
          <span class="logo-sub">Archive</span>
        </div>
      </a>
      <nav class="site-nav">
        <a href="<?= $bp ?>/index.php" class="active">Home</a>
        <a href="<?= $bp ?>/search.php">Episodes</a>
        <a href="<?= $bp ?>/pages/specials.php">Specials</a>
        <a href="<?= $bp ?>/pages/themes.php">Themes</a>
        <a href="<?= $bp ?>/pages/years.php">By Year</a>
        <a href="<?= $bp ?>/admin/" class="nav-cta">⚡ Admin</a>
      </nav>
    </div>
  </div>
</header>

<main class="site-main" style="padding-top:0">

<!-- HERO -->
<section class="hero">
  <div class="container">
    <div class="hero-inner" style="justify-content:center">
      <div class="hero-copy">
        <div class="hero-eyebrow">🏃 SBS 런닝맨 · Running Man · 2010–Present</div>
        <h1>Every Episode.<br><em>Every Race. Every Guest.</em></h1>
        <p class="hero-desc">The complete Running Man fan archive — searchable by episode, special type, theme, and year.</p>
        <div class="hero-stats">
          <div class="hero-stat"><span class="num"><?= number_format($stats['total']) ?></span><span class="lbl">Episodes</span></div>
          <div class="hero-stat"><span class="num">EP<?= str_pad($stats['last_ep'],3,'0',STR_PAD_LEFT) ?></span><span class="lbl">Latest</span></div>
          <div class="hero-stat"><span class="num"><?= $stats['pct'] ?>%</span><span class="lbl">Complete</span></div>
        </div>
      </div>

    </div>
  </div>
</section>

<div class="container" style="padding-top:3rem">

<!-- SEARCH -->
<form method="GET" action="<?= $bp ?>/search.php">
  <div class="search-wrap">
    <span class="search-icon">🔍</span>
    <input type="text" name="q" placeholder="Search episodes, missions, locations…" autocomplete="off">
    <button type="submit">Search</button>
  </div>
</form>

<!-- TOPIC TILES -->
<div class="topic-strip">
  <a href="<?= $bp ?>/pages/specials.php?type=chuseok"    class="topic-tile tt-1"><span class="tile-icon">🌕</span>Chuseok Specials</a>
  <a href="<?= $bp ?>/pages/specials.php?type=overseas"   class="topic-tile tt-2"><span class="tile-icon">✈️</span>Overseas Trips</a>
  <a href="<?= $bp ?>/pages/specials.php?type=milestone"  class="topic-tile tt-3"><span class="tile-icon">🏆</span>Milestone EPs</a>
  <a href="<?= $bp ?>/pages/specials.php?type=farewell"   class="topic-tile tt-4"><span class="tile-icon">👋</span>Farewell Episodes</a>
  <a href="<?= $bp ?>/search.php?special=0"               class="topic-tile tt-5"><span class="tile-icon">🏃</span>Regular Races</a>
</div>

<!-- LATEST EPISODES -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem">
  <h2 class="sec-h" style="margin-bottom:0;flex:1">Latest Episodes</h2>
  <a href="<?= $bp ?>/search.php" class="btn btn-ghost btn-sm">Browse all <?= number_format($stats['total']) ?> →</a>
</div>
<div class="ep-grid" style="margin-bottom:2.5rem">
<?php foreach ($recent as $ep):
  $n = str_pad($ep['episode_number'],3,'0',STR_PAD_LEFT);
  $src = thumbSrc($ep); ?>
<div class="card ep-card" data-href="<?= $bp ?>/episode.php?ep=<?= $ep['episode_number'] ?>" tabindex="0">
  <div class="ep-thumb">
    <?php if ($src): ?>
      <img src="<?= h($src) ?>" alt="Episode #<?= $n ?>" loading="lazy">
    <?php else: ?>
      <div class="thumb-ph">R</div>
    <?php endif; ?>
    <span class="ep-num-badge">EP<?= $n ?></span>
  </div>
  <div class="ep-body">
    <span class="ep-num">Episode #<?= $n ?></span>
    <div class="ep-title">
      <?php
      $t = $ep['title'] ?? '';
      echo h(preg_match('/^Episode\s*#\d+\s*-\s*(.+)$/i',$t,$m) ? $m[1] : ($t ?: 'Running Man'));
      ?>
    </div>
    <div class="ep-date"><?= h($ep['air_date'] ?? '') ?></div>
    <div class="ep-meta">
      <?php if ($ep['is_special']): ?><span class="badge b-yel">⭐ Special</span><?php endif; ?>
      <?php if ($ep['theme_name']): ?><span class="badge b-gray"><?= h($ep['theme_name']) ?></span><?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- YEAR GRID -->
<h2 class="sec-h">Browse by Year</h2>
<div class="year-grid" style="margin-bottom:3rem">
<?php foreach (array_reverse($yearStats) as $y): if (!$y['total_episodes']) continue; ?>
<a href="<?= $bp ?>/search.php?year=<?= $y['year_label'] ?>" class="year-card">
  <div class="yr"><?= $y['year_label'] ?></div>
  <div class="yr-range">EP<?= str_pad($y['first_ep'],3,'0',STR_PAD_LEFT) ?>–EP<?= str_pad($y['last_ep'],3,'0',STR_PAD_LEFT) ?></div>
  <div class="yr-count"><?= $y['total_episodes'] ?> episodes</div>
</a>
<?php endforeach; ?>
</div>

</div>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-inner">
      <div>
        <div class="footer-brand"><div class="footer-logo">R</div><span class="footer-name">Running Man Archive</span></div>
        <p class="footer-note">Fan archive · SBS 런닝맨 2010–present · Data sourced from myrm.tv &amp; myrunningman.com</p>
      </div>
      <nav class="footer-links">
        <a href="<?= $bp ?>/index.php">Home</a>
        <a href="<?= $bp ?>/search.php">Episodes</a>
        <a href="<?= $bp ?>/pages/specials.php">Specials</a>
        <a href="<?= $bp ?>/admin/">Admin</a>
      </nav>
    </div>
  </div>
</footer>
<script src="<?= $bp ?>/assets/js/main.js"></script>
</body></html>
