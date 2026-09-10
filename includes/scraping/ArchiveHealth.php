<?php
// ============================================================
// RmArchiveHealth — "is the archive internally healthy, and if not,
// exactly what needs attention?"
//
// SCAN → DETECT → CLASSIFY → EXPLAIN → RECOMMEND. Never SCAN → AUTO
// FIX. Every method in this file only reads; nothing here writes to
// episodes, thumbnails, research state, provenance, or source
// configuration. If a future repair is warranted, the issue names the
// existing controlled workflow to use (Auto Sync, Thumbnail Recovery,
// the review inbox) — it never runs one itself.
//
// This is deliberately a thin aggregator, not a new subsystem: every
// domain below is built from a class PR10-PR15 already shipped —
// RmResearchState (coverage + research state), RmMissingData (core vs
// optional metadata), RmDuplicateDetector (structural integrity),
// RmThumbnailEngine (PR14's six-state classify()), RmSourceHealth (the
// source status ladder), RmLatestEpisode (PR13's evidence-based latest
// detector). The one genuinely new check here is Domain G — nothing
// before this audited whether AI/decision provenance is internally
// consistent (an "applied" flag that doesn't correspond to a decision
// that was ever safe to apply, or to a field that still holds a value).
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/MissingData.php';
require_once __DIR__ . '/ResearchState.php';
require_once __DIR__ . '/Integrity.php';
require_once __DIR__ . '/ThumbnailEngine.php';
require_once __DIR__ . '/SourceHealth.php';

class RmArchiveHealth
{
    // Severity vocabulary (Section 10). Order matters for sorting.
    public const INFO     = 'INFO';
    public const WARNING  = 'WARNING';
    public const ERROR    = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    private const SEVERITY_RANK = [self::CRITICAL => 3, self::ERROR => 2, self::WARNING => 1, self::INFO => 0];

    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
    }

    /**
     * The full scan. Read-only, deterministic given unchanged data, and
     * never touches the network on its own — pass a fresh
     * RmLatestEpisode::detectDetailed() result as $opt['detection'] to
     * fold in live latest-episode evidence (exactly the same opt-in
     * pattern RmResearchState::archiveCoverage() already uses); without
     * one, Latest Verification honestly reports UNKNOWN rather than
     * guessing.
     *
     * @param array $opt detection (?array), thumbnail_limit, provenance_limit
     * @return array{generated_at:string,read_only:bool,domains:array,issues:array,severity_counts:array}
     */
    public function scan(array $opt = []): array
    {
        $detection = $opt['detection'] ?? null;
        $thumbLimit = (int)($opt['thumbnail_limit'] ?? rmScrapeConfig('archive_health.thumbnail_scan_limit', 500));
        $provLimit  = (int)($opt['provenance_limit'] ?? rmScrapeConfig('archive_health.provenance_scan_limit', 500));
        $issueCap   = (int)($opt['issue_limit'] ?? rmScrapeConfig('archive_health.issue_limit', 500));

        $domains = [
            'episode_coverage'    => $this->episodeCoverage($detection),
            'metadata'            => $this->metadataIntegrity(),
            'research'            => $this->researchIntegrity(),
            'thumbnails'          => $this->thumbnailIntegrity($thumbLimit),
            'sources'             => $this->sourceHealth(),
            'latest_verification' => $this->latestVerification($detection),
            'provenance'          => $this->provenanceIntegrity($provLimit),
        ];

        $issues = [];
        foreach ($domains as $domainKey => $d) {
            foreach ((array)($d['issues'] ?? []) as $issue) {
                $issue['domain'] = $domainKey;
                $issue['detected_at'] = date('c');
                $issues[] = $issue;
            }
        }

        // Most severe first; deterministic tie-break so two scans of the
        // same unchanged data produce the same order (Section 16) —
        // detected_at is the only field allowed to differ.
        usort($issues, function ($a, $b) {
            return [self::SEVERITY_RANK[$b['severity']] ?? 0, $a['domain'], $a['id'], (int)($a['episode'] ?? 0)]
               <=> [self::SEVERITY_RANK[$a['severity']] ?? 0, $b['domain'], $b['id'], (int)($b['episode'] ?? 0)];
        });
        $truncated = count($issues) > $issueCap;
        $issues = array_slice($issues, 0, max(1, $issueCap));

        $severityCounts = [self::INFO => 0, self::WARNING => 0, self::ERROR => 0, self::CRITICAL => 0];
        foreach ($issues as $i) $severityCounts[$i['severity']] = ($severityCounts[$i['severity']] ?? 0) + 1;

        return [
            'generated_at'   => date('c'),
            'read_only'      => true,
            'domains'        => $domains,
            'issues'         => $issues,
            'issues_truncated' => $truncated,
            'severity_counts'=> $severityCounts,
        ];
    }

    /** One structured issue — Section 12's model. */
    private static function issue(
        string $id, string $severity, string $description, ?int $episode = null,
        array $evidence = [], ?string $recommendedAction = null, bool $safeToAutoRepair = false
    ): array {
        return [
            'id'                  => $id,
            'severity'            => $severity,
            'episode'             => $episode,
            'description'         => $description,
            'evidence'            => $evidence,
            'recommended_action'  => $recommendedAction,
            'safe_to_auto_repair' => $safeToAutoRepair,
        ];
    }

    private function tableExists(string $table): bool
    {
        if ($this->db === null) return false;
        try { $this->db->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
        catch (Throwable $e) { return false; }
    }

    // ────────────────────────────────────────────────────────────
    // A. Episode Coverage
    // ────────────────────────────────────────────────────────────
    private function episodeCoverage(?array $detection): array
    {
        $issues = [];
        $state  = new RmResearchState($this->db);
        $cov    = $state->archiveCoverage(200, $detection);

        if ($cov['stored_episodes'] === 0) {
            return ['status' => 'EMPTY', 'counts' => $cov, 'issues' => [
                self::issue('ARCHIVE_EMPTY', self::INFO, 'The archive has no episodes stored yet — there is nothing to verify.'),
            ], 'explanation' => 'No episodes are stored. This is not "100% healthy" — it means coverage cannot be assessed.'];
        }

        $dupes = (new RmDuplicateDetector($this->db))->fullCheck(200);
        foreach ($dupes['duplicate_episode_numbers']['rows'] as $r) {
            $issues[] = self::issue('DUPLICATE_EPISODE_NUMBER', self::CRITICAL,
                'Episode number ' . $r['episode_number'] . ' is stored ' . $r['c'] . ' times — the primary identity is not unique.',
                (int)$r['episode_number'], $r, 'Inspect both rows manually; merge or renumber. Never auto-merged.');
        }
        foreach ($dupes['invalid_episode_numbers']['rows'] as $r) {
            $issues[] = self::issue('INVALID_EPISODE_NUMBER', self::CRITICAL,
                'Episode number ' . $r['episode_number'] . ' is outside the plausible range.',
                (int)$r['episode_number'], $r, 'Correct the episode number by hand.');
        }

        if ($cov['missing_count'] > 0) {
            $sample = array_slice($cov['missing_episodes'], 0, 20);
            $confirmedAired = $cov['decision'] === 'MISSING';
            $issues[] = self::issue('MISSING_EPISODES', $confirmedAired ? self::ERROR : self::WARNING,
                $cov['missing_count'] . ' episode number(s) are absent from the archive' .
                    ($cov['missing_range'] ? ' (' . $cov['missing_range'] . ')' : '') .
                    ($confirmedAired ? ' — at least one is confirmed aired by live evidence' : ' — a gap in stored numbering, not necessarily confirmed aired'),
                null, ['episodes' => $sample, 'decision' => $cov['decision'], 'decision_note' => $cov['decision_note']],
                'Run Auto Sync → Fill Missing / Detect Latest to research and confirm these episodes.');
        }

        $hasCritical = (bool)array_filter($issues, fn($i) => $i['severity'] === self::CRITICAL);
        $hasError    = (bool)array_filter($issues, fn($i) => $i['severity'] === self::ERROR);
        $status = $hasCritical ? 'CRITICAL' : ($hasError ? 'ERROR' : ($issues ? 'WARNING' : 'GOOD'));

        return ['status' => $status, 'counts' => $cov, 'issues' => $issues,
                'explanation' => $cov['decision_note']];
    }

    // ────────────────────────────────────────────────────────────
    // B. Metadata Integrity
    // ────────────────────────────────────────────────────────────
    private function metadataIntegrity(): array
    {
        $missing = new RmMissingData($this->db);
        $core    = $missing->coreCompleteness();

        if ($core['total'] === 0) {
            return ['status' => 'EMPTY', 'counts' => ['core' => $core, 'optional_gaps' => []], 'issues' => [],
                    'explanation' => 'No episodes stored — metadata cannot be assessed.'];
        }

        $issues = [];
        $optionalGaps = $missing->fieldGapCounts(['synopsis', 'mission', 'location', 'theme', 'guests', 'thumbnail', 'teams', 'results']);
        foreach ($optionalGaps as $field => $n) {
            if ($n <= 0) continue;
            // Thumbnail gaps are reported by the Thumbnail domain, in more
            // useful detail (broken vs missing vs invalid) — not duplicated here.
            if ($field === 'thumbnail') continue;
            $issues[] = self::issue('OPTIONAL_METADATA_MISSING', self::INFO,
                "$n episode(s) are missing $field — enrichment, not core coverage.",
                null, ['field' => $field, 'count' => $n],
                'Optional; Auto Sync will fill it opportunistically when a source has it.');
        }

        if ($core['core_partial'] > 0) {
            $issues[] = self::issue('CORE_METADATA_MISSING', self::ERROR,
                $core['core_partial'] . ' episode(s) are missing a CORE field (title or air date).',
                null, $core, 'Run Auto Sync research on these episodes — core fields matter more than enrichment.');
        }

        $dupes = (new RmDuplicateDetector($this->db))->fullCheck(200);
        foreach ($dupes['invalid_dates']['rows'] as $r) {
            $issues[] = self::issue('INVALID_AIR_DATE', self::ERROR,
                'Episode ' . $r['episode_number'] . ' has an implausible air date (' . $r['air_date'] . ').',
                (int)$r['episode_number'], $r, 'Correct the air date by hand or re-research the episode.');
        }
        foreach ($dupes['duplicate_air_dates']['rows'] as $r) {
            $issues[] = self::issue('DUPLICATE_AIR_DATE', self::WARNING,
                'Episodes ' . $r['episodes'] . ' share air date ' . $r['air_date'] . ' — legitimate for a back-to-back special, worth a glance otherwise.',
                null, $r, 'Review manually; this is not automatically wrong.');
        }
        foreach ($dupes['broken_references']['rows'] as $r) {
            $issues[] = self::issue('BROKEN_REFERENCE', self::CRITICAL,
                'Episode ' . $r['episode_number'] . " references a {$r['ref']} that no longer exists (" . $r['value'] . ').',
                (int)$r['episode_number'], $r, 'Usually caused by an import that ran with foreign-key checks disabled. Fix the reference by hand.');
        }
        foreach ($dupes['orphaned_guests']['rows'] as $r) {
            $issues[] = self::issue('ORPHANED_GUEST', self::INFO,
                'Guest "' . $r['name_romanized'] . '" is not linked to any episode.',
                null, $r, 'Harmless; safe to leave or remove via Admin.');
        }
        foreach ($dupes['orphaned_thumbnails']['rows'] as $r) {
            $issues[] = self::issue('ORPHANED_THUMBNAIL', self::INFO,
                'A thumbnail row for EP' . $r['episode_number'] . ' is not referenced by any episode.',
                (int)$r['episode_number'], $r, 'Harmless; left behind by a re-download that created a new row.');
        }

        $hasCritical = (bool)array_filter($issues, fn($i) => $i['severity'] === self::CRITICAL);
        $hasError    = (bool)array_filter($issues, fn($i) => $i['severity'] === self::ERROR);
        $hasWarning  = (bool)array_filter($issues, fn($i) => $i['severity'] === self::WARNING);
        $status = $hasCritical ? 'CRITICAL' : ($hasError ? 'ERROR' : ($hasWarning ? 'WARNING' : 'GOOD'));

        return ['status' => $status, 'counts' => ['core' => $core, 'optional_gaps' => $optionalGaps], 'issues' => $issues,
                'explanation' => 'Core = title + air date. Everything else is enrichment and never counts against archive health on its own.'];
    }

    // ────────────────────────────────────────────────────────────
    // C. Research Integrity
    // ────────────────────────────────────────────────────────────
    private function researchIntegrity(): array
    {
        $state  = new RmResearchState($this->db);
        $health = $state->archiveHealth();

        if ($health['episodes'] === 0) {
            return ['status' => 'EMPTY', 'counts' => $health, 'issues' => [],
                    'explanation' => 'No episodes stored — research state cannot be assessed.'];
        }
        if (!rmResearchTablesExist()) {
            return ['status' => 'NOT_INSTALLED', 'counts' => $health, 'issues' => [
                self::issue('RESEARCH_TABLES_MISSING', self::INFO, 'The research engine tables are not installed — research state cannot be tracked.'),
            ], 'explanation' => 'Install database/research_engine.sql to enable research-state tracking.'];
        }

        $attention = $state->attention(2000);
        $issues = [];
        $map = [
            'conflict'       => [self::WARNING, 'RESEARCH_CONFLICTS', 'Sources disagree — a person should decide.'],
            'needs_review'   => [self::WARNING, 'RESEARCH_NEEDS_REVIEW', 'A decision is withheld pending review.'],
            'failed'         => [self::WARNING, 'RESEARCH_FAILED', 'Could not be checked last time — usually transient.'],
            'low_confidence' => [self::WARNING, 'RESEARCH_LOW_CONFIDENCE', 'Held on weak evidence; worth corroborating.'],
            'stale'          => [self::INFO, 'RESEARCH_STALE', 'Not verified for a long time.'],
            'never_researched' => [self::INFO, 'RESEARCH_NEVER_RESEARCHED', 'Incomplete and never looked at yet.'],
            // Insufficient evidence is explicitly NOT an error (Section 5)
            // — reported in counts only, never as an issue.
        ];
        foreach ($map as $bucket => [$sev, $id, $desc]) {
            $n = $attention[$bucket]['count'] ?? 0;
            if ($n <= 0) continue;
            $issues[] = self::issue($id, $sev, "$n episode(s): $desc",
                null, ['episodes' => array_slice($attention[$bucket]['episodes'], 0, 20), 'count' => $n],
                'See Auto Sync → ' . $attention[$bucket]['label'] . '.');
        }

        $hasWarning = (bool)array_filter($issues, fn($i) => $i['severity'] === self::WARNING);
        $status = $hasWarning ? 'WARNING' : ($issues ? 'INFO' : 'GOOD');

        return ['status' => $status, 'counts' => $health + ['insufficient_evidence' => $attention['incomplete']['count'] ?? 0],
                'issues' => $issues,
                'explanation' => 'Insufficient Evidence means research ran and found nothing published — not a failure, and never re-queued without a reason to try again.'];
    }

    // ────────────────────────────────────────────────────────────
    // D. Thumbnail Integrity
    // ────────────────────────────────────────────────────────────
    private function thumbnailIntegrity(int $limit): array
    {
        if ($this->db === null) {
            return ['status' => 'UNKNOWN', 'counts' => [], 'issues' => [], 'explanation' => 'No database connection.'];
        }
        $total = (int)$this->db->query('SELECT COUNT(*) FROM episodes')->fetchColumn();
        if ($total === 0) {
            return ['status' => 'EMPTY', 'counts' => [], 'issues' => [], 'explanation' => 'No episodes stored.'];
        }

        $noThumb = (int)$this->db->query('SELECT COUNT(*) FROM episodes WHERE thumbnail_id IS NULL')->fetchColumn();
        $withThumb = $this->db->query(
            'SELECT episode_number FROM episodes WHERE thumbnail_id IS NOT NULL ORDER BY episode_number LIMIT ' . max(1, $limit)
        )->fetchAll(PDO::FETCH_COLUMN);
        $withThumbTotal = (int)$this->db->query('SELECT COUNT(*) FROM episodes WHERE thumbnail_id IS NOT NULL')->fetchColumn();

        $te = new RmThumbnailEngine($this->db);
        $counts = ['VALID' => 0, 'BROKEN' => 0, 'INVALID' => 0, 'DUPLICATE' => 0, 'SUSPECT_DUPLICATE' => 0, 'MISSING' => $noThumb];
        $broken = []; $invalid = []; $dup = []; $suspect = [];
        foreach ($withThumb as $ep) {
            $c = $te->classify((int)$ep);
            $counts[$c['state']] = ($counts[$c['state']] ?? 0) + 1;
            match ($c['state']) {
                'BROKEN' => $broken[] = (int)$ep,
                'INVALID' => $invalid[] = (int)$ep,
                'DUPLICATE' => $dup[] = (int)$ep,
                'SUSPECT_DUPLICATE' => $suspect[] = (int)$ep,
                default => null,
            };
        }

        $issues = [];
        if ($broken) $issues[] = self::issue('THUMBNAIL_BROKEN', self::ERROR,
            count($broken) . ' episode(s) reference a thumbnail file that cannot be found on disk.',
            null, ['episodes' => array_slice($broken, 0, 20)], 'Run Thumbnail Recovery preview (Admin → Thumbnails).');
        if ($invalid) $issues[] = self::issue('THUMBNAIL_INVALID', self::ERROR,
            count($invalid) . ' episode(s) have a thumbnail file that cannot be decoded as a valid image.',
            null, ['episodes' => array_slice($invalid, 0, 20)], 'Run Thumbnail Recovery preview (Admin → Thumbnails).');
        if ($noThumb) $issues[] = self::issue('THUMBNAIL_MISSING', self::WARNING,
            "$noThumb episode(s) have no thumbnail recorded at all.",
            null, ['count' => $noThumb], 'Run Auto Sync / Fetch Latest to acquire one.');
        if ($dup) $issues[] = self::issue('THUMBNAIL_DUPLICATE', self::WARNING,
            count($dup) . ' episode(s) share a byte-identical image with another episode, not explained as a legitimate shared image (e.g. a two-part special).',
            null, ['episodes' => array_slice($dup, 0, 20)], 'Review in Admin → Thumbnails. Never auto-deleted — a duplicate can be legitimate.');
        if ($suspect) $issues[] = self::issue('THUMBNAIL_SUSPECT_DUPLICATE', self::INFO,
            count($suspect) . ' episode(s) have a thumbnail visually similar to another episode\'s (not byte-identical) — low-confidence, for review only.',
            null, ['episodes' => array_slice($suspect, 0, 20)], 'Review in Admin → Thumbnails if curious; no action required.');

        $status = ($broken || $invalid) ? 'ERROR' : (($noThumb || $dup) ? 'WARNING' : ($suspect ? 'INFO' : 'GOOD'));

        return ['status' => $status,
                'counts' => $counts + ['scanned' => count($withThumb), 'with_thumbnail_total' => $withThumbTotal, 'capped' => $withThumbTotal > $limit],
                'issues' => $issues,
                'explanation' => $withThumbTotal > $limit
                    ? 'Scanned the first ' . $limit . ' of ' . $withThumbTotal . ' episodes with a thumbnail — increase archive_health.thumbnail_scan_limit for full coverage.'
                    : 'Every episode with a thumbnail was checked.'];
    }

    // ────────────────────────────────────────────────────────────
    // E. Source Health
    // ────────────────────────────────────────────────────────────
    private function sourceHealth(): array
    {
        $sources = RmSourceHealth::instance()->all();
        if (!$sources) {
            return ['status' => 'UNKNOWN', 'counts' => [], 'issues' => [], 'explanation' => 'No sources configured.'];
        }

        $buckets = ['online' => [], 'degraded' => [], 'unavailable' => [], 'disabled' => [], 'unknown' => []];
        foreach ($sources as $name => $s) {
            $status = (string)$s['status'];
            $suppressed = RmSourceHealth::instance()->isSuppressed($name);
            if ($status === 'disabled') $buckets['disabled'][] = $name;
            elseif ($status === RmSourceHealth::OK && !$suppressed) $buckets['online'][] = $name;
            elseif (in_array($status, [RmSourceHealth::DEGRADED, RmSourceHealth::PARSER_WARNING, RmSourceHealth::RATE_LIMITED], true) || $suppressed) $buckets['degraded'][] = $name;
            elseif (in_array($status, [RmSourceHealth::BLOCKED, RmSourceHealth::ROBOTS_DENIED, RmSourceHealth::DOWN], true)) $buckets['unavailable'][] = $name;
            else $buckets['unknown'][] = $name;
        }

        $active = array_filter($sources, fn($s, $name) => $s['status'] !== 'disabled', ARRAY_FILTER_USE_BOTH);
        $activeCount   = count($active);
        $onlineCount   = count($buckets['online']);
        $troubled      = array_merge($buckets['unavailable'], $buckets['degraded']);

        // "Independent evidence remains available" has to mean a source
        // CONFIRMED reachable (status ok), never merely "not yet tried"
        // (unknown) — counting an untested source as reassurance would be
        // exactly the invented confidence Section 7/10 warns against.
        $issues = [];
        if ($activeCount > 0 && count($buckets['unavailable']) >= $activeCount) {
            $issues[] = self::issue('ALL_SOURCES_UNAVAILABLE', self::ERROR,
                'Every enabled source is blocked, robots-denied, or down — no independent evidence is currently reachable.',
                null, $buckets, 'Wait for cool-downs to expire, or check Admin → Diagnostics for the specific errors.');
        } elseif ($troubled && $onlineCount > 0) {
            $issues[] = self::issue('SOURCE_PARTIALLY_UNAVAILABLE', self::INFO,
                implode(', ', $troubled) . ' unavailable/degraded, but ' . $onlineCount . ' confirmed-online source(s) remain (' .
                    implode(', ', $buckets['online']) . ') — archive confidence is not significantly reduced.',
                null, $buckets, 'No action needed unless this persists for a long time.');
        } elseif ($troubled && $onlineCount === 0) {
            $issues[] = self::issue('SOURCE_HEALTH_UNCERTAIN', self::WARNING,
                implode(', ', $troubled) . ' unavailable/degraded, and no source is CONFIRMED reachable right now' .
                    ($buckets['unknown'] ? ' (' . implode(', ', $buckets['unknown']) . ' simply has not been tried yet)' : '') .
                    ' — archive confidence cannot be vouched for until one succeeds.',
                null, $buckets, 'Run a manual sync or Admin → Diagnostics → Probe every source now.');
        }
        if ($buckets['degraded']) {
            $issues[] = self::issue('SOURCE_DEGRADED', self::WARNING,
                implode(', ', $buckets['degraded']) . ' degraded (parser warning, rate-limited, or in cool-down).',
                null, $buckets, 'Check Admin → Diagnostics for the specific parser/rate-limit error.');
        }

        $status = $activeCount > 0 && count($buckets['unavailable']) >= $activeCount ? 'ERROR'
            : ($buckets['degraded'] || ($troubled && $onlineCount === 0) ? 'WARNING'
              : ($buckets['unavailable'] ? 'INFO' : 'GOOD'));

        return ['status' => $status, 'counts' => array_map('count', $buckets), 'issues' => $issues,
                'explanation' => 'A blocked source is context for confidence, not a verdict on its own — only a CONFIRMED-online source counts as independent evidence remaining.'];
    }

    // ────────────────────────────────────────────────────────────
    // F. Latest Episode Verification
    // ────────────────────────────────────────────────────────────
    private function latestVerification(?array $detection): array
    {
        $missing = new RmMissingData($this->db);
        $dbMax   = $missing->maxEpisode();

        $det = $detection ?? [
            'latest_aired' => $dbMax ?: null, 'upcoming' => [],
            'decision' => $dbMax > 0 ? 'NOT_CHECKED' : 'SOURCE_UNAVAILABLE',
            'decision_note' => $dbMax > 0
                ? 'Live sources have not been checked this session.'
                : 'The archive is empty and live sources have not been checked.',
        ];

        $verification = match ($det['decision']) {
            'ALREADY_SYNCED' => 'VERIFIED',
            'MISSING'        => 'MISSING_AIRED_EPISODES',
            'SOURCE_DISAGREEMENT' => 'CONFLICT',
            default          => 'UNKNOWN',   // SOURCE_UNAVAILABLE, NOT_CHECKED, INSUFFICIENT_EVIDENCE
        };

        $issues = [];
        if ($verification === 'MISSING_AIRED_EPISODES') {
            $issues[] = self::issue('CONFIRMED_MISSING_EPISODES', self::ERROR,
                $det['decision_note'], null, $det, 'Run Auto Sync research on the confirmed episode(s).');
        } elseif ($verification === 'CONFLICT') {
            $issues[] = self::issue('LATEST_EPISODE_SOURCE_CONFLICT', self::WARNING,
                $det['decision_note'], null, $det, 'Sources disagree by more than the normal lag — check manually before trusting either.');
        } elseif ($verification === 'UNKNOWN') {
            $issues[] = self::issue('LATEST_VERIFICATION_UNKNOWN', self::INFO,
                'Latest-episode verification has not been established: ' . $det['decision_note'],
                null, $det, 'Run Auto Sync → Detect Latest for a live check.');
        }

        return [
            'status' => $verification,
            'counts' => [
                'stored_latest'          => $dbMax ?: null,
                'latest_verified_aired'  => $det['latest_aired'] ?? null,
                'upcoming'               => !empty($det['upcoming']) ? (int)$det['upcoming'][0]['episode'] : null,
            ],
            'issues' => $issues,
            'explanation' => $det['decision_note'],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // G. Provenance / AI Application Integrity
    // ────────────────────────────────────────────────────────────
    /**
     * Nothing before PR16 audited whether AI/decision provenance is
     * internally CONSISTENT — only whether it was recorded at all. Every
     * check here is a structural invariant that should always hold if
     * the write-gates in Decision.php/AiSynopsis.php/AiReasoning.php are
     * working correctly; a hit here means one of those gates was bypassed
     * (a bug, or a manual DB edit), not a normal archive condition.
     */
    private function provenanceIntegrity(int $limit): array
    {
        $hasAiLog   = $this->tableExists('ai_generation_log');
        $hasDecision= $this->tableExists('research_decisions');
        $hasFieldSrc= $this->tableExists('episode_field_sources');

        if (!$hasAiLog && !$hasDecision) {
            return ['status' => 'NOT_INSTALLED', 'counts' => [], 'issues' => [
                self::issue('PROVENANCE_TABLES_MISSING', self::INFO,
                    'ai_generation_log / research_decisions are not installed — provenance integrity cannot be checked.'),
            ], 'explanation' => 'Install database/pr4_ai_diagnostics.sql and database/research_engine.sql to enable this check.'];
        }

        $issues = [];
        $counts = [];

        if ($hasAiLog) {
            // 1. An AI draft that failed grounding must never be applied.
            $rows = $this->query(
                "SELECT episode_number, field_name, model, grounding_status FROM ai_generation_log
                  WHERE applied = 1 AND grounding_status = 'rejected' LIMIT $limit");
            $counts['ai_applied_despite_rejected_grounding'] = count($rows);
            foreach ($rows as $r) {
                $issues[] = self::issue('AI_APPLIED_DESPITE_REJECTED_GROUNDING', self::CRITICAL,
                    'EP' . $r['episode_number'] . " field '{$r['field_name']}' is marked applied, but its grounding status is REJECTED.",
                    (int)$r['episode_number'], $r, 'Investigate immediately — this should be structurally impossible.');
            }

            // 2. Only a GENERATE decision may ever be marked applied.
            $rows = $this->query(
                "SELECT episode_number, field_name, decision FROM ai_generation_log
                  WHERE applied = 1 AND decision <> 'GENERATE' LIMIT $limit");
            $counts['ai_applied_invalid_decision'] = count($rows);
            foreach ($rows as $r) {
                $issues[] = self::issue('AI_APPLIED_INVALID_DECISION', self::CRITICAL,
                    'EP' . $r['episode_number'] . " field '{$r['field_name']}' is marked applied, but its logged decision was {$r['decision']}, not GENERATE.",
                    (int)$r['episode_number'], $r, 'Investigate — only a GENERATE decision should ever be applied.');
            }

            // 4. The log claims a write happened; does the field still hold a value?
            $rows = $this->query(
                "SELECT l.episode_number, l.field_name FROM ai_generation_log l
                   JOIN episodes e ON e.episode_number = l.episode_number
                  WHERE l.applied = 1 AND l.field_name = 'synopsis'
                    AND (e.synopsis IS NULL OR e.synopsis = '') LIMIT $limit");
            $counts['ai_applied_but_field_empty'] = count($rows);
            foreach ($rows as $r) {
                $issues[] = self::issue('AI_APPLIED_BUT_FIELD_EMPTY', self::ERROR,
                    'EP' . $r['episode_number'] . " ai_generation_log says its synopsis was applied, but the field is empty now.",
                    (int)$r['episode_number'], $r, 'A later edit may have cleared it — verify manually; not necessarily corruption.');
            }
        }

        if ($hasDecision) {
            // 3. Only UPDATE/FILL decisions are ever safe to auto-apply
            // (RmDecision::isSafe()) — an applied REVIEW/REJECT/KEEP/UNKNOWN
            // means a write happened that should have waited for a person.
            $rows = $this->query(
                "SELECT episode_number, field_name, decision FROM research_decisions
                  WHERE applied = 1 AND decision NOT IN ('UPDATE','FILL') LIMIT $limit");
            $counts['decision_applied_unsafe'] = count($rows);
            foreach ($rows as $r) {
                $issues[] = self::issue('DECISION_APPLIED_UNSAFE', self::CRITICAL,
                    'EP' . $r['episode_number'] . " field '{$r['field_name']}' was applied despite a {$r['decision']} decision, which is never safe to auto-write.",
                    (int)$r['episode_number'], $r, 'Investigate immediately — this bypasses the review gate.');
            }
        }

        if ($hasFieldSrc && $hasDecision) {
            // 5. Informational only: a researched episode with zero recorded
            // field provenance at all — an audit-trail gap, not a data problem.
            $rows = $this->query(
                "SELECT DISTINCT rs.episode_number FROM research_state rs
                   LEFT JOIN episode_field_sources fs ON fs.episode_number = rs.episode_number
                  WHERE rs.status <> 'never_researched' AND fs.episode_number IS NULL
                  LIMIT $limit");
            $counts['no_field_provenance'] = count($rows);
            if ($rows) {
                $issues[] = self::issue('NO_FIELD_PROVENANCE', self::INFO,
                    count($rows) . ' researched episode(s) have no field-level provenance recorded at all.',
                    null, ['episodes' => array_slice(array_map(fn($r) => (int)$r['episode_number'], $rows), 0, 20)],
                    'Usually pre-dates the provenance migration; not itself a data error.');
            }
        }

        $hasCritical = (bool)array_filter($issues, fn($i) => $i['severity'] === self::CRITICAL);
        $hasError    = (bool)array_filter($issues, fn($i) => $i['severity'] === self::ERROR);
        $status = $hasCritical ? 'CRITICAL' : ($hasError ? 'ERROR' : ($issues ? 'INFO' : 'GOOD'));

        return ['status' => $status, 'counts' => $counts, 'issues' => $issues,
                'explanation' => 'Every check here is a structural invariant — a hit means a write-gate was bypassed, not a normal archive condition.'];
    }

    /**
     * Section 14 — filter an already-computed issue list. Deliberately a
     * pure array filter over scan()'s own output rather than a second
     * query path: the admin UI (and this method) never re-derives
     * anything, so a filtered view can never disagree with the full one.
     *
     * @param array $filters severity, domain, episode, id (issue type) — any combination, all optional
     */
    public static function filterIssues(array $issues, array $filters): array
    {
        return array_values(array_filter($issues, function ($i) use ($filters) {
            if (isset($filters['severity']) && $filters['severity'] !== '' && $i['severity'] !== $filters['severity']) return false;
            if (isset($filters['domain']) && $filters['domain'] !== '' && ($i['domain'] ?? null) !== $filters['domain']) return false;
            if (isset($filters['episode']) && $filters['episode'] !== '' && (int)($i['episode'] ?? 0) !== (int)$filters['episode']) return false;
            if (isset($filters['id']) && $filters['id'] !== '' && $i['id'] !== $filters['id']) return false;
            return true;
        }));
    }

    private function query(string $sql): array
    {
        if ($this->db === null) return [];
        try { return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }
}
