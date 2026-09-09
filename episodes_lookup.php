<?php
// ============================================================
// Tiny public JSON endpoint used only by "Continue Browsing"
// (localStorage-driven, no accounts — see assets/js/main.js).
// Returns the same publicly-visible fields episode.php already
// shows; nothing internal, nothing scraper/admin-related.
// ============================================================
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');
@ini_set('display_errors', '0');

$raw = trim((string)($_GET['eps'] ?? ''));
$nums = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn($n) => $n > 0)));
$nums = array_slice($nums, 0, 12);

if (!$nums) { echo json_encode([]); exit; }

try {
    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($nums), '?'));
    $stmt = $db->prepare("
        SELECT e.episode_number, e.title, e.air_date, th.local_path AS thumbnail_path
        FROM episodes e
        LEFT JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id
        WHERE e.episode_number IN ($placeholders)
    ");
    $stmt->execute($nums);
    $byNum = [];
    foreach ($stmt->fetchAll() as $row) $byNum[(int)$row['episode_number']] = $row;

    $out = [];
    foreach ($nums as $n) {
        if (!isset($byNum[$n])) continue;
        $ep = $byNum[$n];
        $title = $ep['title'] ?? '';
        if (preg_match('/^Episode\s*#\d+\s*-\s*(.+)$/i', $title, $m)) $title = $m[1];
        $out[] = [
            'episode_number' => (int)$ep['episode_number'],
            'title'          => $title ?: 'Running Man',
            'air_date'       => $ep['air_date'],
            'thumbnail'      => thumbSrc($ep),
            'url'            => episodeUrl((int)$ep['episode_number']),
        ];
    }
    echo json_encode($out);
} catch (Throwable $e) {
    // Never leak SQL/internal detail to a public endpoint.
    echo json_encode([]);
}
