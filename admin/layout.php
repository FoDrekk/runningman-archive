<?php
// Shared admin layout — include at start of each admin page
// Set: $adminTitle, $adminPage before including
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
adminCheck();

$bp   = bp();
$cssV = @filemtime(__DIR__.'/../assets/css/style.css') ?: 1;
$pg   = $adminPage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=1200">
<title><?= htmlspecialchars($adminTitle??'Admin') ?> — RM Archive</title>
<link rel="stylesheet" href="<?= $bp ?>/assets/css/style.css?v=<?= $cssV ?>">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#080c12;color:#eef2f8;min-width:1200px;-webkit-font-smoothing:antialiased}
.aw{display:grid;grid-template-columns:200px 1fr;min-height:100vh}
/* Sidebar */
.sb{background:#06090f;border-right:1px solid rgba(41,171,226,.1);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0}
.sb-stripe{height:3px;background:linear-gradient(90deg,#29ABE2,#FFD700,#29ABE2);background-size:200%;animation:st 3s linear infinite;flex-shrink:0}
@keyframes st{to{background-position:200% 0}}
.sb-logo{padding:1.1rem 1rem .9rem;border-bottom:1px solid rgba(41,171,226,.1);display:flex;align-items:center;gap:.55rem}
.sb-r{width:28px;height:28px;border-radius:50%;background:#29ABE2;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.75rem;color:#FFD700;font-style:italic;flex-shrink:0}
.sb-name{font-weight:800;font-size:.85rem;color:#eef2f8;line-height:1.2}
.sb-sub{font-size:.58rem;color:rgba(41,171,226,.6);letter-spacing:.06em;text-transform:uppercase}
.sb-sec{font-size:.58rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:rgba(41,171,226,.32);padding:.8rem 1rem .25rem}
.sb a{display:flex;align-items:center;gap:.55rem;padding:.46rem 1rem;color:rgba(255,255,255,.42);font-size:.79rem;text-decoration:none;transition:.1s}
.sb a:hover{color:#eef2f8;background:rgba(41,171,226,.07);text-decoration:none}
.sb a.on{color:#FFD700;background:rgba(41,171,226,.1);font-weight:600}
.sb-bot{margin-top:auto;padding:.6rem 0;border-top:1px solid rgba(41,171,226,.07)}
/* Main */
.am{background:#080c12;padding:2.2rem 2.5rem 4rem;overflow-y:auto}
/* Panel */
.ap{background:#0e1420;border:1px solid rgba(41,171,226,.1);border-radius:13px;padding:1.6rem;margin-bottom:1.4rem}
/* Title */
.at h1{font-size:1.35rem;font-weight:900;letter-spacing:-.03em;margin-bottom:.2rem}
.at p{font-size:.78rem;color:rgba(255,255,255,.3)}
.at{margin-bottom:1.75rem}
/* Form */
.fld{margin-bottom:.9rem}
.fld label{display:block;font-size:.64rem;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:rgba(255,255,255,.32);margin-bottom:.28rem}
.fld input,.fld select,.fld textarea{width:100%;padding:.52rem .8rem;border:1px solid rgba(41,171,226,.14);border-radius:8px;font-size:.875rem;font-family:inherit;background:#141c2c;color:#eef2f8;outline:none;transition:.15s}
.fld input:focus,.fld select:focus,.fld textarea:focus{border-color:#29ABE2;box-shadow:0 0 0 3px rgba(41,171,226,.1)}
.fld textarea{resize:vertical;min-height:78px;line-height:1.6}
.fld input[readonly]{opacity:.4;cursor:not-allowed}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem}
/* Section heading */
.sh{font-size:.64rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(41,171,226,.45);margin-bottom:.8rem;padding-bottom:.45rem;border-bottom:1px solid rgba(41,171,226,.09)}
/* Alerts */
.aok{background:rgba(34,197,94,.09);border:1px solid rgba(34,197,94,.2);border-radius:8px;padding:.72rem .95rem;color:#86efac;font-size:.85rem;margin-bottom:1.1rem}
.aerr{background:rgba(239,68,68,.09);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:.72rem .95rem;color:#fca5a5;font-size:.85rem;margin-bottom:1.1rem}
/* Table */
.atable{width:100%;border-collapse:collapse;font-size:.83rem}
.atable th{background:#141c2c;font-size:.61rem;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:rgba(41,171,226,.45);padding:.62rem .95rem;text-align:left;border-bottom:1px solid rgba(41,171,226,.1)}
.atable td{padding:.62rem .95rem;border-bottom:1px solid rgba(41,171,226,.05);vertical-align:middle;color:rgba(255,255,255,.62)}
.atable tbody tr:hover td{background:rgba(41,171,226,.03)}
.atable tbody tr:last-child td{border-bottom:none}
/* Spinner */
.spin{display:inline-block;width:13px;height:13px;border:2px solid rgba(255,255,255,.18);border-top-color:#29ABE2;border-radius:50%;animation:sp .6s linear infinite;vertical-align:middle}
@keyframes sp{to{transform:rotate(360deg)}}
/* Action row */
.ar{display:flex;gap:.6rem;margin-top:1.4rem;padding-top:1.1rem;border-top:1px solid rgba(41,171,226,.08)}
</style>
<?php if (!empty($adminExtraCSS)) echo '<style>'.$adminExtraCSS.'</style>'; ?>
</head>
<body>
<div class="aw">
<aside class="sb">
  <div class="sb-stripe"></div>
  <div class="sb-logo">
    <div class="sb-r">R</div>
    <div><div class="sb-name">Running Man</div><div class="sb-sub">Admin</div></div>
  </div>
  <div class="sb-sec">Automation</div>
  <a href="<?= $bp ?>/admin/"                     <?= $pg==='dash'   ?'class="on"':'' ?>><span>🏠</span> Dashboard</a>
  <a href="<?= $bp ?>/admin/fetch.php"            <?= $pg==='fetch'  ?'class="on"':'' ?>><span>⚡</span> Fetch Latest</a>
  <a href="<?= $bp ?>/admin/auto_sync.php"             <?= $pg==='auto_sync'   ?'class="on"':'' ?>><span>🔄</span> Auto Sync</a>
  <a href="<?= $bp ?>/admin/scraper.php"          <?= $pg==='scraper' ?'class="on"':'' ?>><span>🛰️</span> Scraper Centre</a>
  <a href="<?= $bp ?>/admin/cron.php"             <?= $pg==='cron'   ?'class="on"':'' ?>><span>⏱️</span> Weekly Update</a>
  <div class="sb-sec">Data</div>
  <a href="<?= $bp ?>/admin/edit_episode.php"     <?= $pg==='editep' ?'class="on"':'' ?>><span>✏️</span> Edit Episode</a>
  <a href="<?= $bp ?>/admin/thumbnails.php"       <?= $pg==='thumbs' ?'class="on"':'' ?>><span>🖼️</span> Thumbnails</a>
  <a href="<?= $bp ?>/admin/import.php"           <?= $pg==='import' ?'class="on"':'' ?>><span>📥</span> Import</a>
  <a href="<?= $bp ?>/admin/export.php"           <?= $pg==='export' ?'class="on"':'' ?>><span>📦</span> Export</a>
  <div class="sb-sec">System</div>
  <a href="<?= $bp ?>/admin/health.php"           <?= $pg==='health' ?'class="on"':'' ?>><span>🩺</span> System Health</a>
  <a href="<?= $bp ?>/admin/archive_health.php"   <?= $pg==='archive_health' ?'class="on"':'' ?>><span>📋</span> Archive Health</a>
  <a href="<?= $bp ?>/admin/diagnostics.php"       <?= $pg==='diagnostics' ?'class="on"':'' ?>><span>🛠️</span> Diagnostics</a>
  <div class="sb-sec">Site</div>
  <a href="<?= $bp ?>/index.php" target="_blank"><span>🌐</span> View Site</a>
  <div class="sb-bot">
    <a href="<?= $bp ?>/index.php"><span>←</span> Back to Site</a>
  </div>
</aside>
<main class="am">
