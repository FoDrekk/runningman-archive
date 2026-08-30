<?php
$pageTitle = $pageTitle ?? 'Running Man Archive';
$bp  = defined('BASE_PATH') ? BASE_PATH : '';
$cur = basename($_SERVER['PHP_SELF'] ?? '');
$cssV = @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1200">
<title><?= h($pageTitle) ?> | Running Man Archive</title>
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
        <a href="<?= $bp ?>/index.php"          <?= $cur==='index.php'    ?'class="active"':'' ?>>Home</a>
        <a href="<?= $bp ?>/search.php"         <?= $cur==='search.php'   ?'class="active"':'' ?>>Episodes</a>
        <a href="<?= $bp ?>/pages/specials.php" <?= $cur==='specials.php' ?'class="active"':'' ?>>Specials</a>
        <a href="<?= $bp ?>/pages/themes.php"   <?= $cur==='themes.php'   ?'class="active"':'' ?>>Themes</a>
        <a href="<?= $bp ?>/pages/years.php"    <?= $cur==='years.php'    ?'class="active"':'' ?>>By Year</a>
        <a href="<?= $bp ?>/admin/" class="nav-cta">⚡ Admin</a>
      </nav>
    </div>
  </div>
</header>
<main class="site-main">
<div class="container">
