<?php
// ============================================================
// Public header — shared shell for every public page (PR #5).
//
// Pages set these BEFORE including this file (all optional):
//   $pageTitle        <title> and og:title — defaults to the site name
//   $pageDescription  meta description + og:description
//   $ogImage          absolute or root-relative image URL for og:image
//   $canonicalPath    root-relative path for <link rel=canonical>
//                     (defaults to the current request path)
//   $bodyClass        extra class(es) on <body>, e.g. for page-specific hooks
// ============================================================
$pageTitle       = $pageTitle ?? 'Running Man Archive';
$pageDescription = $pageDescription ?? 'The complete Running Man fan archive — every episode, guest, mission and location, searchable by year, theme and special.';
$bp   = defined('BASE_PATH') ? BASE_PATH : '';
$cur  = basename($_SERVER['PHP_SELF'] ?? '');
$cssV = @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1;
$jsV  = @filemtime(__DIR__ . '/../assets/js/main.js') ?: 1;

// Absolute site URL, best-effort — used only for canonical/OG tags, which
// degrade gracefully (relative) if the host header looks untrustworthy.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = preg_replace('/[^a-zA-Z0-9.:\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$siteUrl = $host !== '' ? "$scheme://$host" : '';
$canonicalPath = $canonicalPath ?? ($_SERVER['REQUEST_URI'] ?? $bp . '/index.php');
$canonicalUrl  = $siteUrl . $canonicalPath;
$ogImageUrl    = !empty($ogImage) ? ($siteUrl . $ogImage) : '';

$navLinks = [
    ['index.php', $bp . '/index.php', 'Home'],
    ['search.php', $bp . '/search.php', 'Episodes'],
    ['specials.php', $bp . '/pages/specials.php', 'Specials'],
    ['themes.php', $bp . '/pages/themes.php', 'Themes'],
    ['years.php', $bp . '/pages/years.php', 'Years'],
];
// Single-user personal archive — the PR4 admin/research centre is reused
// as-is (no new page, no admin UI duplicated here) and linked directly
// from primary nav rather than hidden, per the site's single-user model.
$maintenanceUrl = $bp . '/admin/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?><?= $pageTitle === 'Running Man Archive' ? '' : ' | Running Man Archive' ?></title>
<meta name="description" content="<?= h($pageDescription) ?>">
<link rel="canonical" href="<?= h($canonicalUrl) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Running Man Archive">
<meta property="og:title" content="<?= h($pageTitle) ?>">
<meta property="og:description" content="<?= h($pageDescription) ?>">
<meta property="og:url" content="<?= h($canonicalUrl) ?>">
<?php if ($ogImageUrl): ?><meta property="og:image" content="<?= h($ogImageUrl) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="theme-color" content="#080c12">
<link rel="stylesheet" href="<?= $bp ?>/assets/css/style.css?v=<?= $cssV ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Noto+Sans+KR:wght@400;500;700;900&display=swap" rel="stylesheet">
</head>
<body<?= !empty($bodyClass) ? ' class="' . h($bodyClass) . '"' : '' ?>>
<a href="#main" class="skip-link">Skip to content</a>
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
      <nav class="site-nav" aria-label="Primary">
        <?php foreach ($navLinks as [$file, $url, $label]): ?>
        <a href="<?= $url ?>"<?= $cur === $file ? ' class="active" aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
        <a href="<?= $maintenanceUrl ?>">🔧 Maintenance</a>
      </nav>
      <a href="<?= $bp ?>/search.php" class="nav-search-btn" aria-label="Search episodes">🔍</a>
      <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mobileNav">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<nav id="mobileNav" class="mobile-nav" aria-label="Mobile" hidden>
  <?php foreach ($navLinks as [$file, $url, $label]): ?>
  <a href="<?= $url ?>"<?= $cur === $file ? ' class="active" aria-current="page"' : '' ?>><?= $label ?></a>
  <?php endforeach; ?>
  <a href="<?= $maintenanceUrl ?>">🔧 Maintenance</a>
  <a href="<?= $bp ?>/search.php">🔍 Search</a>
</nav>
<div class="mobile-nav-backdrop" id="mobileNavBackdrop" hidden></div>

<main class="site-main" id="main">
<div class="container">
