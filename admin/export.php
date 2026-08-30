<?php
// AJAX/download handler must run before layout.php — see auto_sync.php for why.
$adminTitle = 'Export';
$adminPage  = 'export';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
adminCheck();

$db = getDB();

// Handle export
if (isset($_GET['type'])) {
    $type = $_GET['type'];
    if ($type === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="runningman-episodes-'.date('Y-m-d').'.json"');
        $eps = $db->query("
            SELECT e.episode_number, e.title, e.air_date, e.synopsis, e.main_mission,
                   e.is_special, e.special_type, y.year_label AS year,
                   l.name AS location, l.country, l.is_overseas,
                   t.name AS theme
            FROM episodes e
            LEFT JOIN years y     ON y.year_id     = e.year_id
            LEFT JOIN locations l ON l.location_id  = e.location_id
            LEFT JOIN themes t    ON t.theme_id     = e.theme_id
            ORDER BY e.episode_number ASC
        ")->fetchAll();
        echo json_encode(['exported' => date('c'), 'total' => count($eps), 'episodes' => $eps], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($type === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="runningman-episodes-'.date('Y-m-d').'.csv"');
        $eps = $db->query("
            SELECT e.episode_number, e.title, e.air_date, e.synopsis, e.is_special, e.special_type, y.year_label, l.name AS location, l.country
            FROM episodes e
            LEFT JOIN years y ON y.year_id=e.year_id LEFT JOIN locations l ON l.location_id=e.location_id
            ORDER BY e.episode_number ASC
        ")->fetchAll();
        $out = fopen('php://output', 'w');
        fputcsv($out, ['episode_number','title','air_date','synopsis','is_special','special_type','year','location','country']);
        foreach ($eps as $r) fputcsv($out, $r);
        fclose($out);
        exit;
    }
}

require_once __DIR__ . '/layout.php';

$total = (int)$db->query("SELECT COUNT(*) FROM episodes")->fetchColumn();
?>
<div class="at"><h1>📦 Export Data</h1><p>Download episode data in different formats.</p></div>

<div class="ap">
  <div class="sh">Export Options</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div style="background:#141c2c;border:1px solid rgba(41,171,226,.12);border-radius:10px;padding:1.4rem">
      <div style="font-weight:700;font-size:.95rem;color:#eef2f8;margin-bottom:.4rem">📄 JSON</div>
      <div style="font-size:.8rem;color:rgba(255,255,255,.4);margin-bottom:1rem">Full episode data including synopsis, location, theme. <?= number_format($total) ?> episodes.</div>
      <a href="?type=json" class="btn btn-sm">Download JSON</a>
    </div>
    <div style="background:#141c2c;border:1px solid rgba(41,171,226,.12);border-radius:10px;padding:1.4rem">
      <div style="font-weight:700;font-size:.95rem;color:#eef2f8;margin-bottom:.4rem">📊 CSV</div>
      <div style="font-size:.8rem;color:rgba(255,255,255,.4);margin-bottom:1rem">Spreadsheet-compatible format. <?= number_format($total) ?> episodes.</div>
      <a href="?type=csv" class="btn btn-sm">Download CSV</a>
    </div>
  </div>
</div>
</main></div>
</body></html>
