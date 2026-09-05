<?php
// ============================================================
// RmAnomaly — things that are probably wrong even when every source
// agrees.
//
// Corroboration catches disagreement. It does not catch a value that
// is internally impossible: episode 813 airing before episode 812,
// a runtime of 999 minutes, the same guest listed twice, a title
// identical to an unrelated episode's. Those slip through every
// consensus check because they are not contested — they are just
// wrong.
//
// An anomaly is never treated as proof of error. It withholds the
// affected fields from the automatic write and says why, so a person
// looks. Silence would be worse than a false alarm here: the archive
// is the record.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/DataNormalizer.php';

class RmAnomaly
{
    /**
     * @param array<string,RmDecision> $decisions
     * @param array $existing current database values
     * @return array<int,array{type:string,message:string,fields:string[],severity:string}>
     */
    public static function check(?PDO $db, int $epNum, array $decisions, array $existing = []): array
    {
        $out = [];
        $proposed = [];
        foreach ($decisions as $field => $d) {
            if (in_array($d->decision, ['UPDATE', 'FILL'], true)) $proposed[$field] = $d->value;
        }

        // ── Broadcast order ──
        if (isset($proposed['air_date'])) {
            $date = (string)$proposed['air_date'];
            foreach (self::neighbours($db, $epNum) as $n => $neighbourDate) {
                if ($neighbourDate === null) continue;
                if ($n < $epNum && $date < $neighbourDate) {
                    $out[] = [
                        'type' => 'date_order', 'severity' => 'high', 'fields' => ['air_date'],
                        'message' => "EP$epNum would air on $date, before EP$n on $neighbourDate",
                    ];
                }
                if ($n > $epNum && $date > $neighbourDate) {
                    $out[] = [
                        'type' => 'date_order', 'severity' => 'high', 'fields' => ['air_date'],
                        'message' => "EP$epNum would air on $date, after EP$n on $neighbourDate",
                    ];
                }
            }
            // A date implausibly far from where the episode number puts it.
            $expected = rmYear($epNum);
            $year = (int)substr($date, 0, 4);
            if ($year > 0 && abs($year - $expected) > 1) {
                $out[] = [
                    'type' => 'date_year', 'severity' => 'high', 'fields' => ['air_date'],
                    'message' => "EP$epNum belongs to $expected by episode number, but the date given is $year",
                ];
            }
        }

        // ── Runtime ──
        if (isset($proposed['runtime'])) {
            $mins = (int)$proposed['runtime'];
            if ($mins < 10 || $mins > 300) {
                $out[] = [
                    'type' => 'runtime', 'severity' => 'medium', 'fields' => ['runtime'],
                    'message' => "Runtime of $mins minutes is outside anything this show has aired",
                ];
            }
        }

        // ── Duplicated guests ──
        if (!empty($proposed['guests']) && is_array($proposed['guests'])) {
            $keys = array_map(fn($g) => RmNormalizer::guestKey(is_array($g) ? ($g['name'] ?? '') : (string)$g),
                              $proposed['guests']);
            $keys = array_filter($keys);
            $dupes = array_keys(array_filter(array_count_values($keys), fn($n) => $n > 1));
            if ($dupes) {
                $out[] = [
                    'type' => 'duplicate_guest', 'severity' => 'low', 'fields' => ['guests'],
                    'message' => 'The same person appears more than once in the guest list ('
                               . count($dupes) . ' duplicate' . (count($dupes) === 1 ? '' : 's') . ')',
                ];
            }
            $max = (int)rmScrapeConfig('safety.max_guests_per_ep', 30);
            if (count($proposed['guests']) > $max) {
                $out[] = [
                    'type' => 'guest_count', 'severity' => 'medium', 'fields' => ['guests'],
                    'message' => count($proposed['guests']) . " guests for one episode exceeds the $max the archive considers plausible",
                ];
            }
        }

        // ── A title that belongs to a different episode ──
        if (isset($proposed['title']) && $db !== null) {
            $desc = RmNormalizer::titleDescriptor((string)$proposed['title']);
            if ($desc !== null && mb_strlen($desc) > 8) {
                try {
                    $s = $db->prepare(
                        'SELECT episode_number FROM episodes
                          WHERE episode_number <> ? AND title LIKE ? LIMIT 1');
                    $s->execute([$epNum, '%' . mb_substr($desc, 0, 30) . '%']);
                    $clash = $s->fetchColumn();
                    if ($clash) {
                        $out[] = [
                            'type' => 'title_clash', 'severity' => 'medium', 'fields' => ['title'],
                            'message' => "The proposed title is already used, near-identically, by EP$clash",
                        ];
                    }
                } catch (Throwable $e) { }
            }
        }

        // ── A generic thumbnail standing in for an episode still ──
        if (isset($proposed['image_url'])) {
            $u = (string)$proposed['image_url'];
            if (preg_match('~/(?:default|placeholder|no[-_]?image|poster|logo|show|series)[-_.]~i', $u)) {
                $out[] = [
                    'type' => 'generic_thumbnail', 'severity' => 'low', 'fields' => ['image_url'],
                    'message' => 'The image looks like show artwork rather than a still from this episode',
                ];
            }
        }

        // ── A synopsis that is really the show's blurb ──
        if (isset($proposed['synopsis'])) {
            $syn = mb_strtolower((string)$proposed['synopsis']);
            if (preg_match('/members?\s+compete\s+in\s+a\s+series\s+of\s+games?\s+and\s+missions?/i', $syn)) {
                $out[] = [
                    'type' => 'generic_synopsis', 'severity' => 'medium', 'fields' => ['synopsis'],
                    'message' => "The synopsis is the show's standing description, not this episode's",
                ];
            }
        }

        return $out;
    }

    /** The recorded air dates of the episodes either side of this one. */
    private static function neighbours(?PDO $db, int $epNum): array
    {
        if ($db === null) return [];
        try {
            $s = $db->prepare(
                'SELECT episode_number, air_date FROM episodes
                  WHERE episode_number IN (?, ?) AND air_date IS NOT NULL');
            $s->execute([$epNum - 1, $epNum + 1]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['episode_number']] = (string)$r['air_date'];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * Numbering gaps across the archive — the one anomaly that is about
     * the collection rather than a single episode.
     *
     * @return int[] episode numbers that ought to exist and do not
     */
    public static function numberingGaps(?PDO $db, int $limit = 50): array
    {
        if ($db === null) return [];
        try {
            $max = (int)$db->query('SELECT MAX(episode_number) FROM episodes')->fetchColumn();
            if ($max < 2) return [];
            $have = $db->query("SELECT episode_number FROM episodes WHERE episode_number BETWEEN 1 AND $max")
                       ->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_values(array_diff(range(1, $max), array_map('intval', $have)));
            return array_slice($missing, 0, $limit);
        } catch (Throwable $e) { return []; }
    }
}
