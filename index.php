<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/components.php';

$bp2      = bp();
$hero     = getLatestAiredEpisode();
$latest   = getRecentAiredEpisodes(12, $hero['episode_number'] ?? null);
$featured = getFeaturedEpisodes(10);
$themes   = getThemesForDiscovery(6);
$onThisDay= getOnThisDay(8);
$years    = getYearStats();
$stats    = getArchiveStats();

$heroSrc = $hero ? thumbSrc($hero) : '';
$heroDesc = $hero ? (($hero['synopsis'] ?? '') ?: generateEpisodeSummary($hero, [])) : '';

$pageDescription = 'The complete Running Man fan archive — every episode, guest, mission and location since 2010, searchable by year, theme and special.';
$ogImage = $heroSrc ?: null;
include __DIR__ . '/includes/header.php';
?>

<!-- ══ HERO: latest AIRED episode (never an upcoming one) ══ -->
<?php if ($hero): $hn = str_pad((string)$hero['episode_number'], 3, '0', STR_PAD_LEFT); ?>
<section class="hero-ep">
  <?php if ($heroSrc): ?>
    <img src="<?= h($heroSrc) ?>" alt="" class="hero-ep-bg" loading="eager">
  <?php else: ?>
    <div class="hero-ep-noimg" aria-hidden="true">R</div>
  <?php endif; ?>
  <div class="hero-ep-scrim"></div>
  <div class="hero-ep-body">
    <span class="hero-ep-eyebrow">🏃 Latest Aired · Episode #<?= $hn ?></span>
    <h2><?= h(displayTitle($hero['title'] ?? '')) ?></h2>
    <div class="hero-ep-meta">
      <?php if (!empty($hero['air_date'])): ?><span>📅 <?= h($hero['air_date']) ?></span><?php endif; ?>
      <?php if (!empty($hero['location_name'])): ?><span>📍 <?= h($hero['location_name']) ?></span><?php endif; ?>
      <?php if (!empty($hero['guests'])): ?><span>👥 <?= count($hero['guests']) ?> guest<?= count($hero['guests'])===1?'':'s' ?></span><?php endif; ?>
      <?php if (!empty($hero['is_special'])): ?><span class="badge b-yel">⭐ Special</span><?php endif; ?>
    </div>
    <?php if ($heroDesc): ?><p class="hero-ep-desc"><?= h(mb_strimwidth((string)$heroDesc, 0, 220, '…')) ?></p><?php endif; ?>
    <div class="hero-ep-actions">
      <a href="<?= h(episodeUrl((int)$hero['episode_number'])) ?>" class="btn btn-yel">▶ Watch Details</a>
      <a href="<?= $bp2 ?>/search.php" class="btn btn-dark">Explore Archive</a>
    </div>
  </div>
</section>
<?php else: ?>
<?= renderEmptyState('🏃', 'No episodes yet', 'The archive is empty — sync data from Admin to get started.') ?>
<?php endif; ?>

<!-- ══ CONTINUE BROWSING (localStorage-only, hidden until populated) ══ -->
<section id="continueBrowsing" hidden aria-label="Continue browsing">
  <div class="rail-head"><h2 class="sec-h" style="margin-bottom:0">Continue Browsing</h2></div>
  <div class="rail-viewport"><div class="rail"></div></div>
</section>

<!-- ══ SEARCH ══ -->
<form method="GET" action="<?= $bp2 ?>/search.php" role="search">
  <div class="search-wrap">
    <span class="search-icon" aria-hidden="true">🔍</span>
    <label for="homeSearch" class="sr-only">Search episodes</label>
    <input type="text" id="homeSearch" name="q" placeholder="Search episodes, missions, locations, guests…" autocomplete="off">
    <button type="submit">Search</button>
  </div>
</form>

<!-- ══ TOPIC TILES ══ -->
<div class="topic-strip">
  <a href="<?= $bp2 ?>/pages/specials.php?type=chuseok"   class="topic-tile tt-1"><span class="tile-icon">🌕</span>Chuseok Specials</a>
  <a href="<?= $bp2 ?>/pages/specials.php?type=overseas"  class="topic-tile tt-2"><span class="tile-icon">✈️</span>Overseas Trips</a>
  <a href="<?= $bp2 ?>/pages/specials.php?type=milestone" class="topic-tile tt-3"><span class="tile-icon">🏆</span>Milestone EPs</a>
  <a href="<?= $bp2 ?>/pages/specials.php?type=farewell"  class="topic-tile tt-4"><span class="tile-icon">👋</span>Farewell Episodes</a>
  <a href="<?= $bp2 ?>/search.php?special=0"              class="topic-tile tt-5"><span class="tile-icon">🏃</span>Regular Races</a>
</div>

<!-- ══ LATEST EPISODES ══ -->
<section aria-label="Latest episodes" style="margin-bottom:2.75rem">
<?= renderRail('Latest Episodes', $latest, $bp2 . '/search.php') ?>
</section>

<!-- ══ FEATURED EPISODES ══ -->
<section aria-label="Featured episodes" style="margin-bottom:2.75rem">
<?= renderRail('Featured Episodes', $featured, $bp2 . '/search.php', fn($ep) => renderEpisodeCard($ep, ['synopsis' => true]), 'Nothing to feature yet — keep the archive syncing.') ?>
</section>

<!-- ══ ON THIS DAY ══ -->
<section aria-label="On this day" style="margin-bottom:2.75rem">
  <div class="rail-head"><h2 class="sec-h" style="margin-bottom:0">On This Day</h2><span style="font-size:.78rem;color:var(--t4)"><?= date('F j') ?></span></div>
  <?php if ($onThisDay): ?>
  <div class="related-grid">
    <?php foreach ($onThisDay as $ep): $on = str_pad((string)$ep['episode_number'],3,'0',STR_PAD_LEFT); $osrc = thumbSrc($ep); ?>
    <a href="<?= h(episodeUrl((int)$ep['episode_number'])) ?>" class="otd-card">
      <div class="otd-thumb"><?php if ($osrc): ?><img src="<?= h($osrc) ?>" alt="" loading="lazy"><?php else: ?><div class="thumb-ph">R</div><?php endif; ?></div>
      <div><div class="otd-year"><?= h(substr((string)$ep['air_date'],0,4)) ?> · EP<?= $on ?></div>
      <div class="otd-title"><?= h(displayTitle($ep['title'] ?? '')) ?></div></div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <?= renderEmptyState('📅', 'No episodes on this day', 'No aired episode in the archive shares today\'s calendar date — check back tomorrow.') ?>
  <?php endif; ?>
</section>

<!-- ══ BROWSE BY THEME ══ -->
<section aria-label="Browse by theme" style="margin-bottom:2.75rem">
  <div class="rail-head"><h2 class="sec-h" style="margin-bottom:0">Browse by Theme</h2>
    <a href="<?= $bp2 ?>/pages/themes.php" class="btn btn-ghost btn-sm">View all →</a>
  </div>
  <?php if ($themes): ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">
    <?php foreach ($themes as $t): ?><?= renderThemeCard($t) ?><?php endforeach; ?>
  </div>
  <?php else: ?>
  <?= renderEmptyState('🎭', 'No themes assigned yet', 'Themes are populated automatically as episode data syncs.') ?>
  <?php endif; ?>
</section>

<!-- ══ EXPLORE BY YEAR ══ -->
<section aria-label="Explore by year" style="margin-bottom:2.75rem">
  <h2 class="sec-h">Explore by Year</h2>
  <?php $realYears = array_values(array_filter(array_reverse($years), fn($y) => $y['total_episodes'])); ?>
  <?php if ($realYears): ?>
  <div class="year-grid">
    <?php foreach ($realYears as $y): ?><?= renderYearCard($y) ?><?php endforeach; ?>
  </div>
  <?php else: ?>
  <?= renderEmptyState('🗓️', 'No years yet', 'Import or sync episode data to populate the archive by year.') ?>
  <?php endif; ?>
</section>

<!-- ══ SPECIALS ══ -->
<?php $specialEps = array_filter($latest, fn($e) => !empty($e['is_special'])); ?>
<section aria-label="Specials" style="margin-bottom:1rem">
<?= renderRail('Specials', array_slice(array_values($specialEps), 0, 8), $bp2 . '/pages/specials.php', null, 'No special episodes found in recent airings — see the full Specials page.') ?>
</section>

<!-- ══ SURPRISE ME ══ -->
<section class="surprise-panel" aria-label="Surprise me">
  <div class="surprise-copy">
    <h2>Feeling lucky?</h2>
    <p>Jump to a random, reasonably complete episode from the archive — no episode number required.</p>
  </div>
  <a href="<?= $bp2 ?>/surprise.php" class="btn btn-yel">🎲 Surprise Me</a>
</section>

<!-- ══ ARCHIVE STATISTICS ══ -->
<section aria-label="Archive statistics">
  <h2 class="sec-h">Archive at a Glance</h2>
  <?= renderStatsSection($stats) ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.rmInitContinueBrowsing) window.rmInitContinueBrowsing('continueBrowsing', '<?= h($bp2) ?>');
});
</script>
