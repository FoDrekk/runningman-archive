<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/components.php';
$epNum = (int)($_GET['ep'] ?? 0);
if (!$epNum) { header('Location: '.bp().'/search.php'); exit; }

$ep = getEpisode($epNum);
if (!$ep) {
    http_response_code(404);
    $pageTitle = 'Episode Not Found';
    $pageDescription = 'This episode number does not exist in the Running Man Archive.';
    include __DIR__ . '/includes/header.php';
    echo renderEmptyState('🔍', 'Episode not found', "There's no record of episode #$epNum in the archive.",
        '<a href="' . h(bp()) . '/search.php" class="btn">Browse all episodes</a>');
    include __DIR__ . '/includes/footer.php';
    exit;
}

$db = getDB();
$padded = str_pad($epNum, 3, '0', STR_PAD_LEFT);

// Prev / Next
$prev = $db->prepare("SELECT episode_number FROM episodes WHERE episode_number<? ORDER BY episode_number DESC LIMIT 1");
$prev->execute([$epNum]); $prevEp = $prev->fetch()?:null;
$next = $db->prepare("SELECT episode_number FROM episodes WHERE episode_number>? ORDER BY episode_number ASC LIMIT 1");
$next->execute([$epNum]); $nextEp = $next->fetch()?:null;

// Parse title
$rawTitle  = $ep['title'] ?? '';
$descPart  = '';
if (preg_match('/^Episode\s*#\d+\s*-\s*(.+)$/i', $rawTitle, $m)) $descPart = trim($m[1]);
$pageTitle = $descPart ? "Episode #$padded — $descPart" : "Episode #$padded";

$src = thumbSrc($ep);

// ── N/A helper — single source of truth for "is this field empty" ──
function naVal($v, $suffix = ''): string {
    if ($v === null || $v === '' || $v === 0 || $v === '0000-00-00') {
        return '<span class="na">N/A</span>';
    }
    return h((string)$v) . $suffix;
}
function hasVal($v): bool {
    return !($v === null || $v === '' || $v === 0 || $v === '0000-00-00');
}

$hasLocation = hasVal($ep['location_name'] ?? null);
$hasMission  = hasVal($ep['main_mission'] ?? null);
$hasRuntime  = hasVal($ep['runtime_minutes'] ?? null);
$hasDate     = hasVal($ep['air_date'] ?? null);
$hasGuests   = !empty($ep['guests']);
$hasSynopsis = hasVal($ep['synopsis'] ?? null);
$hasTags     = !empty($ep['tags']);
$hasNotes    = hasVal($ep['special_notes'] ?? null);

// When there's no real synopsis (common for episodes synced before this
// feature existed), synthesize one from whatever else we have rather than
// showing a bare N/A. $ep already carries guests/location/teams/results
// from getEpisode()'s joins, so no extra queries needed here.
//
// IMPORTANT: this page already shows Main Mission, Teams, Result and
// Location as their own labeled rows, so we exclude them from the
// generated summary — otherwise the "Description" box just restates the
// fields directly above it word-for-word (this is exactly why EP807
// showed the mission text twice). That leaves the guest list as the only
// thing the summary can add here; if there are no guests either, the
// summary is null and the box shows N/A.
$generatedSummary = $hasSynopsis
    ? null
    : generateEpisodeSummary($ep, ['main_mission','teams','results','location_name']);

$hasTheme = hasVal($ep['theme_name'] ?? null);
$related  = getRelatedEpisodes($ep, 6);

// SEO — derived only from real episode fields, never invented.
$pageDescription = $hasSynopsis
    ? mb_strimwidth((string)$ep['synopsis'], 0, 160, '…')
    : ($generatedSummary ? mb_strimwidth($generatedSummary, 0, 160, '…')
        : "Episode #$padded of Running Man" . ($hasDate ? ' — aired ' . $ep['air_date'] : '') . '.');
$ogImage = $src ?: null;
$canonicalPath = bp() . '/episode.php?ep=' . $epNum;

include __DIR__ . '/includes/header.php';
?>
<div style="padding-top:2rem">

<!-- Breadcrumb -->
<nav class="breadcrumb">
  <a href="<?= bp() ?>/index.php">Home</a><span class="sep">/</span>
  <a href="<?= bp() ?>/search.php">Episodes</a><span class="sep">/</span>
  <?php if ($hasDate): ?>
  <a href="<?= bp() ?>/search.php?year=<?= substr($ep['air_date'],0,4) ?>"><?= substr($ep['air_date'],0,4) ?></a>
  <span class="sep">/</span>
  <?php endif; ?>
  <span>Episode #<?= $padded ?></span>
</nav>

<!-- Top nav -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.4rem">
  <?php if ($prevEp): ?>
  <a href="<?= episodeUrl($prevEp['episode_number']) ?>" class="ep-nav-btn">
    <span class="ep-nav-arr">«</span> Ep #<?= str_pad($prevEp['episode_number'],3,'0',STR_PAD_LEFT) ?>
  </a>
  <?php else: ?><span></span><?php endif; ?>
  <?php if ($nextEp): ?>
  <a href="<?= episodeUrl($nextEp['episode_number']) ?>" class="ep-nav-btn next">
    Ep #<?= str_pad($nextEp['episode_number'],3,'0',STR_PAD_LEFT) ?> <span class="ep-nav-arr">»</span>
  </a>
  <?php else: ?><span></span><?php endif; ?>
</div>

<!-- Main title -->
<h1 class="ep-main-title">
  Episode #<?= $padded ?>
  <?php if ($descPart): ?> - <span><?= h($descPart) ?></span><?php else: ?> - <span class="na">N/A</span><?php endif; ?>
  <?php if ($ep['is_special']): ?>
    <span class="badge b-yel" style="font-size:.4em;vertical-align:middle;margin-left:.5rem">⭐ Special</span>
  <?php endif; ?>
</h1>

<!-- Two-column layout -->
<div class="ep-detail">

  <!-- LEFT -->
  <div>
    <div class="ep-detail-thumb">
      <?php if ($src): ?>
        <img src="<?= h($src) ?>" alt="Episode #<?= $padded ?>">
      <?php else: ?>
        <div class="ep-detail-empty">
          <span style="font-size:3rem;color:var(--yel);font-style:italic;font-weight:900">R</span>
          <span style="font-size:.72rem;color:var(--t4)">No thumbnail — N/A</span>
        </div>
      <?php endif; ?>
    </div>

    <!-- Metadata rows — ALWAYS rendered, N/A when empty -->
    <div class="ep-meta-rows" style="margin-top:1.1rem">

      <div class="ep-meta-row">
        <span class="ep-meta-ic">🕐</span><span class="ep-meta-l">Broadcast Date:</span>
        <span class="ep-meta-v"><?= naVal($ep['air_date'] ?? null) ?></span>
      </div>

      <div class="ep-meta-row">
        <span class="ep-meta-ic">📍</span><span class="ep-meta-l">Location:</span>
        <span class="ep-meta-v">
          <?php if ($hasLocation): ?>
            <?= h($ep['location_name']) ?><?= !empty($ep['location_country']) ? ' — '.h($ep['location_country']) : '' ?>
            <?php if ($ep['is_overseas'] ?? false): ?><span class="badge b-blue" style="margin-left:.3rem">✈ Overseas</span><?php endif; ?>
          <?php else: ?><span class="na">N/A</span><?php endif; ?>
        </span>
      </div>

      <div class="ep-meta-row">
        <span class="ep-meta-ic">🎭</span><span class="ep-meta-l">Theme:</span>
        <span class="ep-meta-v">
          <?php if ($hasTheme): ?>
            <a href="<?= h(bp()) ?>/search.php?theme_id=<?= (int)$ep['theme_id'] ?>" class="ep-meta-link"><?= h($ep['theme_name']) ?></a>
          <?php else: ?><span class="na">N/A</span><?php endif; ?>
        </span>
      </div>

      <div class="ep-meta-row">
        <span class="ep-meta-ic">🎯</span><span class="ep-meta-l">Main Mission:</span>
        <span class="ep-meta-v"><?= naVal($ep['main_mission'] ?? null) ?></span>
      </div>

      <div class="ep-meta-row">
        <span class="ep-meta-ic">👥</span><span class="ep-meta-l">Teams:</span>
        <span class="ep-meta-v"><?= naVal($ep['teams'] ?? null) ?></span>
      </div>

      <div class="ep-meta-row">
        <span class="ep-meta-ic">🏆</span><span class="ep-meta-l">Result:</span>
        <span class="ep-meta-v"><?= naVal($ep['results'] ?? null) ?></span>
      </div>

      <div class="ep-meta-row">
        <span class="ep-meta-ic">⏱</span><span class="ep-meta-l">Runtime:</span>
        <span class="ep-meta-v"><?= $hasRuntime ? h($ep['runtime_minutes']).' min' : '<span class="na">N/A</span>' ?></span>
      </div>

    </div>

    <!-- Tags — always shown -->
    <div style="margin-top:.85rem">
      <div class="ep-meta-l" style="margin-bottom:.45rem">🏷 Tags: <?= $hasTags ? count($ep['tags']) : 0 ?></div>
      <?php if ($hasTags): ?>
        <?php foreach ($ep['tags'] as $tag): ?>
        <a href="<?= bp() ?>/search.php?q=<?= urlencode($tag) ?>" class="ep-tag"><?= h($tag) ?></a>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="na">N/A</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT -->
  <div>

    <!-- Appearances — always shown -->
    <div class="ep-section">
      <div class="ep-sec-title">👥 Appearances (<?= $hasGuests ? count($ep['guests']) : 0 ?>):</div>
      <?php if ($hasGuests): ?>
      <ul class="ep-guest-list">
        <?php foreach ($ep['guests'] as $g): ?>
        <li class="ep-guest-item">
          <div class="ep-guest-av"><?= strtoupper(mb_substr($g['name_romanized'],0,1)) ?></div>
          <div>
            <span class="ep-guest-name"><?= h($g['name_romanized']) ?></span>
            <?php if (!empty($g['name_korean'])): ?><span class="ep-guest-kr"> (<?= h($g['name_korean']) ?>)</span><?php endif; ?>
            <?php if (!empty($g['profession'])): ?><span class="ep-guest-prof"> — <?= h($g['profession']) ?></span><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
        <p class="ep-desc"><span class="na">N/A</span></p>
      <?php endif; ?>
    </div>

    <!-- Description — always shown -->
    <div class="ep-section">
      <div class="ep-sec-title"><?= $hasSynopsis ? 'ℹ Description:' : '📝 Summary (auto-generated):' ?></div>
      <p class="ep-desc">
        <?php if ($hasSynopsis): ?>
          <?= nl2br(h($ep['synopsis'])) ?>
        <?php elseif ($generatedSummary): ?>
          <?= nl2br(h($generatedSummary)) ?>
          <br><span style="font-size:.72rem;color:var(--t4);font-style:italic">Generated from available mission/guest/location data — not an official synopsis.</span>
        <?php else: ?>
          <span class="na">N/A</span>
        <?php endif; ?>
      </p>
    </div>

    <!-- Special notes — always shown -->
    <div class="ep-section">
      <div class="ep-sec-title">📌 Special Notes:</div>
      <p class="ep-desc"><?= $hasNotes ? nl2br(h($ep['special_notes'])) : '<span class="na">N/A</span>' ?></p>
    </div>

    <?php
    $completeness = (int)$hasDate + (int)$hasLocation + (int)$hasMission + (int)$hasGuests + (int)$hasSynopsis + (int)!empty($generatedSummary);
    if ($completeness <= 1): ?>
    <div class="alert alert-warn" style="margin-top:.5rem">
      ⚠ Most data unavailable for this episode. <a href="<?= bp() ?>/admin/auto_sync.php" style="color:inherit;font-weight:700">Run Auto Sync</a> to fetch from Wikipedia/myrunningman.com.
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- Bottom nav -->
<div class="ep-nav">
  <?php if ($prevEp): ?>
  <a href="<?= episodeUrl($prevEp['episode_number']) ?>" class="ep-nav-btn">
    <span class="ep-nav-arr">«</span> Ep #<?= str_pad($prevEp['episode_number'],3,'0',STR_PAD_LEFT) ?>
  </a>
  <?php else: ?><span></span><?php endif; ?>
  <a href="<?= bp() ?>/search.php" class="btn btn-ghost btn-sm">All Episodes</a>
  <?php if ($nextEp): ?>
  <a href="<?= episodeUrl($nextEp['episode_number']) ?>" class="ep-nav-btn next">
    Ep #<?= str_pad($nextEp['episode_number'],3,'0',STR_PAD_LEFT) ?> <span class="ep-nav-arr">»</span>
  </a>
  <?php else: ?><span></span><?php endif; ?>
</div>

<?php if ($related): ?>
<section aria-label="Related episodes" style="margin-top:2.75rem">
  <h2 class="sec-h">Related Episodes</h2>
  <div class="related-grid">
    <?php foreach ($related as $r): ?>
      <?= renderEpisodeCard($r, ['meta' => false]) ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

</div>
<style>.na{color:var(--t4);font-style:italic;font-weight:400}</style>
<script>if (window.rmRecordVisit) window.rmRecordVisit(<?= (int)$epNum ?>);</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
