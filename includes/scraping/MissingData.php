<?php
// ============================================================
// RmMissingData — incremental scraping's targeting system.
//
// Two jobs:
//
// 1. FIELD-LEVEL gap detection. An episode with a title, an air date
//    and guests but no synopsis is not "a missing episode" — it is an
//    episode missing one field. Treating it as missing re-fetches
//    everything it already has. This reports the specific empty
//    fields so the engine can call only the sources that supply them.
//
// 2. TARGET SELECTION for each scraping mode: latest, single, range,
//    missing, failed, changed/unstable, full. Nothing here fetches
//    anything — it only decides WHICH episodes are worth fetching.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/Provenance.php';

class RmMissingData
{
    /** Fields the archive stores per episode and can detect gaps in. */
    const TRACKED = ['title','air_date','synopsis','mission','location','guests','thumbnail','teams','results'];

    private ?PDO $db;

    public function __construct(?PDO $db = null) {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
    }

    private function hasTeamsResults(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        if ($this->db === null) return $has = false;
        try { $this->db->query('SELECT teams, results FROM episodes LIMIT 1'); return $has = true; }
        catch (Throwable $e) { return $has = false; }
    }

    /** Current DB values for one episode, in the engine's field vocabulary. */
    public function currentValues(int $epNum): array
    {
        if ($this->db === null) return [];
        try {
            $cols = 'e.episode_id, e.episode_number, e.title, e.air_date, e.synopsis, e.main_mission, e.special_notes, e.theme_id, e.location_id'
                  . ($this->hasTeamsResults() ? ', e.teams, e.results' : '');
            $stmt = $this->db->prepare(
                "SELECT $cols, l.name AS location_name, t.local_path AS thumb_path, t.thumbnail_url, t.verified AS thumb_verified
                   FROM episodes e
                   LEFT JOIN locations l ON l.location_id = e.location_id
                   LEFT JOIN thumbnails t ON t.thumbnail_id = e.thumbnail_id
                  WHERE e.episode_number = ?"
            );
            $stmt->execute([$epNum]);
            $row = $stmt->fetch();
            if (!$row) return [];

            $guests = [];
            $g = $this->db->prepare(
                "SELECT g.name_romanized FROM episode_guests eg
                   JOIN guests g ON g.guest_id = eg.guest_id
                  WHERE eg.episode_id = ? ORDER BY g.name_romanized"
            );
            $g->execute([(int)$row['episode_id']]);
            $guests = array_map('strval', $g->fetchAll(PDO::FETCH_COLUMN));

            $tags = [];
            $t = $this->db->prepare(
                "SELECT tg.name FROM episode_tags et JOIN tags tg ON tg.tag_id = et.tag_id WHERE et.episode_id = ?"
            );
            $t->execute([(int)$row['episode_id']]);
            $tags = array_map('strval', $t->fetchAll(PDO::FETCH_COLUMN));

            return [
                'episode_id'    => (int)$row['episode_id'],
                'episode_number'=> (int)$row['episode_number'],
                'title'         => $row['title'] ?: null,
                'air_date'      => $row['air_date'] ?: null,
                'synopsis'      => $row['synopsis'] ?: null,
                'mission'       => $row['main_mission'] ?: null,
                'teams'         => $row['teams'] ?? null,
                'results'       => $row['results'] ?? null,
                'special_notes' => $row['special_notes'] ?: null,
                'location'      => $row['location_name'] ?: null,
                'guests'        => $guests,
                'tags'          => $tags,
                'thumbnail'     => $row['thumb_path'] ?: null,
                'thumb_verified'=> (int)($row['thumb_verified'] ?? 0),
                'image_url'     => $row['thumbnail_url'] ?: null,
            ];
        } catch (Throwable $e) { return []; }
    }

    /**
     * Which tracked fields are empty for this episode.
     * A bare "Episode #NNN" title counts as missing: it is a placeholder,
     * not a title.
     */
    public function gaps(int $epNum, ?array $values = null): array
    {
        $v = $values ?? $this->currentValues($epNum);
        if (!$v) return self::TRACKED;   // episode absent entirely

        $missing = [];
        if (empty($v['title']) || RmNormalizer::titleDescriptor((string)$v['title']) === null) $missing[] = 'title';
        if (empty($v['air_date'])) $missing[] = 'air_date';
        if (empty($v['synopsis'])) $missing[] = 'synopsis';
        if (empty($v['mission']))  $missing[] = 'mission';
        if (empty($v['location'])) $missing[] = 'location';
        if (empty($v['guests']))   $missing[] = 'guests';
        if (empty($v['thumbnail']) || empty($v['thumb_verified'])) $missing[] = 'thumbnail';
        if ($this->hasTeamsResults()) {
            if (empty($v['teams']))   $missing[] = 'teams';
            if (empty($v['results'])) $missing[] = 'results';
        }
        return $missing;
    }

    /** A completeness percentage, for the Admin listing. */
    public function completeness(int $epNum, ?array $values = null): int
    {
        $tracked = $this->hasTeamsResults() ? self::TRACKED : array_diff(self::TRACKED, ['teams','results']);
        $gaps = $this->gaps($epNum, $values);
        $have = count($tracked) - count(array_intersect($tracked, $gaps));
        return (int)round($have / max(1, count($tracked)) * 100);
    }

    /**
     * Episodes with at least one empty field.
     * @return array<int,string[]> episode_number => missing fields
     */
    public function incompleteEpisodes(int $limit = 200, array $onlyFields = []): array
    {
        if ($this->db === null) return [];
        $conds = [
            'title'     => "(e.title IS NULL OR e.title = '' OR e.title NOT LIKE '%% - %%')",
            'air_date'  => 'e.air_date IS NULL',
            'synopsis'  => "(e.synopsis IS NULL OR e.synopsis = '')",
            'mission'   => "(e.main_mission IS NULL OR e.main_mission = '')",
            'location'  => 'e.location_id IS NULL',
            'guests'    => 'NOT EXISTS (SELECT 1 FROM episode_guests eg WHERE eg.episode_id = e.episode_id)',
            'thumbnail' => '(t.thumbnail_id IS NULL OR t.verified = 0)',
        ];
        if ($this->hasTeamsResults()) {
            $conds['teams']   = "(e.teams IS NULL OR e.teams = '')";
            $conds['results'] = "(e.results IS NULL OR e.results = '')";
        }
        $wanted = $onlyFields ? array_intersect_key($conds, array_flip($onlyFields)) : $conds;
        if (!$wanted) return [];

        try {
            $sql = 'SELECT e.episode_number, ' .
                   implode(', ', array_map(fn($f, $c) => "($c) AS miss_$f", array_keys($wanted), $wanted)) .
                   ' FROM episodes e LEFT JOIN thumbnails t ON t.thumbnail_id = e.thumbnail_id' .
                   ' WHERE ' . implode(' OR ', $wanted) .
                   ' ORDER BY e.episode_number DESC LIMIT ' . max(1, min(2000, $limit));
            $out = [];
            foreach ($this->db->query($sql)->fetchAll() as $row) {
                $fields = [];
                foreach ($wanted as $f => $_) if (!empty($row["miss_$f"])) $fields[] = $f;
                if ($fields) $out[(int)$row['episode_number']] = $fields;
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * How many STORED episodes are missing each field — one COUNT per
     * field in a single pass, for a dashboard's "Metadata Health"
     * breakdown (PR11 §5). Same predicates as incompleteEpisodes(),
     * just aggregated instead of listed per-episode, and over every
     * stored episode rather than capped at a page limit.
     *
     * @return array<string,int> field => count of episodes missing it
     */
    public function fieldGapCounts(array $onlyFields = []): array
    {
        if ($this->db === null) return [];
        $conds = [
            'title'     => "(e.title IS NULL OR e.title = '' OR e.title NOT LIKE '%% - %%')",
            'air_date'  => 'e.air_date IS NULL',
            'synopsis'  => "(e.synopsis IS NULL OR e.synopsis = '')",
            'mission'   => "(e.main_mission IS NULL OR e.main_mission = '')",
            'location'  => 'e.location_id IS NULL',
            'theme'     => 'e.theme_id IS NULL',
            'guests'    => 'NOT EXISTS (SELECT 1 FROM episode_guests eg WHERE eg.episode_id = e.episode_id)',
            'thumbnail' => '(t.thumbnail_id IS NULL OR t.verified = 0)',
        ];
        if ($this->hasTeamsResults()) {
            $conds['teams']   = "(e.teams IS NULL OR e.teams = '')";
            $conds['results'] = "(e.results IS NULL OR e.results = '')";
        }
        $wanted = $onlyFields ? array_intersect_key($conds, array_flip($onlyFields)) : $conds;
        if (!$wanted) return [];

        try {
            $sql = 'SELECT ' . implode(', ', array_map(fn($f, $c) => "SUM($c) AS $f", array_keys($wanted), $wanted))
                 . ' FROM episodes e LEFT JOIN thumbnails t ON t.thumbnail_id = e.thumbnail_id';
            $row = $this->db->query($sql)->fetch();
            $out = [];
            foreach (array_keys($wanted) as $f) $out[$f] = (int)($row[$f] ?? 0);
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * CORE fields only — title and air_date. Everything else tracked by
     * gaps()/incompleteEpisodes() (synopsis, mission, teams, results,
     * location, theme, guests, thumbnail) is enrichment: useful, tracked
     * separately (fieldGapCounts()), but a public source simply not
     * publishing a synopsis for an old episode does not make that
     * episode's ARCHIVE record incomplete (PR11 §5/§16). This is the one
     * completeness percentage that should ever be shown as the archive's
     * headline "% Complete" — the any-of-9-fields definition is a
     * research-queue targeting predicate, not a coverage metric.
     */
    const CORE = ['title', 'air_date'];

    /** @return array{total:int,core_complete:int,core_partial:int,pct:int} */
    public function coreCompleteness(): array
    {
        if ($this->db === null) return ['total' => 0, 'core_complete' => 0, 'core_partial' => 0, 'pct' => 0];
        try {
            $total = (int)$this->db->query('SELECT COUNT(*) FROM episodes')->fetchColumn();
            if ($total === 0) return ['total' => 0, 'core_complete' => 0, 'core_partial' => 0, 'pct' => 0];
            $partial = (int)$this->db->query(
                "SELECT COUNT(*) FROM episodes e WHERE
                    (e.title IS NULL OR e.title = '' OR e.title NOT LIKE '%% - %%')
                    OR e.air_date IS NULL"
            )->fetchColumn();
            $complete = $total - $partial;
            return ['total' => $total, 'core_complete' => $complete, 'core_partial' => $partial,
                    'pct' => (int)round($complete / $total * 100)];
        } catch (Throwable $e) { return ['total' => 0, 'core_complete' => 0, 'core_partial' => 0, 'pct' => 0]; }
    }

    /** Episodes whose last scrape failed, from the provenance table. */
    public function failedEpisodes(int $limit = 200): array
    {
        if ($this->db === null || !rmScrapingTablesExist()) return [];
        try {
            $stmt = $this->db->prepare(
                "SELECT episode_number, GROUP_CONCAT(DISTINCT source_name) AS sources,
                        GROUP_CONCAT(DISTINCT status) AS statuses, MAX(fetched_at) AS last_try
                   FROM episode_sources
                  WHERE status IN ('fetch_failed','blocked','rate_limited','parser_warning')
                  GROUP BY episode_number
                  ORDER BY episode_number DESC LIMIT " . max(1, min(1000, $limit))
            );
            $stmt->execute();
            $out = [];
            foreach ($stmt->fetchAll() as $r) $out[(int)$r['episode_number']] = $r;
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** Episodes carrying a CONFLICT or recent rejected write — "unstable". */
    public function unstableEpisodes(int $limit = 200): array
    {
        if ($this->db === null || !rmScrapingTablesExist()) return [];
        try {
            $stmt = $this->db->prepare(
                "SELECT episode_number, GROUP_CONCAT(DISTINCT field_name) AS fields FROM (
                     SELECT episode_number, field_name FROM episode_field_sources WHERE confidence='conflict'
                     UNION
                     SELECT episode_number, field_name FROM scrape_changes
                      WHERE change_type IN ('rejected','conflict') AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                 ) x GROUP BY episode_number ORDER BY episode_number DESC LIMIT " . max(1, min(1000, $limit))
            );
            $stmt->execute();
            $out = [];
            foreach ($stmt->fetchAll() as $r) $out[(int)$r['episode_number']] = explode(',', (string)$r['fields']);
            return $out;
        } catch (Throwable $e) { return []; }
    }

    public function maxEpisode(): int
    {
        if ($this->db === null) return 0;
        try { return (int)$this->db->query('SELECT COALESCE(MAX(episode_number),0) FROM episodes')->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    public function exists(int $epNum): bool
    {
        if ($this->db === null) return false;
        try {
            $s = $this->db->prepare('SELECT 1 FROM episodes WHERE episode_number=? LIMIT 1');
            $s->execute([$epNum]);
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /** Episode numbers between 1 and the archive max that have no row at all. */
    public function gapsInNumbering(int $limit = 200): array
    {
        if ($this->db === null) return [];
        try {
            $max = $this->maxEpisode();
            if ($max < 1) return [];
            $have = array_flip(array_map('intval', $this->db->query('SELECT episode_number FROM episodes')->fetchAll(PDO::FETCH_COLUMN)));
            $out = [];
            for ($n = 1; $n <= $max && count($out) < $limit; $n++) if (!isset($have[$n])) $out[] = $n;
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * Build the episode queue for a scraping mode.
     * @return array{episodes:int[], fields:array<int,string[]>, label:string}
     */
    public function targetsFor(string $mode, array $opt = []): array
    {
        $limit = (int)($opt['limit'] ?? 200);
        switch ($mode) {
            case 'single':
                $n = (int)($opt['episode'] ?? 0);
                return ['episodes' => $n > 0 ? [$n] : [], 'fields' => [], 'label' => "EP$n"];

            case 'range':
                $from = max(1, (int)($opt['from'] ?? 1));
                $to   = max($from, (int)($opt['to'] ?? $from));
                $eps  = range($from, min($to, $from + max(0, $limit) - 1));
                // Concatenated, not interpolated: PHP 8 allows high bytes in
                // identifiers, so "EP$from–EP" parses the en-dash as part of
                // the variable name and silently yields an undefined variable.
                return ['episodes' => $eps, 'fields' => [], 'label' => 'EP' . $from . '–EP' . end($eps)];

            case 'latest':
                $latest = (int)($opt['latest'] ?? 0);
                $dbMax  = $this->maxEpisode();
                $eps = [];
                if ($latest > $dbMax) {
                    for ($n = $dbMax + 1; $n <= min($latest, $dbMax + max(1, (int)($opt['max_new'] ?? 5))); $n++) $eps[] = $n;
                }
                // Recent episodes are still being edited upstream for days
                // after airing, so re-check the tail — but only the fields
                // that are actually still missing.
                $fields = [];
                foreach ($this->incompleteEpisodes(max(1, (int)($opt['tail'] ?? 3))) as $ep => $miss) {
                    if (!in_array($ep, $eps, true)) { $eps[] = $ep; $fields[$ep] = $miss; }
                }
                sort($eps);
                return ['episodes' => $eps, 'fields' => $fields, 'label' => 'Latest' . ($latest ? " (detected EP$latest)" : '')];

            case 'missing':
                $incomplete = $this->incompleteEpisodes($limit, (array)($opt['fields'] ?? []));
                $eps = array_keys($incomplete);
                // Episodes with no row at all are missing everything.
                foreach ($this->gapsInNumbering(max(0, $limit - count($eps))) as $n) {
                    if (!isset($incomplete[$n])) { $eps[] = $n; $incomplete[$n] = self::TRACKED; }
                }
                sort($eps);
                return ['episodes' => $eps, 'fields' => $incomplete, 'label' => 'Missing data (' . count($eps) . ' episodes)'];

            case 'failed':
                $failed = $this->failedEpisodes($limit);
                return ['episodes' => array_keys($failed), 'fields' => [], 'label' => 'Previously failed (' . count($failed) . ')'];

            case 'unstable':
            case 'changed':
                $unstable = $this->unstableEpisodes($limit);
                return ['episodes' => array_keys($unstable), 'fields' => $unstable, 'label' => 'Conflicted/unstable (' . count($unstable) . ')'];

            case 'thumbnails':
                $eps = (new RmThumbnailEngine($this->db))->needingVerification($limit);
                $fields = [];
                foreach ($eps as $n) $fields[$n] = ['thumbnail'];
                return ['episodes' => $eps, 'fields' => $fields, 'label' => 'Thumbnail verification (' . count($eps) . ')'];

            case 'full':
            default:
                $from = max(1, (int)($opt['from'] ?? 1));
                $to   = (int)($opt['to'] ?? $this->maxEpisode());
                if ($to < $from) $to = $from;
                $eps  = range($from, min($to, $from + max(0, $limit) - 1));
                return ['episodes' => $eps, 'fields' => [], 'label' => 'Full rescan EP' . $from . '–EP' . end($eps)];
        }
    }
}
