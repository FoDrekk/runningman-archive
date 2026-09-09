<?php
// ============================================================
// RmDuplicateDetector · RmGuestResolver · RmLatestEpisode
//
// The three "identity" problems the archive has to get right, and the
// one rule they share: WHEN UNSURE, FLAG — NEVER DESTROY.
//
//   Duplicates   episode_number stays the primary identity; anything
//                else (same normalised title, same air date, same
//                source URL, same guest set) is a SUSPICION and gets
//                raised for review, never auto-deleted.
//   Guests       three spellings of one name must not become three
//                people; two different people whose names happen to
//                look alike must not become one. Exact identity keys
//                merge; near-misses are reported.
//   Latest ep    determined from several independent signals rather
//                than a hardcoded number, and a disagreement between
//                signals is recorded as a conflict instead of one
//                being blindly trusted.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/DataNormalizer.php';
require_once __DIR__ . '/Provenance.php';
require_once __DIR__ . '/SourceRegistry.php';

class RmDuplicateDetector
{
    private ?PDO $db;
    private RmProvenance $prov;

    public function __construct(?PDO $db = null) {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
        $this->prov = new RmProvenance($this->db);
    }

    /**
     * Look for episodes that may be duplicates of $epNum.
     * Returns candidates with the reason each was suspected. Nothing is
     * deleted or merged here — that is always a human decision.
     */
    public function check(int $epNum, array $values = []): array
    {
        if ($this->db === null) return [];
        $suspects = [];

        try {
            $title = (string)($values['title'] ?? '');
            $date  = (string)($values['air_date'] ?? '');

            // Same air date, different episode number. Legitimate for a
            // two-part special aired back to back, so it is reported with
            // that caveat rather than treated as proof.
            if ($date !== '') {
                $s = $this->db->prepare('SELECT episode_number, title FROM episodes WHERE air_date=? AND episode_number<>? LIMIT 5');
                $s->execute([$date, $epNum]);
                foreach ($s->fetchAll() as $r) {
                    $suspects[(int)$r['episode_number']][] = "shares air date $date";
                }
            }

            // Same normalised title descriptor.
            $key = RmNormalizer::titleKey($title);
            if ($key !== '' && mb_strlen($key) >= 8) {
                $s = $this->db->prepare('SELECT episode_number, title FROM episodes WHERE episode_number<>? AND title LIKE ? LIMIT 40');
                $s->execute([$epNum, '%' . mb_substr(trim(RmNormalizer::titleDescriptor($title) ?? ''), 0, 20) . '%']);
                foreach ($s->fetchAll() as $r) {
                    if (RmNormalizer::titleKey((string)$r['title']) === $key) {
                        $suspects[(int)$r['episode_number']][] = 'identical normalised title';
                    }
                }
            }

            // Same stored source URL for the same source.
            if (rmScrapingTablesExist() && !empty($values['source_urls'])) {
                foreach ((array)$values['source_urls'] as $src => $url) {
                    if (!$url) continue;
                    $s = $this->db->prepare('SELECT episode_number FROM episode_sources WHERE source_name=? AND source_url=? AND episode_number<>? LIMIT 3');
                    $s->execute([$src, $url, $epNum]);
                    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $n) {
                        $suspects[(int)$n][] = "same $src source URL";
                    }
                }
            }

            // Identical guest set — only meaningful with several guests.
            $guests = array_filter(array_map(fn($g) => RmNormalizer::guestKey((string)$g), (array)($values['guests'] ?? [])));
            if (count($guests) >= 3) {
                sort($guests);
                $sig = implode('|', $guests);
                $s = $this->db->prepare(
                    "SELECT e.episode_number, GROUP_CONCAT(g.name_romanized ORDER BY g.name_romanized) AS names
                       FROM episodes e
                       JOIN episode_guests eg ON eg.episode_id = e.episode_id
                       JOIN guests g ON g.guest_id = eg.guest_id
                      WHERE e.episode_number <> ?
                      GROUP BY e.episode_number
                     HAVING COUNT(*) = ? LIMIT 200"
                );
                $s->execute([$epNum, count($guests)]);
                foreach ($s->fetchAll() as $r) {
                    $other = array_filter(array_map(fn($n) => RmNormalizer::guestKey($n), explode(',', (string)$r['names'])));
                    sort($other);
                    if (implode('|', $other) === $sig) $suspects[(int)$r['episode_number']][] = 'identical guest lineup';
                }
            }
        } catch (Throwable $e) { return []; }

        // One weak signal is noise. Two is worth a human's attention.
        $out = [];
        foreach ($suspects as $other => $reasons) {
            $reasons = array_values(array_unique($reasons));
            if (count($reasons) < 2 && !in_array('identical normalised title', $reasons, true)) continue;
            $out[] = ['episode_number' => $other, 'reasons' => $reasons];
        }
        return $out;
    }

    /** Record suspicions for review. Never deletes anything. */
    public function flagAll(int $epNum, array $suspects): int
    {
        foreach ($suspects as $s) {
            $this->prov->flag('duplicate_episode', 'episode', null, $epNum,
                'Possible duplicate of EP' . $s['episode_number'] . ' — ' . implode(', ', $s['reasons']) .
                '. Not merged or deleted: review and resolve manually.');
        }
        return count($suspects);
    }

    /** Archive-wide sweep, for the Admin integrity panel. */
    public function scanAll(int $limit = 500): array
    {
        if ($this->db === null) return [];
        $found = [];
        try {
            // Duplicate air dates
            foreach ($this->db->query(
                "SELECT air_date, GROUP_CONCAT(episode_number ORDER BY episode_number) eps, COUNT(*) c
                   FROM episodes WHERE air_date IS NOT NULL
                  GROUP BY air_date HAVING c > 1 ORDER BY air_date DESC LIMIT $limit")->fetchAll() as $r) {
                $found[] = ['type'=>'same_air_date','key'=>$r['air_date'],'episodes'=>$r['eps'],
                            'note'=>'Two or more episodes share this air date (legitimate for back-to-back specials)'];
            }
            // Duplicate titles
            foreach ($this->db->query(
                "SELECT title, GROUP_CONCAT(episode_number ORDER BY episode_number) eps, COUNT(*) c
                   FROM episodes WHERE title LIKE '%% - %%'
                  GROUP BY title HAVING c > 1 LIMIT $limit")->fetchAll() as $r) {
                $found[] = ['type'=>'same_title','key'=>$r['title'],'episodes'=>$r['eps'],
                            'note'=>'Identical stored title'];
            }
        } catch (Throwable $e) { }
        return $found;
    }

    /**
     * The full data-integrity sweep (PR #4, section 25). Read-only —
     * everything found is a report, never an automatic fix. Each
     * category degrades to an empty result if its table is absent,
     * so this runs the same whether or not the optional PR #4/PR #1
     * migrations have been installed.
     *
     * @return array<string,array{label:string,rows:array}>
     */
    public function fullCheck(int $limit = 500): array
    {
        $out = [
            'duplicate_episode_numbers' => ['label' => 'Duplicate episode numbers', 'rows' => []],
            'invalid_episode_numbers'   => ['label' => 'Invalid episode numbers',    'rows' => []],
            'invalid_dates'             => ['label' => 'Invalid air dates',          'rows' => []],
            'duplicate_air_dates'       => ['label' => 'Duplicate air dates',        'rows' => []],
            'orphaned_guests'           => ['label' => 'Orphaned guests',            'rows' => []],
            'orphaned_thumbnails'       => ['label' => 'Orphaned thumbnails',        'rows' => []],
            'duplicate_thumbnails'      => ['label' => 'Duplicate thumbnail images', 'rows' => []],
            'broken_references'         => ['label' => 'Broken theme/location references', 'rows' => []],
        ];
        if ($this->db === null) return $out;
        $db = $this->db;

        $q = function (string $sql) use ($db): array {
            try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
        };

        // A structural impossibility if the UNIQUE constraint holds, but
        // worth checking anyway — an import that ran with foreign key /
        // unique checks disabled can leave the schema no longer matching
        // what the application assumes.
        $out['duplicate_episode_numbers']['rows'] = $q(
            "SELECT episode_number, COUNT(*) c FROM episodes GROUP BY episode_number HAVING c > 1 LIMIT $limit");

        // The ceiling is deliberately generous — test fixtures across this
        // project use large placeholder numbers (999xxx) specifically to
        // stay clear of real episodes, and those must never read as a
        // data problem in a real archive.
        $out['invalid_episode_numbers']['rows'] = $q(
            "SELECT episode_id, episode_number FROM episodes WHERE episode_number <= 0 OR episode_number > 100000 LIMIT $limit");

        $out['invalid_dates']['rows'] = $q(
            "SELECT episode_number, air_date FROM episodes
              WHERE air_date IS NOT NULL
                AND (air_date < '2010-06-01' OR air_date > DATE_ADD(CURDATE(), INTERVAL 21 DAY))
              ORDER BY episode_number LIMIT $limit");

        $out['duplicate_air_dates']['rows'] = $q(
            "SELECT air_date, GROUP_CONCAT(episode_number ORDER BY episode_number) episodes, COUNT(*) c
               FROM episodes WHERE air_date IS NOT NULL
              GROUP BY air_date HAVING c > 1 ORDER BY air_date DESC LIMIT $limit");

        $out['orphaned_guests']['rows'] = $q(
            "SELECT g.guest_id, g.name_romanized FROM guests g
               LEFT JOIN episode_guests eg ON eg.guest_id = g.guest_id
              WHERE eg.guest_id IS NULL LIMIT $limit");

        // A thumbnails row that no episode actually points to — usually
        // left behind by a re-download that created a fresh row instead
        // of updating the one the episode still references.
        $out['orphaned_thumbnails']['rows'] = $q(
            "SELECT t.thumbnail_id, t.episode_number, t.local_path FROM thumbnails t
               LEFT JOIN episodes e ON e.thumbnail_id = t.thumbnail_id
              WHERE e.episode_id IS NULL LIMIT $limit");

        if ($this->tableExists('thumbnail_meta')) {
            $out['duplicate_thumbnails']['rows'] = $q(
                "SELECT content_hash, GROUP_CONCAT(episode_number ORDER BY episode_number) episodes, COUNT(*) c
                   FROM thumbnail_meta WHERE content_hash IS NOT NULL AND content_hash <> ''
                  GROUP BY content_hash HAVING c > 1 LIMIT $limit");
        }

        // The schema's own foreign keys make this normally impossible;
        // it only fires when a bulk import ran with FOREIGN_KEY_CHECKS=0
        // (several files under database/import_csv/ and MASTER_FIX.sql do).
        $out['broken_references']['rows'] = $q(
            "SELECT e.episode_number, 'theme_id' AS ref, e.theme_id AS value FROM episodes e
               LEFT JOIN themes t ON t.theme_id = e.theme_id
              WHERE e.theme_id IS NOT NULL AND t.theme_id IS NULL
              UNION ALL
             SELECT e.episode_number, 'location_id' AS ref, e.location_id AS value FROM episodes e
               LEFT JOIN locations l ON l.location_id = e.location_id
              WHERE e.location_id IS NOT NULL AND l.location_id IS NULL
              LIMIT $limit");

        return $out;
    }

    private function tableExists(string $table): bool
    {
        try { $this->db->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
        catch (Throwable $e) { return false; }
    }
}

// ============================================================
class RmGuestResolver
{
    private ?PDO $db;
    private RmProvenance $prov;

    public function __construct(?PDO $db = null) {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
        $this->prov = new RmProvenance($this->db);
    }

    private function aliasReady(): bool
    {
        if ($this->db === null) return false;
        try { $this->db->query('SELECT 1 FROM guest_aliases LIMIT 1'); return true; }
        catch (Throwable $e) { return false; }
    }

    /**
     * Find (or create) the guest row for a scraped name.
     *
     * Matching order, strictest first:
     *   1. exact stored name
     *   2. a recorded alias
     *   3. identity key equality against existing guests
     *      ("Lee Kwang Soo" ≡ "Lee Kwang-soo" ≡ "Lee Kwangsoo")
     * A one-character difference is NOT a match — it creates the guest
     * and raises a review flag, because "Kim Jong-kook"/"Kim Jong-kuk"
     * being one person is a guess, not a fact.
     *
     * @return array{guest_id:?int, created:bool, matched:?string, review:?string}
     */
    public function resolve(string $rawName, ?int $epNum = null, ?string $source = null): array
    {
        $name = RmNormalizer::guestName($rawName);
        if ($name === null || $this->db === null) {
            return ['guest_id'=>null,'created'=>false,'matched'=>null,'review'=>'Unusable guest name: ' . mb_substr($rawName, 0, 40)];
        }
        $key = RmNormalizer::guestKey($name);
        if ($key === '') return ['guest_id'=>null,'created'=>false,'matched'=>null,'review'=>'Guest name normalised to nothing'];

        try {
            // 1. exact
            $s = $this->db->prepare('SELECT guest_id FROM guests WHERE name_romanized = ? LIMIT 1');
            $s->execute([$name]);
            $id = $s->fetchColumn();
            if ($id) return ['guest_id'=>(int)$id,'created'=>false,'matched'=>'exact','review'=>null];

            // 2. alias table — but only if the guest it points at still
            // EXISTS. An alias outlives the guest it was created for
            // whenever someone deletes a guest in Admin, and a dangling
            // alias is worse than no alias: resolve() hands back an id
            // that no longer exists, the INSERT IGNORE into
            // episode_guests hits a foreign-key failure that IGNORE
            // swallows, and the guest silently never attaches to any
            // episode again. The JOIN makes that impossible; the DELETE
            // stops the stale row being consulted a second time.
            if ($this->aliasReady()) {
                $s = $this->db->prepare(
                    'SELECT a.guest_id FROM guest_aliases a
                       JOIN guests g ON g.guest_id = a.guest_id
                      WHERE a.alias_key = ? LIMIT 1'
                );
                $s->execute([$key]);
                $id = $s->fetchColumn();
                if ($id) return ['guest_id'=>(int)$id,'created'=>false,'matched'=>'alias','review'=>null];

                $this->db->prepare(
                    'DELETE FROM guest_aliases
                      WHERE alias_key = ? AND guest_id IS NOT NULL
                        AND guest_id NOT IN (SELECT guest_id FROM guests)'
                )->execute([$key]);
            }

            // 3. identity key across existing guests. Narrow the scan with a
            // cheap LIKE on the first characters before normalising in PHP.
            $prefix = mb_substr($name, 0, 3);
            $s = $this->db->prepare('SELECT guest_id, name_romanized FROM guests WHERE name_romanized LIKE ? LIMIT 200');
            $s->execute([$prefix . '%']);
            $near = [];
            foreach ($s->fetchAll() as $row) {
                $existingKey = RmNormalizer::guestKey((string)$row['name_romanized']);
                if ($existingKey === $key) {
                    if ($this->aliasReady()) $this->recordAlias($key, $name, (int)$row['guest_id'], 'auto', $source);
                    return ['guest_id'=>(int)$row['guest_id'],'created'=>false,'matched'=>'normalised','review'=>null];
                }
                if (strlen($existingKey) >= 6 && abs(strlen($existingKey) - strlen($key)) <= 2
                    && levenshtein($existingKey, $key) === 1) {
                    $near[] = (string)$row['name_romanized'];
                }
            }

            // Create — a new person, not a merge.
            $this->db->prepare('INSERT INTO guests (name_romanized) VALUES (?)')->execute([$name]);
            $newId = (int)$this->db->lastInsertId();
            if ($newId <= 0) {
                return ['guest_id'=>null,'created'=>false,'matched'=>null,
                        'review'=>"Could not create guest \"$name\" — the insert returned no id"];
            }
            if ($this->aliasReady()) $this->recordAlias($key, $name, $newId, 'auto', $source);

            $review = null;
            if ($near) {
                $review = "New guest \"$name\" is one character from existing " . implode(', ', array_map(fn($n) => "\"$n\"", array_slice($near, 0, 3)))
                        . ' — same person or two people? Not merged automatically.';
                $this->prov->flag('similar_guest', 'guest', $newId, $epNum, $review);
            }
            return ['guest_id'=>$newId,'created'=>true,'matched'=>null,'review'=>$review];
        } catch (Throwable $e) {
            return ['guest_id'=>null,'created'=>false,'matched'=>null,'review'=>'Guest lookup failed: ' . $e->getMessage()];
        }
    }

    private function recordAlias(string $key, string $raw, ?int $guestId, string $status, ?string $source): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO guest_aliases (alias_key, alias_raw, guest_id, status, source_name) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE guest_id=COALESCE(VALUES(guest_id), guest_id)'
            )->execute([mb_substr($key,0,150), mb_substr($raw,0,150), $guestId, $status, $source ? mb_substr($source,0,40) : null]);
        } catch (Throwable $e) { }
    }

    /**
     * Optional enrichment: fill blank name_korean / profession /
     * nationality from Wikidata. Only ever fills EMPTY columns —
     * an editor's existing value is never overwritten by a lookup.
     */
    public function enrich(int $guestId, string $name): array
    {
        if ($this->db === null) return ['ok'=>false,'reason'=>'No database'];
        $lookup = RmSourceRegistry::instance()->personLookup();
        if ($lookup === null || !method_exists($lookup, 'enrichPerson')) return ['ok'=>false,'reason'=>'No person-lookup source available'];

        try {
            $s = $this->db->prepare('SELECT name_korean, profession, nationality FROM guests WHERE guest_id=?');
            $s->execute([$guestId]);
            $cur = $s->fetch();
            if (!$cur) return ['ok'=>false,'reason'=>'Guest not found'];
            if (!empty($cur['name_korean']) && !empty($cur['profession'])) return ['ok'=>true,'reason'=>'Already enriched','changed'=>[]];

            $info = $lookup->enrichPerson($name);
            if ($info === null) return ['ok'=>false,'reason'=>'No confident Wikidata match'];

            $sets = []; $params = []; $changed = [];
            foreach (['name_korean'=>'name_korean','profession'=>'profession','nationality'=>'nationality'] as $col => $k) {
                if (empty($cur[$col]) && !empty($info[$k])) {
                    $sets[] = "$col = ?";
                    $params[] = mb_substr((string)$info[$k], 0, $col === 'name_korean' ? 100 : 100);
                    $changed[] = $col;
                }
            }
            if (!$sets) return ['ok'=>true,'reason'=>'Nothing new to add','changed'=>[]];
            $params[] = $guestId;
            $this->db->prepare('UPDATE guests SET ' . implode(', ', $sets) . ' WHERE guest_id = ?')->execute($params);
            return ['ok'=>true,'reason'=>null,'changed'=>$changed,'source'=>$info['url'] ?? null];
        } catch (Throwable $e) {
            return ['ok'=>false,'reason'=>$e->getMessage()];
        }
    }
}

// ============================================================
class RmLatestEpisode
{
    /**
     * Determine the newest episode from every signal available, and
     * report disagreement rather than silently picking a winner.
     *
     * @return array{latest:?int, signals:array, confidence:string, conflict:bool, note:string}
     */
    public static function detect(array $opt = []): array
    {
        $registry = RmSourceRegistry::instance();
        $signals  = [];

        // Signal 1: the archive itself — the floor, never the answer.
        $dbMax = (new RmMissingData())->maxEpisode();
        if ($dbMax > 0) $signals['database'] = $dbMax;

        // Signal 2+: whichever sources can report their own maximum.
        foreach ($registry->active() as $name => $adapter) {
            try {
                $n = $adapter->latestEpisode();
                if (is_int($n) && $n > 0 && $n < 2000) $signals[$name] = $n;
            } catch (Throwable $e) { }
        }

        // Signal 3: probe past the highest source-reported number. A new
        // episode appears on myrunningman days before a Wikipedia editor
        // adds it, so the listing sources lag reality by design.
        $probeBase = max([0] + array_values(array_diff_key($signals, ['database' => 1])));
        $mrm = $registry->get('myrunningman');
        if ($probeBase > 0 && $mrm instanceof MyRunningManScraper && $registry->usable('myrunningman')) {
            $probe = $probeBase;
            for ($n = $probeBase + 1; $n <= $probeBase + (int)($opt['probe_depth'] ?? 4); $n++) {
                if ($mrm->episodeExists($n)) $probe = $n; else break;   // stop at the first gap
            }
            if ($probe > $probeBase) $signals['myrunningman_probe'] = $probe;
        }

        $external = array_diff_key($signals, ['database' => 1]);
        if (!$external) {
            return ['latest' => $dbMax ?: null, 'signals' => $signals, 'confidence' => 'low', 'conflict' => false,
                    'note' => 'No external source could report a latest episode — falling back to the archive maximum'];
        }

        $latest = max($external);
        $values = array_values($external);
        $spread = max($values) - min($values);

        // Sources genuinely lag each other by an episode or two; that is
        // normal. A gap of several episodes means something is wrong.
        $conflict = $spread > 3;
        $confidence = count(array_unique($values)) === 1 ? 'high' : ($conflict ? 'conflict' : 'medium');

        $note = $conflict
            ? 'Sources disagree by ' . $spread . ' episodes (' .
              implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($external), $values)) .
              ') — flagged rather than trusted blindly'
            : 'Agreed within ' . $spread . ' episode(s) across ' . count($external) . ' signals';

        if ($conflict) {
            (new RmProvenance())->flag('latest_ep_conflict', 'source', null, $latest, $note);
        }
        // A sudden jump far past the archive is a false positive, not 40 new episodes.
        if ($dbMax > 0 && $latest > $dbMax + 30) {
            return ['latest' => $dbMax, 'signals' => $signals, 'confidence' => 'conflict', 'conflict' => true,
                    'note' => "Detected EP$latest is more than 30 past the archive maximum (EP$dbMax) — rejected as a false positive"];
        }

        return ['latest' => $latest, 'signals' => $signals, 'confidence' => $confidence, 'conflict' => $conflict, 'note' => $note];
    }
}
