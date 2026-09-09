<?php
// ============================================================
// Public data access — discovery/homepage queries (PR #5).
//
// Everything here reads from the SAME tables PR4's research engine
// writes to (episodes/guests/thumbnails/themes/years) through ordinary
// parameterized queries — nothing here scrapes, nothing here bypasses
// PR4's validation, and nothing here invents data that isn't in the
// database. This file is the one place these discovery queries live,
// so index.php/search.php/pages/*.php call a function instead of each
// repeating its own SQL.
// ============================================================
require_once __DIR__ . '/functions.php';

/**
 * The latest AIRED episode — never an upcoming/announced one.
 *
 * Deliberately NOT MAX(episode_number): a source can add a stub row for
 * an announced-but-unaired episode before it airs. The archive's
 * air_date column is the canonical, PR4-validated field (written only
 * through the research engine's safe-write pipeline), so "latest
 * aired" is defined as the highest air_date that is not in the future.
 * This needs no live network call — it trusts the data PR4 already
 * verified and stored.
 */
function getLatestAiredEpisode(): ?array {
    $db  = getDB();
    $row = $db->query("
        SELECT episode_number FROM episodes
         WHERE air_date IS NOT NULL AND air_date <= CURDATE()
         ORDER BY air_date DESC, episode_number DESC LIMIT 1
    ")->fetch();
    if (!$row) {
        // No air_date data at all (archive never synced) — degrade to the
        // highest known row rather than showing nothing, but this is a
        // visibly incomplete state, not a claim about what has aired.
        $row = $db->query("SELECT episode_number FROM episodes ORDER BY episode_number DESC LIMIT 1")->fetch();
    }
    return $row ? getEpisode((int)$row['episode_number']) : null;
}

/**
 * Recent AIRED episodes for the "Latest Episodes" rail, newest first.
 * Same air_date <= today guard as getLatestAiredEpisode().
 */
function getRecentAiredEpisodes(int $limit = 12, ?int $excludeEp = null): array {
    $db = getDB();
    $sql = "SELECT e.episode_number, e.title, e.air_date, e.synopsis, e.is_special,
                   t.name AS theme_name, th.local_path AS thumbnail_path
              FROM episodes e
              LEFT JOIN themes t ON t.theme_id = e.theme_id
              LEFT JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id
             WHERE e.air_date IS NOT NULL AND e.air_date <= CURDATE()"
          . ($excludeEp ? " AND e.episode_number <> ?" : "")
          . " ORDER BY e.air_date DESC, e.episode_number DESC LIMIT ?";
    $stmt = $db->prepare($sql);
    $i = 1;
    if ($excludeEp) $stmt->bindValue($i++, $excludeEp, PDO::PARAM_INT);
    $stmt->bindValue($i, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Featured episodes — a deterministic completeness score, not a fake
 * popularity metric. An episode scores one point each for: a real
 * synopsis, a verified thumbnail, an assigned theme, a filming
 * location, and having 3+ credited guests; specials get a flat bonus.
 * Ties break on episode_number so the same input always produces the
 * same output (a real "featured" ranking would need editorial input
 * this archive doesn't have — this is the honest fallback).
 */
function getFeaturedEpisodes(int $limit = 8): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT e.episode_number, e.title, e.air_date, e.synopsis, e.is_special,
               t.name AS theme_name, th.local_path AS thumbnail_path,
               (
                 (e.synopsis IS NOT NULL AND e.synopsis <> '')
               + (th.verified = 1)
               + (e.theme_id IS NOT NULL)
               + (e.location_id IS NOT NULL)
               + (e.is_special = 1) * 2
               + ((SELECT COUNT(*) FROM episode_guests eg WHERE eg.episode_id = e.episode_id) >= 3)
               ) AS score
          FROM episodes e
          LEFT JOIN themes t ON t.theme_id = e.theme_id
          LEFT JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id
         WHERE e.air_date IS NOT NULL AND e.air_date <= CURDATE()
         ORDER BY score DESC, e.episode_number DESC
         LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** "On This Day" — aired episodes sharing today's calendar month/day, any past year. */
function getOnThisDay(int $limit = 8): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT e.episode_number, e.title, e.air_date, th.local_path AS thumbnail_path
          FROM episodes e
          LEFT JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id
         WHERE e.air_date IS NOT NULL
           AND e.air_date < CURDATE()
           AND MONTH(e.air_date) = MONTH(CURDATE())
           AND DAY(e.air_date) = DAY(CURDATE())
         ORDER BY e.air_date DESC
         LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * One valid episode number for "Surprise Me" — always aired, never
 * upcoming, preferring episodes with real synopsis/thumbnail coverage
 * so a visitor never lands on a near-empty record. Falls back to any
 * aired episode if nothing meets that bar (a young/incomplete archive
 * should still offer something rather than nothing).
 */
function getSurpriseEpisodeNumber(): ?int {
    $db = getDB();
    $n = $db->query("
        SELECT e.episode_number FROM episodes e
          LEFT JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id
         WHERE e.air_date IS NOT NULL AND e.air_date <= CURDATE()
           AND e.synopsis IS NOT NULL AND e.synopsis <> '' AND th.verified = 1
         ORDER BY RAND() LIMIT 1
    ")->fetchColumn();
    if (!$n) {
        $n = $db->query("
            SELECT episode_number FROM episodes
             WHERE air_date IS NOT NULL AND air_date <= CURDATE()
             ORDER BY RAND() LIMIT 1
        ")->fetchColumn();
    }
    return $n ? (int)$n : null;
}

/** Archive-wide totals for the homepage stats strip — real counts only. */
function getArchiveStats(): array {
    $db = getDB();
    return [
        'episodes' => (int)$db->query("SELECT COUNT(*) FROM episodes")->fetchColumn(),
        'guests'   => (int)$db->query("SELECT COUNT(*) FROM guests")->fetchColumn(),
        'themes'   => (int)$db->query("SELECT COUNT(*) FROM themes t WHERE EXISTS (SELECT 1 FROM episodes e WHERE e.theme_id = t.theme_id)")->fetchColumn(),
        'years'    => (int)$db->query("SELECT COUNT(*) FROM years y WHERE EXISTS (SELECT 1 FROM episodes e WHERE e.year_id = y.year_id)")->fetchColumn(),
        'specials' => (int)$db->query("SELECT COUNT(*) FROM episodes WHERE is_special = 1")->fetchColumn(),
    ];
}

/** One representative (most recent, verified-thumbnail-preferred) episode for a theme/year/special-type tile. */
function getRepresentativeThumb(array $where, array $params): ?string {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT th.local_path FROM episodes e
          JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id
         WHERE $where AND th.local_path IS NOT NULL
         ORDER BY th.verified DESC, e.episode_number DESC LIMIT 1
    ");
    $stmt->execute($params);
    $path = $stmt->fetchColumn();
    return $path ? thumbSrc(['thumbnail_path' => $path]) : null;
}

/** Themes with episode counts and a representative thumbnail, for the homepage/theme browsing. */
function getThemesForDiscovery(int $limit = 8): array {
    $themes = array_slice(getThemes(), 0, $limit);
    foreach ($themes as &$t) {
        $t['thumb'] = getRepresentativeThumb('e.theme_id = ?', [$t['theme_id']]);
    }
    return $themes;
}

/**
 * Related episodes for the detail page. Deterministic, DB-relationship
 * based — never invented. Priority: same theme -> shared guest -> same
 * special_type -> same year -> nearest episode number. Each stage tops
 * up the result set without re-querying stages already satisfied.
 */
function getRelatedEpisodes(array $ep, int $limit = 6): array {
    $db = getDB();
    $epNum = (int)$ep['episode_number'];
    $found = [];   // episode_number => row
    $add = function (array $rows) use (&$found, $epNum) {
        foreach ($rows as $r) {
            $n = (int)$r['episode_number'];
            if ($n !== $epNum) $found[$n] = $r;
        }
    };
    $cols = "e.episode_number, e.title, e.air_date, e.is_special, th.local_path AS thumbnail_path";
    $join = "LEFT JOIN thumbnails th ON th.thumbnail_id = e.thumbnail_id";

    if (!empty($ep['theme_id'])) {
        $s = $db->prepare("SELECT $cols FROM episodes e $join WHERE e.theme_id = ? AND e.episode_number <> ? ORDER BY ABS(e.episode_number - ?) LIMIT ?");
        $s->execute([$ep['theme_id'], $epNum, $epNum, $limit]);
        $add($s->fetchAll());
    }

    if (count($found) < $limit && !empty($ep['episode_id'])) {
        $s = $db->prepare("
            SELECT $cols FROM episodes e $join
              JOIN episode_guests eg2 ON eg2.episode_id = e.episode_id
             WHERE e.episode_number <> ? AND eg2.guest_id IN (
                    SELECT guest_id FROM episode_guests WHERE episode_id = ?
                 )
             GROUP BY e.episode_id ORDER BY COUNT(*) DESC, ABS(e.episode_number - ?) LIMIT ?
        ");
        $s->execute([$epNum, $ep['episode_id'], $epNum, $limit - count($found)]);
        $add($s->fetchAll());
    }

    if (count($found) < $limit && !empty($ep['is_special']) && !empty($ep['special_type'])) {
        $s = $db->prepare("SELECT $cols FROM episodes e $join WHERE e.special_type = ? AND e.episode_number <> ? ORDER BY ABS(e.episode_number - ?) LIMIT ?");
        $s->execute([$ep['special_type'], $epNum, $epNum, $limit - count($found)]);
        $add($s->fetchAll());
    }

    if (count($found) < $limit && !empty($ep['year_id'])) {
        $s = $db->prepare("SELECT $cols FROM episodes e $join WHERE e.year_id = ? AND e.episode_number <> ? ORDER BY ABS(e.episode_number - ?) LIMIT ?");
        $s->execute([$ep['year_id'], $epNum, $epNum, $limit - count($found)]);
        $add($s->fetchAll());
    }

    if (count($found) < $limit) {
        $s = $db->prepare("SELECT $cols FROM episodes e $join WHERE e.episode_number <> ? ORDER BY ABS(e.episode_number - ?) LIMIT ?");
        $s->execute([$epNum, $epNum, $limit - count($found)]);
        $add($s->fetchAll());
    }

    $rows = array_values($found);
    usort($rows, fn($a, $b) => abs((int)$a['episode_number'] - $epNum) <=> abs((int)$b['episode_number'] - $epNum));
    return array_slice($rows, 0, $limit);
}
