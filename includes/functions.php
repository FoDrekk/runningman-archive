<?php
// ============================================================
// Running Man Archive — Core Functions
// ============================================================
require_once __DIR__ . '/../config/db.php';

// ── URL helpers ──────────────────────────────────────────────
function bp(): string { return defined('BASE_PATH') ? BASE_PATH : ''; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_HTML5, 'UTF-8'); }
function episodeUrl(int $n): string { return bp().'/episode.php?ep='.$n; }
function themeUrl(int $id): string  { return bp().'/pages/themes.php?id='.$id; }

// ── Episode list with filters + pagination ───────────────────
function getEpisodeList(int $page = 1, int $perPage = 24, array $f = []): array {
    $db = getDB();
    $where = ['1=1']; $params = [];

    if (!empty($f['search'])) {
        $where[] = '(e.title LIKE ? OR e.synopsis LIKE ?)';
        $s = '%'.trim($f['search']).'%';
        $params[] = $s; $params[] = $s;
    }
    if (!empty($f['year']))       { $where[] = 'y.year_label=?';   $params[] = $f['year']; }
    if (!empty($f['theme_id']))   { $where[] = 'e.theme_id=?';     $params[] = $f['theme_id']; }
    if (isset($f['is_special']))  { $where[] = 'e.is_special=?';   $params[] = (int)$f['is_special']; }
    if (!empty($f['special_type'])){ $where[] = 'e.special_type=?';$params[] = $f['special_type']; }

    $sql = "FROM episodes e
            LEFT JOIN years y      ON y.year_id      = e.year_id
            LEFT JOIN themes t     ON t.theme_id      = e.theme_id
            LEFT JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id
            WHERE " . implode(' AND ', $where);

    // BUGFIX: this used to also run a separate "$count = ..." ternary
    // expression here that called prepare()->execute() TWICE more (3
    // total COUNT(*) queries per page load) and assigned the result to
    // a variable that was never read anywhere — execute() returns a
    // bool (success/fail), not the row count, so that variable was
    // never even capable of holding a real count. Pure dead weight;
    // removed. $cStmt below is the one real COUNT(*) call needed.
    $cStmt = $db->prepare("SELECT COUNT(*) $sql"); $cStmt->execute($params);
    $total = (int)$cStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;

    // BUGFIX (found in review): config/db.php sets
    // PDO::ATTR_EMULATE_PREPARES => false (native prepares — the correct,
    // safe setting). Under native prepares, execute([...]) binds EVERY
    // value as a string by default, so passing $perPage/$offset through
    // the $params array produced "LIMIT '24' OFFSET '0'", which MySQL
    // rejects with a syntax error — meaning the episode list / search /
    // specials pages would fatal on the SQL query. The WHERE-clause
    // $params are all fine as strings (MySQL coerces them in comparisons),
    // but LIMIT/OFFSET must be bound explicitly as integers. So bind the
    // WHERE params positionally first, then the two integer params with an
    // explicit PARAM_INT type.
    $rows = $db->prepare("
        SELECT e.episode_number, e.title, e.air_date, e.synopsis,
               e.is_special, e.special_type, e.verification_required,
               y.year_label, t.name AS theme_name,
               th.local_path AS thumbnail_path, th.verified AS thumb_verified
        $sql ORDER BY e.episode_number DESC LIMIT ? OFFSET ?
    ");
    $bindPos = 1;
    foreach ($params as $p) { $rows->bindValue($bindPos++, $p); }
    $rows->bindValue($bindPos++, (int)$perPage, PDO::PARAM_INT);
    $rows->bindValue($bindPos++, (int)$offset,  PDO::PARAM_INT);
    $rows->execute();

    return [
        'episodes'   => $rows->fetchAll(),
        'pagination' => [
            'total'        => $total,
            'total_pages'  => $totalPages,
            'current_page' => $page,
            'has_prev'     => $page > 1,
            'has_next'     => $page < $totalPages,
        ],
    ];
}

// ── Single episode with all relations ───────────────────────
function getEpisode(int $n): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT e.*,
               y.year_label,
               t.name AS theme_name,
               l.name AS location_name, l.country AS location_country, l.is_overseas,
               th.local_path AS thumbnail_path, th.thumbnail_url, th.verified AS thumb_verified
        FROM episodes e
        LEFT JOIN years y      ON y.year_id      = e.year_id
        LEFT JOIN themes t     ON t.theme_id      = e.theme_id
        LEFT JOIN locations l  ON l.location_id   = e.location_id
        LEFT JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id
        WHERE e.episode_number = ?
    ");
    $stmt->execute([$n]);
    $ep = $stmt->fetch();
    if (!$ep) return null;

    // Guests
    $gStmt = $db->prepare("
        SELECT g.guest_id, g.name_romanized, g.name_korean, g.profession
        FROM guests g
        JOIN episode_guests eg ON eg.guest_id = g.guest_id
        JOIN episodes e ON e.episode_id = eg.episode_id
        WHERE e.episode_number = ?
        ORDER BY g.name_romanized
    ");
    $gStmt->execute([$n]);
    $ep['guests'] = $gStmt->fetchAll();

    // Tags
    $tStmt = $db->prepare("
        SELECT tg.name FROM tags tg
        JOIN episode_tags et ON et.tag_id = tg.tag_id
        JOIN episodes e ON e.episode_id = et.episode_id
        WHERE e.episode_number = ?
        ORDER BY tg.name
    ");
    $tStmt->execute([$n]);
    $ep['tags'] = $tStmt->fetchAll(PDO::FETCH_COLUMN);

    return $ep;
}

// ── Year stats ───────────────────────────────────────────────
function getYearStats(): array {
    return getDB()->query("
        SELECT y.year_label,
               COUNT(e.episode_id)    AS total_episodes,
               SUM(e.is_special)      AS specials,
               MIN(e.episode_number)  AS first_ep,
               MAX(e.episode_number)  AS last_ep
        FROM years y
        LEFT JOIN episodes e ON e.year_id = y.year_id
        GROUP BY y.year_id, y.year_label
        ORDER BY y.year_label ASC
    ")->fetchAll();
}

// ── All themes ───────────────────────────────────────────────
function getThemes(): array {
    return getDB()->query("
        SELECT t.*, COUNT(e.episode_id) AS episode_count
        FROM themes t LEFT JOIN episodes e ON e.theme_id = t.theme_id
        GROUP BY t.theme_id HAVING episode_count > 0
        ORDER BY episode_count DESC
    ")->fetchAll();
}

// ── Completeness (cached 5 min) ──────────────────────────────
function getStats(): array {
    $cache = sys_get_temp_dir().'/rm_stats.json';
    if (file_exists($cache) && (time()-filemtime($cache)) < 300) {
        $d = json_decode(file_get_contents($cache), true);
        if ($d) return $d;
    }
    $db = getDB();
    $row = $db->query("
        SELECT COUNT(*) AS total,
               COALESCE(MIN(episode_number),0) AS first_ep,
               COALESCE(MAX(episode_number),0) AS last_ep,
               SUM(synopsis IS NULL OR synopsis='') AS no_synopsis,
               SUM(verification_required=1)   AS unverified
        FROM episodes
    ")->fetch();
    $total    = (int)$row['total'];
    $maxEp    = (int)$row['last_ep'];
    $minEp    = (int)$row['first_ep'];
    $expected = $maxEp > 0 ? $maxEp - $minEp + 1 : 0;

    // Missing count via SQL (no PHP array needed)
    $missing = $expected - $total;

    $thumbMissing = (int)$db->query("
        SELECT COUNT(*) FROM episodes e
        WHERE NOT EXISTS (
            SELECT 1 FROM thumbnails t
            WHERE t.thumbnail_id=e.thumbnail_id AND t.verified=1
        )
    ")->fetchColumn();

    $result = [
        'total'         => $total,
        'first_ep'      => $minEp,
        'last_ep'       => $maxEp,
        'expected'      => $expected,
        'missing'       => max(0, $missing),
        'no_synopsis'   => (int)$row['no_synopsis'],
        'unverified'    => (int)$row['unverified'],
        'thumb_missing' => $thumbMissing,
        'pct'           => $expected > 0 ? round(($total/$expected)*100,1) : 0,
    ];
    @file_put_contents($cache, json_encode($result));
    return $result;
}

// ── Thumbnail check ──────────────────────────────────────────
function thumbSrc(array $ep): string {
    $path = $ep['thumbnail_path'] ?? '';
    if ($path && file_exists(($_SERVER['DOCUMENT_ROOT']??'').$path)) return $path;
    return '';
}

// ── Defensive migration check — teams/results columns ─────────
// These only exist after running database/add_teams_results.sql.
// Cached per-request so this is one cheap query, not one per episode
// during a bulk sync of hundreds of episodes.
function rmTeamsResultsColumnsExist(PDO $db): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $db->query("SELECT teams, results FROM episodes LIMIT 1");
        $exists = true;
    } catch (Exception $e) {
        $exists = false;
    }
    return $exists;
}

// ── Auto-generated summary fallback ───────────────────────────
// When an episode has no real synopsis, build a readable sentence
// from whatever data IS available (mission, teams, guests, location)
// instead of showing a bare "N/A". Clearly a synthesized summary,
// not pretending to be an official synopsis — episode.php labels it
// differently so the distinction stays visible to the reader.
// Builds a human-readable one-paragraph summary from the structured
// fields. $excludeShown lists fields that are ALREADY displayed in their
// own labeled rows by the caller (e.g. episode.php shows Main Mission,
// Teams, Result, Location separately) — including them here just produces
// a paragraph that restates what's directly above it. Pass those field
// names to omit them and avoid the redundancy. With everything excluded
// and only guests left, the summary becomes a short "guests appearing"
// line; if even that's empty, returns null so the caller can fall back
// to N/A rather than show an empty box.
function generateEpisodeSummary(array $ep, array $excludeShown = []): ?string {
    $skip = array_flip($excludeShown);
    $parts = [];

    if (!isset($skip['main_mission']) && !empty($ep['main_mission'])) {
        $parts[] = 'The mission: ' . rtrim($ep['main_mission'], '. ') . '.';
    }
    if (!isset($skip['teams']) && !empty($ep['teams'])) {
        $parts[] = 'Teams: ' . rtrim($ep['teams'], '. ') . '.';
    }
    if (!isset($skip['guests']) && !empty($ep['guests']) && is_array($ep['guests'])) {
        $names = array_filter(array_map(
            fn($g) => is_array($g) ? ($g['name_romanized'] ?? '') : (string)$g,
            $ep['guests']
        ));
        $names = array_values($names);
        if ($names) {
            $list = count($names) > 1
                ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names)
                : $names[0];
            $parts[] = (count($names) > 1 ? 'Guests' : 'Guest') . ' appearing: ' . $list . '.';
        }
    }
    if (!isset($skip['location_name']) && !empty($ep['location_name'])) {
        $loc = $ep['location_name'] . (!empty($ep['location_country']) ? ', ' . $ep['location_country'] : '');
        $parts[] = 'Filmed at ' . $loc . '.';
    }
    if (!isset($skip['results']) && !empty($ep['results'])) {
        $parts[] = 'Result: ' . rtrim($ep['results'], '. ') . '.';
    }

    return $parts ? implode(' ', $parts) : null;
}
