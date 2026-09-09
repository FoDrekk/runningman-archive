<?php
// ============================================================
// RmResearchState — what we already know about looking.
//
// The Auto Sync page used to compute its queue from one question:
// "which episodes have an empty field?" That question has no memory,
// so 386 episodes whose synopsis genuinely does not exist on any
// public source came back every single time the page was opened, and
// a run that had done everything correctly looked like it had done
// nothing.
//
// This class records the OTHER half: we looked, on this date, with
// these sources at these parser versions, and this is what we found.
// An episode that is incomplete AND has been researched is a coverage
// gap. An episode that is incomplete and has NEVER been researched is
// work. Those are different, and the archive has to be able to say
// which is which.
//
// Every method degrades to a safe default when the research tables
// are absent, so nothing here can break an install that has not run
// database/research_engine.sql.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/MissingData.php';
require_once __DIR__ . '/SourceRegistry.php';

/** True when database/research_engine.sql has been run. */
function rmResearchTablesExist(bool $recheck = false): bool {
    static $exists = null;
    if ($recheck) $exists = null;
    if ($exists !== null) return $exists;
    try {
        $db = getDBSafe();
        if ($db === null) throw new RuntimeException('Database unavailable');
        foreach (['research_state', 'research_queue', 'research_evidence',
                  'research_decisions', 'source_reputation'] as $t) {
            $db->query("SELECT 1 FROM `$t` LIMIT 1");
        }
        // The run columns are part of the same migration; without them the
        // run lifecycle cannot be recorded, so this counts as not installed.
        $db->query('SELECT run_ref, episodes_requested, cancel_requested FROM scrape_runs LIMIT 1');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

class RmResearchState
{
    // The per-episode states from the specification. RESEARCHED_* are
    // outcomes of a completed look; the rest describe the archive.
    public const NEVER      = 'never_researched';
    public const DONE       = 'researched';
    public const NO_NEW     = 'researched_no_new_data';
    public const UPDATED    = 'researched_and_updated';
    public const REVIEW     = 'researched_needs_review';
    public const FAILED     = 'research_failed';
    public const STALE      = 'stale';
    public const CONFLICT   = 'conflict';

    private ?PDO $db;
    private RmMissingData $missing;

    public function __construct(?PDO $db = null)
    {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
        $this->missing = new RmMissingData($this->db);
    }

    private function ready(): bool { return $this->db !== null && rmResearchTablesExist(); }

    // ────────────────────────────────────────────────────────────
    // The source signature
    // ────────────────────────────────────────────────────────────
    /**
     * A fingerprint of "what we are currently able to ask": every
     * enabled source and the version of its parser.
     *
     * This is what makes "retry when a new source is available" and
     * "retry when the parser improved" work without anyone having to
     * remember to flush anything. Add an adapter, or bump a parser
     * version after fixing a selector, and every episode whose last
     * look used the old signature becomes eligible again — which is
     * exactly when a re-look might genuinely find something new.
     */
    public static function signature(): string
    {
        static $sig = null;
        if ($sig !== null) return $sig;
        $parts = [];
        foreach (RmSourceRegistry::instance()->all() as $name => $a) {
            if (!$a->isEnabled()) continue;
            $parts[] = $name . ':' . $a->parserVersion();
        }
        sort($parts);
        return $sig = sha1(implode('|', $parts));
    }

    // ────────────────────────────────────────────────────────────
    // Reading
    // ────────────────────────────────────────────────────────────
    /** @return array<string,mixed>|null the stored row, or null if never looked at */
    public function get(int $epNum): ?array
    {
        if (!$this->ready()) return null;
        try {
            $s = $this->db->prepare('SELECT * FROM research_state WHERE episode_number = ?');
            $s->execute([$epNum]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }

    /** @return array<int,array> keyed by episode number */
    public function getMany(array $epNums): array
    {
        if (!$this->ready() || !$epNums) return [];
        $epNums = array_values(array_unique(array_map('intval', $epNums)));
        $in = implode(',', array_fill(0, count($epNums), '?'));
        try {
            $s = $this->db->prepare("SELECT * FROM research_state WHERE episode_number IN ($in)");
            $s->execute($epNums);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['episode_number']] = $r;
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * The full picture for one episode: archive completeness, research
     * memory, and the plain-language explanation of why it looks the
     * way it does. This is what the episode row in the UI renders.
     */
    public function describe(int $epNum): array
    {
        $values  = $this->missing->currentValues($epNum);
        $gaps    = $this->missing->gaps($epNum, $values);
        $state   = $this->get($epNum);
        $exists  = (bool)$values;

        $status = $state['status'] ?? ($exists ? self::NEVER : self::NEVER);
        return [
            'episode'          => $epNum,
            'exists'           => $exists,
            'completeness'     => $exists ? $this->missing->completeness($epNum, $values) : 0,
            'missing'          => $gaps,
            'status'           => $status,
            'researched'       => $state !== null && $status !== self::NEVER,
            'last_researched'  => $state['last_researched_at'] ?? null,
            'attempts'         => (int)($state['research_attempts'] ?? 0),
            'reason'           => $state['last_reason'] ?? null,
            'next_eligible'    => $state['next_eligible_at'] ?? null,
            'confidence'       => isset($state['confidence']) ? (int)$state['confidence'] : null,
            'eligible'         => $this->isEligible($epNum, $state),
            'stale'            => $this->isStale($epNum, $state),
        ];
    }

    // ────────────────────────────────────────────────────────────
    // Eligibility — the anti-requeue rule
    // ────────────────────────────────────────────────────────────
    /**
     * Should this episode be researched again right now?
     *
     * Yes when we have never looked, when the cool-down has expired,
     * or when what we are able to ask has changed since we last looked
     * (a new source, an improved parser). No while the cool-down is
     * still running and nothing about our capability has changed —
     * because asking the same sources the same question on the same
     * day gets the same answer, and the archive already recorded it.
     *
     * A caller that genuinely wants to look anyway passes force, which
     * is what [RETRY] and Deep Research do. This method decides what
     * happens automatically; it never blocks an explicit request.
     */
    public function isEligible(int $epNum, ?array $state = null, bool $force = false): bool
    {
        if ($force) return true;
        $state ??= $this->get($epNum);
        if ($state === null) return true;                       // never looked
        if (($state['status'] ?? '') === self::REVIEW) return false;  // waiting on a human

        // Capability changed since the last look — worth another.
        if ((string)($state['source_signature'] ?? '') !== self::signature()) return true;

        $until = $state['next_eligible_at'] ?? null;
        if ($until === null) return true;
        return strtotime((string)$until) <= time();
    }

    /**
     * How long to wait before looking again. Backs off on repetition:
     * the tenth look that finds nothing is far less likely to find
     * something than the second, and the sources deserve not to be
     * asked pointlessly.
     */
    public function coolDownSeconds(string $status, int $attempts, int $epNum): int
    {
        $recent = $epNum >= max(0, $this->missing->maxEpisode() - (int)rmScrapeConfig('cache.recent_window', 6));
        $cfg    = (array)rmScrapeConfig('research.cooldown', []);
        $day    = 86400;

        $base = match ($status) {
            // Something changed: check again reasonably soon in case the
            // rest of the record fills in upstream.
            self::UPDATED => (int)($cfg['updated'] ?? 3 * $day),
            // Reached everything and there was nothing. This is the case
            // that used to requeue forever.
            self::NO_NEW  => (int)($cfg['no_new'] ?? 2 * $day),
            // Could not check. Retry sooner — the obstacle may be transient.
            self::FAILED  => (int)($cfg['failed'] ?? 6 * 3600),
            self::CONFLICT=> (int)($cfg['conflict'] ?? 7 * $day),
            default       => (int)($cfg['default'] ?? $day),
        };

        // Each repeat doubles the wait, to a ceiling. A brand-new episode
        // is still moving upstream, so it never backs off as far.
        $factor = min(16, 2 ** max(0, $attempts - 1));
        $cap    = $recent ? (int)($cfg['recent_cap'] ?? $day) : (int)($cfg['cap'] ?? 60 * $day);
        return (int)min($cap, $base * $factor);
    }

    /** Data old enough to be worth re-verifying (never: old enough to overwrite). */
    public function isStale(int $epNum, ?array $state = null): bool
    {
        $state ??= $this->get($epNum);
        if ($state === null || empty($state['last_researched_at'])) return false;
        $days = (int)rmScrapeConfig('research.stale_days', 180);
        return strtotime((string)$state['last_researched_at']) < time() - $days * 86400;
    }

    // ────────────────────────────────────────────────────────────
    // Writing
    // ────────────────────────────────────────────────────────────
    /**
     * Record the outcome of one look.
     *
     * @param string $status one of the class constants
     * @param array  $info   reason, run_id, missing, completeness, confidence
     */
    public function record(int $epNum, string $status, array $info = []): void
    {
        if (!$this->ready()) return;
        try {
            $prev     = $this->get($epNum);
            $attempts = (int)($prev['research_attempts'] ?? 0) + 1;
            $cool     = $this->coolDownSeconds($status, $attempts, $epNum);

            // A look that ended in review waits for a person, not a clock.
            $nextAt = $status === self::REVIEW ? null : date('Y-m-d H:i:s', time() + $cool);

            $missing = $info['missing'] ?? null;
            if (is_array($missing)) $missing = implode(',', $missing);

            $this->db->prepare(
                'INSERT INTO research_state
                   (episode_number, status, last_researched_at, last_run_id, research_attempts,
                    last_reason, next_eligible_at, missing_fields, completeness, confidence, source_signature)
                 VALUES (?,?,NOW(),?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    status=VALUES(status), last_researched_at=VALUES(last_researched_at),
                    last_run_id=VALUES(last_run_id), research_attempts=VALUES(research_attempts),
                    last_reason=VALUES(last_reason), next_eligible_at=VALUES(next_eligible_at),
                    missing_fields=VALUES(missing_fields), completeness=VALUES(completeness),
                    confidence=VALUES(confidence), source_signature=VALUES(source_signature)'
            )->execute([
                $epNum, $status,
                isset($info['run_id']) ? (int)$info['run_id'] : null,
                $attempts,
                mb_substr((string)($info['reason'] ?? ''), 0, 300) ?: null,
                $nextAt,
                $missing !== null ? mb_substr((string)$missing, 0, 300) : null,
                isset($info['completeness']) ? max(0, min(100, (int)$info['completeness'])) : null,
                isset($info['confidence'])   ? max(0, min(100, (int)$info['confidence']))   : null,
                self::signature(),
            ]);
        } catch (Throwable $e) { /* research memory is an optimisation, never a hard dependency */ }
    }

    /** Make an episode eligible again immediately (an explicit retry). */
    public function clearCoolDown(?int $epNum = null): void
    {
        if (!$this->ready()) return;
        try {
            if ($epNum === null) $this->db->query('UPDATE research_state SET next_eligible_at = NULL');
            else $this->db->prepare('UPDATE research_state SET next_eligible_at = NULL WHERE episode_number = ?')
                          ->execute([$epNum]);
        } catch (Throwable $e) { }
    }

    // ────────────────────────────────────────────────────────────
    // The archive, bucketed — "what needs attention now?"
    // ────────────────────────────────────────────────────────────
    /**
     * Splits outstanding work into categories that mean different
     * things and want different responses. Calling all of them
     * "needing sync" is what made the old page unreadable.
     *
     * @return array<string,array{count:int,episodes:int[],label:string,hint:string}>
     */
    public function attention(int $perBucket = 500): array
    {
        // PR11 §3/§8/§11: label these for what they actually are. "new" is
        // genuinely MISSING EPISODES (absent from the database entirely —
        // a coverage gap), never confused with "incomplete", which is
        // metadata enrichment INSUFFICIENT_EVIDENCE for an episode that
        // already exists and has already been researched. Same buckets,
        // same underlying logic — only the label was ever wrong.
        $buckets = [
            'new'              => ['label' => 'Missing Episodes',  'hint' => 'Aired but not in the archive yet — a coverage gap, not a metadata gap', 'episodes' => []],
            'never_researched' => ['label' => 'Never researched',  'hint' => 'Incomplete and never looked at',                   'episodes' => []],
            'conflict'         => ['label' => 'Conflicts',         'hint' => 'Sources disagree — a person should decide',        'episodes' => []],
            'needs_review'     => ['label' => 'Needs review',      'hint' => 'A decision was withheld pending review',           'episodes' => []],
            'failed'           => ['label' => 'Research failed',   'hint' => 'Could not be checked — usually transient',         'episodes' => []],
            'low_confidence'   => ['label' => 'Low confidence',    'hint' => 'Held on weak evidence; worth corroborating',       'episodes' => []],
            'incomplete'       => ['label' => 'Insufficient Evidence', 'hint' => 'Researched, but the data simply is not published anywhere — not a failure', 'episodes' => []],
            'stale'            => ['label' => 'Stale',             'hint' => 'Not verified for a long time',                     'episodes' => []],
        ];
        if ($this->db === null) return $this->finishBuckets($buckets);

        // Episodes that ought to exist but do not.
        foreach ($this->missing->gapsInNumbering($perBucket) as $n) $buckets['new']['episodes'][] = $n;

        $incomplete = $this->missing->incompleteEpisodes($perBucket);
        if (!$incomplete) return $this->finishBuckets($buckets);

        $states = $this->getMany(array_keys($incomplete));
        $lowAt  = (int)rmScrapeConfig('research.low_confidence_below', 75);

        foreach ($incomplete as $ep => $gaps) {
            $st     = $states[$ep] ?? null;
            $status = $st['status'] ?? self::NEVER;

            // Each episode lands in exactly one bucket, most actionable
            // first — an episode with a conflict is a conflict, whatever
            // else is also true of it.
            if ($status === self::CONFLICT)                      { $buckets['conflict']['episodes'][] = $ep; continue; }
            if ($status === self::REVIEW)                        { $buckets['needs_review']['episodes'][] = $ep; continue; }
            if ($status === self::FAILED)                        { $buckets['failed']['episodes'][] = $ep; continue; }
            if ($st === null || $status === self::NEVER)         { $buckets['never_researched']['episodes'][] = $ep; continue; }
            if ($st['confidence'] !== null && (int)$st['confidence'] < $lowAt) {
                $buckets['low_confidence']['episodes'][] = $ep; continue;
            }
            if ($this->isStale($ep, $st))                        { $buckets['stale']['episodes'][] = $ep; continue; }
            $buckets['incomplete']['episodes'][] = $ep;
        }
        return $this->finishBuckets($buckets);
    }

    private function finishBuckets(array $buckets): array
    {
        foreach ($buckets as $k => $b) {
            sort($buckets[$k]['episodes']);
            $buckets[$k]['count'] = count($b['episodes']);
        }
        return $buckets;
    }

    /**
     * Why is this episode queued? Returns the ticked reasons, so the UI
     * can show the checklist from the specification rather than an
     * unexplained episode number.
     *
     * @return string[]
     */
    public function queueReasons(int $epNum, ?array $state = null, ?array $gaps = null): array
    {
        $values = $this->missing->currentValues($epNum);
        $gaps ??= $this->missing->gaps($epNum, $values);
        $state ??= $this->get($epNum);
        $reasons = [];

        if (!$values) {
            $reasons[] = 'New episode — not in the archive yet';
            return $reasons;
        }
        $status = $state['status'] ?? self::NEVER;
        if ($status === self::CONFLICT) $reasons[] = 'Sources disagree on at least one field';
        if ($status === self::FAILED)   $reasons[] = 'The last attempt could not reach enough sources';
        if ($state === null || $status === self::NEVER) $reasons[] = 'Never researched';
        if ($this->isStale($epNum, $state)) $reasons[] = 'Last verified '
            . (isset($state['last_researched_at']) ? substr((string)$state['last_researched_at'], 0, 10) : 'a long time ago');
        if (isset($state['confidence']) && $state['confidence'] !== null
            && (int)$state['confidence'] < (int)rmScrapeConfig('research.low_confidence_below', 75)) {
            $reasons[] = 'Held on low-confidence evidence (' . (int)$state['confidence'] . '%)';
        }
        foreach ($gaps as $g) $reasons[] = ucfirst(str_replace('_', ' ', $g)) . ' missing';
        // A source we could not ask before, or a parser we have since
        // fixed, is a real reason to look again at an episode we already
        // gave up on.
        if ($state !== null && (string)($state['source_signature'] ?? '') !== self::signature()) {
            $reasons[] = 'A source or parser has changed since the last look';
        }
        return $reasons ?: ['Requested explicitly'];
    }

    /** Priority band, P1 (most urgent) … P6. Drives queue ordering. */
    public function priority(int $epNum, ?array $state = null, ?array $gaps = null): int
    {
        $values = $this->missing->currentValues($epNum);
        if (!$values) return 1;                                  // a new episode
        $state ??= $this->get($epNum);
        $gaps  ??= $this->missing->gaps($epNum, $values);
        $status = $state['status'] ?? self::NEVER;

        if ($status === self::CONFLICT) return 2;
        if (array_intersect($gaps, ['title', 'air_date'])) return 3;   // critical fields
        if (isset($state['confidence']) && $state['confidence'] !== null
            && (int)$state['confidence'] < (int)rmScrapeConfig('research.low_confidence_below', 75)) return 4;
        if ($this->isStale($epNum, $state)) return 5;
        return 6;
    }

    /**
     * Archive-wide health. Deliberately separate from run state: a run
     * can be finished while the archive is still incomplete, and the
     * page has to be able to say both at once.
     */
    public function archiveHealth(): array
    {
        $out = [
            'episodes' => 0, 'completeness' => 0, 'researched' => 0, 'never_researched' => 0,
            'incomplete' => 0, 'conflicts' => 0, 'needs_review' => 0, 'stale' => 0,
            'low_confidence' => 0, 'high_confidence' => 0,
        ];
        if ($this->db === null) return $out;
        try {
            $out['episodes'] = (int)$this->db->query('SELECT COUNT(*) FROM episodes')->fetchColumn();
            $out['incomplete'] = count($this->missing->incompleteEpisodes(5000));
            $out['completeness'] = $out['episodes'] > 0
                ? (int)round(($out['episodes'] - $out['incomplete']) / $out['episodes'] * 100) : 0;
        } catch (Throwable $e) { }

        if (!$this->ready()) return $out;
        try {
            $low = (int)rmScrapeConfig('research.low_confidence_below', 75);
            $rows = $this->db->query('SELECT status, COUNT(*) n FROM research_state GROUP BY status')->fetchAll();
            $byStatus = [];
            foreach ($rows as $r) $byStatus[$r['status']] = (int)$r['n'];
            $out['researched']       = array_sum($byStatus) - ($byStatus[self::NEVER] ?? 0);
            $out['never_researched'] = max(0, $out['incomplete'] - $out['researched']);
            $out['conflicts']        = $byStatus[self::CONFLICT] ?? 0;
            $out['needs_review']     = $byStatus[self::REVIEW] ?? 0;
            $days = (int)rmScrapeConfig('research.stale_days', 180);
            $out['stale'] = (int)$this->db->query(
                'SELECT COUNT(*) FROM research_state WHERE last_researched_at < (NOW() - INTERVAL ' . $days . ' DAY)'
            )->fetchColumn();
            $out['low_confidence'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM research_state WHERE confidence IS NOT NULL AND confidence < $low")->fetchColumn();
            $out['high_confidence'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM research_state WHERE confidence >= $low")->fetchColumn();
        } catch (Throwable $e) { }
        // LOCKED (PR11 §6) — a real, separate concept from research_state:
        // fields a person pinned via the episode editor so research never
        // overwrites them. Additive key; degrades to 0 if the PR4 AI/
        // diagnostics migration hasn't been run.
        try {
            $out['locked_episodes'] = (int)$this->db->query(
                'SELECT COUNT(DISTINCT episode_number) FROM field_locks')->fetchColumn();
        } catch (Throwable $e) { $out['locked_episodes'] = 0; }
        return $out;
    }

    /**
     * ARCHIVE COVERAGE (PR11 §2A/§4/§15) — deliberately a SEPARATE axis
     * from archiveHealth()'s metadata-gap numbers above. Answers "does
     * this episode exist" and "what does live evidence say the archive
     * should have", never "is its metadata fully enriched".
     *
     * Reuses RmLatestEpisode::detectDetailed() (PR9/PR10's evidence-based
     * detector — aired vs upcoming vs disagreement vs insufficient
     * evidence, never MAX+1) rather than the older vote-only
     * RmDiscovery::latestEpisode(), and RmMissingData::gapsInNumbering()
     * for numbering gaps below the archive max. Never invents a number:
     * every field here is either a real count or null/an explicit
     * "unknown" — the caller decides how to render that.
     *
     * DB-only by default (safe for an automatic page load, same rule
     * admin/index.php and this page's own pageState() already follow —
     * "never a live network probe on page load"). Pass a fresh
     * RmLatestEpisode::detectDetailed() result via $detection to fold in
     * live evidence (aired-vs-upcoming, missing episodes past the
     * archive max) — reserved for the explicit "Detect Latest" action,
     * never called automatically. Without one, latest_verified_aired
     * falls back to the archive max (a real, already-verified-at-write-
     * time fact, not a guess) and upcoming/decision report "not checked
     * this session" rather than inventing a live answer.
     *
     * @return array{
     *   stored_episodes:int, archive_latest:?int,
     *   latest_verified_aired:?int, upcoming:?int,
     *   decision:string, decision_note:string,
     *   missing_count:int, missing_range:?string, missing_episodes:int[],
     *   core:array
     * }
     */
    public function archiveCoverage(int $missingCap = 200, ?array $detection = null): array
    {
        $stored = (int)($this->db?->query('SELECT COUNT(*) FROM episodes')->fetchColumn() ?? 0);
        $dbMax  = $this->missing->maxEpisode();
        $core   = $this->missing->coreCompleteness();

        $det = $detection ?? [
            'latest_aired' => $dbMax ?: null, 'upcoming' => [], 'missing_aired' => [],
            'decision' => $dbMax > 0 ? 'NOT_CHECKED' : 'SOURCE_UNAVAILABLE',
            'decision_note' => $dbMax > 0
                ? 'Live sources have not been checked this session — showing the archive maximum. Click "Detect Latest" to verify.'
                : 'The archive is empty and live sources have not been checked.',
        ];

        // Missing episode NUMBERS: gaps inside the stored range, plus any
        // aired-and-confirmed episode past the archive max the detector
        // already found evidence for (its 'missing_aired' list) — never
        // just "db_max+1..detected latest" without that confirmation.
        $missing = $this->missing->gapsInNumbering($missingCap);
        foreach ((array)($det['missing_aired'] ?? []) as $m) $missing[] = (int)$m['episode'];
        $missing = array_values(array_unique($missing));
        sort($missing);

        $range = null;
        if ($missing) {
            $range = (count($missing) === (max($missing) - min($missing) + 1))
                ? 'EP' . min($missing) . '–EP' . max($missing)
                : count($missing) . ' episode(s), EP' . min($missing) . '–EP' . max($missing);
        }

        return [
            'stored_episodes'        => $stored,
            'archive_latest'         => $dbMax ?: null,
            'latest_verified_aired'  => $det['latest_aired'] ?? null,
            'upcoming'               => !empty($det['upcoming']) ? (int)$det['upcoming'][0]['episode'] : null,
            'decision'               => $det['decision'],
            'decision_note'          => $det['decision_note'],
            'missing_count'          => count($missing),
            'missing_range'          => $range,
            'missing_episodes'       => $missing,
            'core'                   => $core,
        ];
    }

    /**
     * The episodes a research run should actually take on, in priority
     * order, each with its reason. This is the queue builder, and the
     * place where "do not look at the same thing forever" is enforced.
     *
     * @return array<int,array{episode:int,priority:int,reasons:string[],fields:string[]}>
     */
    public function buildQueue(string $scope, array $opt = []): array
    {
        $limit  = max(1, (int)($opt['limit'] ?? 200));
        $force  = !empty($opt['force']);       // ignore cool-downs (explicit retry / deep research)
        $latest = (int)($opt['latest'] ?? 0);
        $out    = [];

        $add = function (int $ep, array $gaps = []) use (&$out, $force) {
            if (isset($out[$ep])) return;
            $state = $this->get($ep);
            if (!$this->isEligible($ep, $state, $force)) return;
            $out[$ep] = [
                'episode'  => $ep,
                'priority' => $this->priority($ep, $state, $gaps ?: null),
                'reasons'  => $this->queueReasons($ep, $state, $gaps ?: null),
                'fields'   => $gaps,
            ];
        };

        switch ($scope) {
            case 'single':
                $n = (int)($opt['episode'] ?? 0);
                if ($n > 0) {
                    // An explicitly named episode is never held back by a
                    // cool-down: the person asking has overridden it.
                    $state = $this->get($n);
                    $gaps  = $this->missing->gaps($n);
                    $out[$n] = ['episode' => $n, 'priority' => $this->priority($n, $state, $gaps),
                                'reasons' => ['Requested explicitly'], 'fields' => $gaps];
                }
                break;

            case 'range':
                $from = max(1, (int)($opt['from'] ?? 1));
                $to   = max($from, (int)($opt['to'] ?? $from));
                for ($n = $from; $n <= $to && count($out) < $limit; $n++) {
                    $gaps = $this->missing->gaps($n);
                    if ($force) {
                        $out[$n] = ['episode' => $n, 'priority' => $this->priority($n, null, $gaps),
                                    'reasons' => ['In the requested range'], 'fields' => $gaps];
                    } else {
                        $add($n, $gaps);
                    }
                }
                break;

            case 'new':
                $dbMax = $this->missing->maxEpisode();
                for ($n = $dbMax + 1; $n <= $latest && count($out) < $limit; $n++) {
                    $out[$n] = ['episode' => $n, 'priority' => 1,
                                'reasons' => ['New episode — detected upstream, not in the archive yet'],
                                'fields'  => []];
                }
                foreach ($this->missing->gapsInNumbering($limit) as $n) {
                    if (count($out) >= $limit) break;
                    $out[$n] = ['episode' => $n, 'priority' => 1,
                                'reasons' => ['Gap in the episode numbering'], 'fields' => []];
                }
                break;

            case 'missing':
            default:
                foreach ($this->missing->incompleteEpisodes(max($limit * 3, 500)) as $ep => $gaps) {
                    if (count($out) >= $limit) break;
                    $add($ep, $gaps);
                }
                break;
        }

        // P1 first; within a band, newest episodes first — they are the
        // ones a viewer is most likely to be looking at right now.
        $rows = array_values($out);
        usort($rows, fn($a, $b) => [$a['priority'], -$a['episode']] <=> [$b['priority'], -$b['episode']]);
        return array_slice($rows, 0, $limit);
    }

    /**
     * How many episodes a "research missing" run would take on right
     * now, versus how many are incomplete. The gap between these two
     * numbers is the whole point of this class, and the page shows it.
     */
    public function eligibleCount(int $cap = 2000): array
    {
        $incomplete = $this->missing->incompleteEpisodes($cap);
        if (!$incomplete) return ['incomplete' => 0, 'eligible' => 0, 'resting' => 0];
        $states = $this->getMany(array_keys($incomplete));
        $eligible = 0;
        foreach ($incomplete as $ep => $_) if ($this->isEligible($ep, $states[$ep] ?? null)) $eligible++;
        return [
            'incomplete' => count($incomplete),
            'eligible'   => $eligible,
            'resting'    => count($incomplete) - $eligible,
        ];
    }
}
