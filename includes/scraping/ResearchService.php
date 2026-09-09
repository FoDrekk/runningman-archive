<?php
// ============================================================
// RmResearchService — the research agent's loop for one episode.
//
//   DISCOVER → FETCH → EXTRACT → NORMALIZE → VALIDATE → COMPARE
//   → CROSS-CHECK → SCORE EVIDENCE → DECIDE → PREVIEW
//   → SAFELY WRITE → RECORD
//
// This is a layer ON TOP of RmScrapingEngine, not a replacement for
// it. The engine still owns fetching, validation, the diff safety
// guards and the transactional write — everything PR #1 built and
// PR #2 and #3 hardened. What this adds is the part that was missing:
// evidence recorded as evidence, a decision that can explain itself,
// and a memory of having looked, so the same episodes stop coming
// back forever.
//
// Research aggressively, verify aggressively, write conservatively.
// If the evidence is insufficient, do not guess — say so and stop.
// ============================================================
require_once __DIR__ . '/ScrapingEngine.php';
require_once __DIR__ . '/ResearchState.php';
require_once __DIR__ . '/ResearchRun.php';
require_once __DIR__ . '/Evidence.php';
require_once __DIR__ . '/Decision.php';
require_once __DIR__ . '/SourceReputation.php';
require_once __DIR__ . '/Discovery.php';
require_once __DIR__ . '/Anomaly.php';
require_once __DIR__ . '/FieldLock.php';
require_once __DIR__ . '/AiReasoning.php';
require_once __DIR__ . '/AiSynopsis.php';

class RmResearchService
{
    private ?PDO $db;
    private RmScrapingEngine  $engine;
    private RmResearchState   $state;
    private RmDecisionEngine  $decider;
    private RmSourceReputation $rep;
    private RmDiscovery       $discovery;
    private RmProvenance      $prov;
    private RmAiSynopsisService $aiSynopsis;

    public function __construct(?PDO $db = null, ?RmScrapingEngine $engine = null)
    {
        try { $this->db = $db ?: getDBSafe(); } catch (Throwable $e) { $this->db = null; }
        $this->engine    = $engine ?: new RmScrapingEngine($this->db);
        $this->state     = new RmResearchState($this->db);
        $this->decider   = new RmDecisionEngine();
        $this->rep       = RmSourceReputation::instance();
        $this->discovery = new RmDiscovery($this->db);
        $this->prov      = new RmProvenance($this->db);
        $this->aiSynopsis = new RmAiSynopsisService($this->db);

        // AI reasoning is consulted only for decisions the deterministic
        // rules already classified REVIEW (see RmDecisionEngine::refine).
        // With no key configured, RmAiDecisionProvider::decide() always
        // returns null and the deterministic result stands unchanged.
        $aiClient = new RmAiClient();
        if ($aiClient->available()) $this->decider->setProvider(new RmAiDecisionProvider($aiClient));
    }

    /** Swap in a test double — used by tests to exercise GENERATE/REVIEW without a live API key. */
    public function setAiSynopsisService(RmAiSynopsisService $svc): void { $this->aiSynopsis = $svc; }

    public function state(): RmResearchState { return $this->state; }
    public function engine(): RmScrapingEngine { return $this->engine; }
    public function decisionEngine(): RmDecisionEngine { return $this->decider; }

    // ────────────────────────────────────────────────────────────
    // Research modes
    // ────────────────────────────────────────────────────────────
    public static function modes(): array
    {
        return (array)rmScrapeConfig('research.modes', []);
    }

    public static function modeConfig(string $mode): array
    {
        $modes = self::modes();
        return $modes[$mode] ?? $modes[(string)rmScrapeConfig('research.default_mode', 'balanced')] ?? [
            'tiers' => [3, 2], 'discovery' => false, 'recheck_weak' => false,
            'label' => 'Balanced', 'hint' => '',
        ];
    }

    /** Which sources a mode is willing to spend a request on. */
    private function sourcesForMode(string $mode, array $wantFields): array
    {
        $cfg   = self::modeConfig($mode);
        $tiers = (array)($cfg['tiers'] ?? [3, 2, 1]);
        $out   = [];
        foreach (RmSourceRegistry::instance()->all() as $name => $adapter) {
            if (!$adapter->isEnabled()) continue;
            if (!in_array($adapter->tier(), $tiers, true)) continue;
            // A source that cannot supply any wanted field is not worth a
            // request, whatever its tier — that is the whole point of
            // routing by missing field rather than scraping everything.
            if ($wantFields && !array_intersect($wantFields, $adapter->fields())) continue;
            $out[] = $name;
        }
        return $out;
    }

    // ────────────────────────────────────────────────────────────
    // One episode, end to end
    // ────────────────────────────────────────────────────────────
    /**
     * @param array $opt mode, dry_run, fields, force, run (RmResearchRun),
     *                   bypass_cache, skip_thumbnail, auto_apply
     */
    public function researchEpisode(int $epNum, array $opt = []): array
    {
        $t0     = microtime(true);
        $mode   = (string)($opt['mode'] ?? rmScrapeConfig('research.default_mode', 'balanced'));
        $modeC  = self::modeConfig($mode);
        $dryRun = !empty($opt['dry_run']);
        $run    = $opt['run'] ?? null;              // RmResearchRun|null
        $log    = [];

        $note = function (string $msg, string $level = 'info') use (&$log, $epNum) {
            $log[] = ['at' => date('H:i:s'), 'episode' => $epNum, 'level' => $level, 'message' => $msg];
        };
        $note("EP$epNum research started (" . ($modeC['label'] ?? $mode) . ($dryRun ? ', dry run' : '') . ')');

        // ── What are we actually looking for? ──
        $existing = $this->engine->missingData()->currentValues($epNum);
        $gaps     = $this->engine->missingData()->gaps($epNum, $existing);
        $wanted   = (array)($opt['fields'] ?? []);
        if (!$wanted) {
            $wanted = (!$existing || !empty($opt['all_fields']) || !empty($modeC['recheck_weak']))
                ? array_keys((array)rmScrapeConfig('field_priority', []))
                : $this->fieldsForGaps($gaps);
        }
        if (!$wanted) {
            // Nothing missing. Still worth recording that we checked, so
            // the episode does not present as unresearched forever.
            $this->state->record($epNum, RmResearchState::DONE, [
                'reason' => 'Already complete — no field needed researching',
                'run_id' => $run?->id(), 'missing' => [], 'completeness' => 100,
            ]);
            return $this->outcome($epNum, 'completed', 'Already complete — no source was contacted',
                                  [], [], [], $log, $t0, 100);
        }

        // ── DISCOVER ──
        $ctx = [];
        if (!empty($modeC['discovery'])) {
            $discovered = $this->discovery->forEpisode($epNum, $this->sourcesForMode($mode, $wanted));
            if ($discovered) {
                $ctx['discovered'] = $discovered;
                $note('Discovery found ' . array_sum(array_map('count', $discovered))
                      . ' candidate page(s) across ' . count($discovered) . ' source(s)');
            }
        }

        // ── FETCH + EXTRACT + NORMALIZE + VALIDATE (the engine's job) ──
        $collectOpt = [
            'sources'      => $this->sourcesForMode($mode, $wanted),
            'bypass_cache' => !empty($opt['bypass_cache']),
            'dry_run'      => $dryRun,
        ] + $ctx;
        $collected = $this->engine->collect($epNum, $wanted, $collectOpt);

        foreach ($collected['meta'] as $source => $m) {
            $status = RmEvidenceSet::sourceStatusFor((string)($m['status'] ?? ''));
            if ($status === 'FOUND') {
                $note("$source: " . count((array)($m['fields'] ?? [])) . ' field(s) found');
                $this->discovery->confirm($epNum, (string)$source, $m['url'] ?? null);
            } elseif (in_array($status, ['PARSER_ERROR', 'STRUCTURE_CHANGED', 'JAVASCRIPT_REQUIRED'], true)) {
                $note("$source: $status — " . mb_strimwidth((string)($m['error'] ?? ''), 0, 90, '…'), 'warning');
                $this->rep->observe((string)$source, '*', 'parser_error');
            } elseif (in_array($status, ['ACCESS_RESTRICTED', 'RATE_LIMITED', 'TEMPORARY_ERROR', 'UNAVAILABLE'], true)) {
                $note("$source: $status", 'warning');
                $this->rep->observe((string)$source, '*', 'fetch_error');
            }
        }

        // ── COMPARE + CROSS-CHECK + SCORE ──
        $evidence = RmEvidenceSet::fromCollected($collected['payloads'], $collected['meta']);
        $note('Evidence: ' . $evidence->count() . ' item(s) across ' . count($evidence->fields()) . ' field(s)');

        // ── DECIDE ──
        $existingConf = $this->existingConfidence($epNum);
        $decisions = [];
        foreach ($evidence->fields() as $field) {
            $existingVal = $existing[$this->existingKeyFor($field)] ?? null;
            // A locked field (or a locked whole episode) never reaches the
            // normal thresholds at all — manually verified data is
            // protected absolutely, not just weighted heavily.
            if (RmFieldLock::isLocked($this->db, $epNum, $field)) {
                $decisions[$field] = new RmDecision(
                    episode: $epNum, field: $field, decision: 'KEEP', value: $existingVal, existing: $existingVal,
                    confidence: (int)($existingConf[$field] ?? 100),
                    reason: 'This field is locked — research never overwrites a manually protected value.',
                    why: ['Field is locked against automatic changes'],
                );
                $note("$field: locked — left untouched");
                continue;
            }
            $d = $this->decider->decide(
                $epNum, $field, $evidence->candidates($field),
                $existingVal, (int)($existingConf[$field] ?? 0)
            );
            $decisions[$field] = $this->decider->refine(
                $d, $this->decider->contextFor($epNum, $field, $evidence, $existingVal, (int)($existingConf[$field] ?? 0))
            );
            $this->learn($field, $evidence, $decisions[$field]);
        }
        foreach ($decisions as $field => $d) {
            if ($d->decision === 'REVIEW') $note("$field: held for review — " . mb_strimwidth($d->reason, 0, 80, '…'), 'warning');
        }

        // ── ANOMALIES ──
        $anomalies = RmAnomaly::check($this->db, $epNum, $decisions, $existing);
        foreach ($anomalies as $a) $note('Anomaly: ' . $a['message'], 'warning');

        // ── PREVIEW (the plan the engine would apply) ──
        // The engine re-uses the collected result rather than fetching
        // everything a second time.
        $planOpt = $opt + [
            'collected'      => $collected,
            'fields'         => $wanted,
            'dry_run'        => $dryRun,
            'skip_thumbnail' => !empty($opt['skip_thumbnail']),
        ];
        $plan = $this->engine->plan($epNum, $planOpt);

        // ── AI SYNOPSIS (section 9) ──
        // Only ever consulted when synopsis was actually wanted this run,
        // no source already supplied a usable one, and the field isn't
        // locked. KEEP_EXISTING / INSUFFICIENT_EVIDENCE / DISABLED /
        // RETRY_LATER all leave the plan exactly as the sources built it.
        $aiResult = null;
        if (in_array('synopsis', $wanted, true) && empty($plan['apply']['synopsis'])
            && !RmFieldLock::isLocked($this->db, $epNum, 'synopsis')) {
            $facts = array_filter([
                'title'         => $existing['title'] ?? null,
                'guests'        => $existing['guests'] ?? [],
                'mission'       => $existing['mission'] ?? null,
                'location'      => $existing['location'] ?? null,
                'special_notes' => $existing['special_notes'] ?? null,
            ], fn($v) => $v !== null && $v !== '' && $v !== []);
            $aiResult = $this->aiSynopsis->consider($epNum, $existing['synopsis'] ?? null, $facts,
                ['run_id' => $run?->id()]);
            $note('AI synopsis: ' . $aiResult['decision'] . ' — ' . mb_strimwidth($aiResult['reason'], 0, 90, '…'),
                  in_array($aiResult['decision'], ['REJECT', 'RETRY_LATER'], true) ? 'warning' : 'info');

            if ($aiResult['decision'] === 'GENERATE' && !empty($aiResult['value'])) {
                $plan['apply']['synopsis'] = $aiResult['value'];
                $plan['resolved']['synopsis'] = [
                    'value' => $aiResult['value'], 'source' => 'ai_generated',
                    'confidence' => $aiResult['confidence'] >= 90 ? 'high' : 'medium', 'conflicts' => [],
                ];
                $decisions['synopsis'] = new RmDecision(
                    episode: $epNum, field: 'synopsis', decision: 'FILL', value: $aiResult['value'],
                    existing: $existing['synopsis'] ?? null, confidence: $aiResult['confidence'],
                    reason: $aiResult['reason'],
                    why: ['AI-generated from: ' . implode(', ', $aiResult['evidence_basis']),
                          'Grounding: ' . ($aiResult['grounding_status'] ?? 'passed')],
                );
            } elseif (in_array($aiResult['decision'], ['REQUEST_REVIEW', 'REJECT'], true) && !empty($aiResult['value'])) {
                $decisions['synopsis'] = new RmDecision(
                    episode: $epNum, field: 'synopsis', decision: 'REVIEW', value: $aiResult['value'],
                    existing: $existing['synopsis'] ?? null, confidence: $aiResult['confidence'],
                    reason: $aiResult['reason'],
                    why: ['AI draft awaiting review: ' . implode(', ', $aiResult['evidence_basis'])],
                );
            }
        }

        // ── SAFELY WRITE ──
        // Only fields whose decision cleared its own threshold, AND which
        // the engine's own safety guards already approved. Two independent
        // gates: the evidence has to be good enough, and the write has to
        // be safe. Either one can veto.
        $safeFields = [];
        foreach ($decisions as $field => $d) if ($d->isSafe()) $safeFields[] = $field;
        if ($anomalies) {
            // An anomaly is not proof of error, but it is a reason not to
            // write unattended on the fields it touches.
            foreach ($anomalies as $a) {
                $safeFields = array_values(array_diff($safeFields, (array)($a['fields'] ?? [])));
            }
        }
        $beforeApply = (array)($plan['apply'] ?? []);
        $plan['apply'] = array_intersect_key($beforeApply, array_flip($safeFields));
        $withheld = array_values(array_diff(array_keys($beforeApply), array_keys($plan['apply'])));
        if ($withheld) $note('Withheld from the automatic write: ' . implode(', ', $withheld), 'warning');

        $result = $this->engine->apply($plan, $planOpt);
        $applied = array_keys((array)($plan['apply'] ?? []));

        // ── RECORD ──
        $confidence = $this->overallConfidence($decisions);
        if (!$dryRun) {
            $evidence->persist($this->db, $epNum, $run?->id());
            RmDecisionEngine::persist($this->db, $epNum, $decisions, $run?->id(),
                                      empty($result['failed']) ? $applied : []);
            foreach ($anomalies as $a) {
                $this->prov->flag('anomaly', 'episode', null, $epNum, $a['message']);
            }
        }

        $gapsAfter = $this->engine->missingData()->gaps($epNum);
        $outcome   = $this->classify($result, $decisions, $collected['meta'], $anomalies);
        $reason    = $this->reasonFor($outcome, $result, $decisions, $collected['meta'], $applied);

        if (!$dryRun) {
            $this->state->record($epNum, $this->stateFor($outcome), [
                'reason'       => $reason,
                'run_id'       => $run?->id(),
                'missing'      => $gapsAfter,
                'completeness' => $this->engine->missingData()->completeness($epNum),
                'confidence'   => $confidence,
            ]);
        }
        $note("EP$epNum $outcome — " . mb_strimwidth($reason, 0, 100, '…'),
              $outcome === 'failed' ? 'error' : ($outcome === 'needs_review' ? 'warning' : 'info'));

        return $this->outcome($epNum, $outcome, $reason, $decisions, $evidence->sourceStatuses(),
                              $anomalies, $log, $t0, $confidence, [
            'applied'    => $applied,
            'withheld'   => $withheld,
            'changes'    => (array)($result['changes'] ?? []),
            'is_new'     => !empty($result['is_new']),
            'dry_run'    => $dryRun,
            'missing'    => $gapsAfter,
            'evidence'   => $evidence,
            'plan'       => $result,
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // Classification — the distinctions the old page could not make
    // ────────────────────────────────────────────────────────────
    /**
     * "No usable evidence" is not "failed", and neither is "researched
     * and nothing changed". Collapsing them is what made a healthy run
     * report fourteen failures.
     */
    private function classify(array $result, array $decisions, array $meta, array $anomalies): string
    {
        $byStatus = [];
        foreach ($meta as $m) {
            $s = RmEvidenceSet::sourceStatusFor((string)($m['status'] ?? ''));
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
        }
        $contacted = array_sum($byStatus) - ($byStatus['NOT_APPLICABLE'] ?? 0);
        $reachable = ($byStatus['FOUND'] ?? 0) + ($byStatus['NO_DATA'] ?? 0)
                   + ($byStatus['PARSER_ERROR'] ?? 0) + ($byStatus['STRUCTURE_CHANGED'] ?? 0)
                   + ($byStatus['JAVASCRIPT_REQUIRED'] ?? 0);

        // Genuinely could not check: everything we asked refused or broke.
        if ($contacted > 0 && $reachable === 0) return 'failed';
        if ($contacted === 0) return 'failed';
        if (!empty($result['failed'])) return 'failed';

        foreach ($decisions as $d) if ($d->decision === 'REVIEW') return 'needs_review';
        if ($anomalies) return 'needs_review';

        $applied = (int)($result['summary']['total_applied'] ?? 0);
        if ($applied > 0 || !empty($result['is_new'])) return 'completed';
        return 'no_data';
    }

    private function stateFor(string $outcome): string
    {
        return match ($outcome) {
            'completed'    => RmResearchState::UPDATED,
            'no_data'      => RmResearchState::NO_NEW,
            'needs_review' => RmResearchState::REVIEW,
            default        => RmResearchState::FAILED,
        };
    }

    private function reasonFor(string $outcome, array $result, array $decisions, array $meta, array $applied): string
    {
        if ($outcome === 'failed') {
            return (string)($result['reason'] ?? 'No source could be reached, so the episode could not be checked');
        }
        if ($outcome === 'needs_review') {
            $fields = [];
            foreach ($decisions as $f => $d) if ($d->decision === 'REVIEW') $fields[] = $f;
            return 'Held for review: ' . (implode(', ', $fields) ?: 'an anomaly was detected')
                 . ' — existing data left untouched';
        }
        if ($outcome === 'completed') {
            return $applied
                ? 'Updated ' . count($applied) . ' field(s): ' . implode(', ', $applied)
                : 'Researched successfully; the archive already held the best available values';
        }
        // no_data — the case that must never read as a failure.
        $reached = 0;
        foreach ($meta as $m) {
            if (in_array(RmEvidenceSet::sourceStatusFor((string)($m['status'] ?? '')),
                         ['FOUND', 'NO_DATA'], true)) $reached++;
        }
        return "Researched: $reached source(s) answered, none carries the missing data for this episode"
             . ' — the episode is unchanged and intact';
    }

    private function outcome(int $ep, string $outcome, string $reason, array $decisions,
                             array $sources, array $anomalies, array $log, float $t0,
                             ?int $confidence, array $extra = []): array
    {
        return array_merge([
            'episode'    => $ep,
            'outcome'    => $outcome,
            'reason'     => $reason,
            'decisions'  => array_map(fn($d) => $d->toArray(), $decisions),
            'sources'    => $sources,
            'anomalies'  => $anomalies,
            'activity'   => $log,
            'confidence' => $confidence,
            'ms'         => (int)round((microtime(true) - $t0) * 1000),
        ], $extra);
    }

    // ────────────────────────────────────────────────────────────
    // Supporting detail
    // ────────────────────────────────────────────────────────────
    /** Existing per-field confidence, read from PR #1's provenance table. */
    private function existingConfidence(int $epNum): array
    {
        $out = [];
        foreach ($this->prov->forEpisode($epNum)['fields'] ?? [] as $row) {
            $out[$row['field_name']] = match ((string)$row['confidence']) {
                'high'     => 88,
                'medium'   => 72,
                'low'      => 55,
                'conflict' => 40,
                default    => 50,
            };
        }
        return $out;
    }

    /** currentValues() uses the archive's column names; evidence uses the source field names. */
    private function existingKeyFor(string $field): string
    {
        return match ($field) {
            'mission'   => 'mission',
            'image_url' => 'thumbnail',
            default     => $field,
        };
    }

    private function fieldsForGaps(array $gaps): array
    {
        $map = [
            'title'     => ['title', 'title_ko'],
            'air_date'  => ['air_date'],
            'synopsis'  => ['synopsis'],
            'mission'   => ['mission'],
            'location'  => ['location'],
            'guests'    => ['guests'],
            'thumbnail' => ['image_url'],
            'teams'     => ['teams'],
            'results'   => ['results'],
        ];
        $out = [];
        foreach ($gaps as $g) foreach ($map[$g] ?? [$g] as $f) $out[$f] = true;
        return array_keys($out);
    }

    /** The lowest field confidence, because a record is only as good as its weakest field. */
    private function overallConfidence(array $decisions): ?int
    {
        $scores = [];
        foreach ($decisions as $d) {
            if (in_array($d->decision, ['UNKNOWN', 'REJECT'], true)) continue;
            $scores[] = $d->confidence;
        }
        return $scores ? (int)round(min($scores) * 0.4 + array_sum($scores) / count($scores) * 0.6) : null;
    }

    /** Feed the outcome back into source reputation. */
    private function learn(string $field, RmEvidenceSet $ev, RmDecision $d): void
    {
        if (in_array($d->decision, ['UNKNOWN', 'REVIEW'], true)) return;
        foreach ($d->supporting as $s) $this->rep->observe($s, $field, 'agree');
        foreach ($d->dissenting as $s) $this->rep->observe($s, $field, 'disagree');
        if ($d->isSafe()) foreach ($d->supporting as $s) $this->rep->observe($s, $field, 'contributed');
    }

    // ────────────────────────────────────────────────────────────
    // Driving a whole run, one step at a time
    // ────────────────────────────────────────────────────────────
    /**
     * Process queue items until the time budget is spent. The browser
     * calls this repeatedly; the server owns the queue, so a refresh,
     * a crash or a closed tab costs at most the episode in flight.
     *
     * @return array progress plus what happened during this step
     */
    public function step(RmResearchRun $run, array $opt = []): array
    {
        $budget = (float)($opt['seconds'] ?? rmScrapeConfig('research.step_seconds', 20));
        $until  = microtime(true) + max(1.0, $budget);
        $done   = []; $activity = [];

        while (microtime(true) < $until) {
            $item = $run->claimNext();
            if ($item === null) break;

            $ep = (int)$item['episode_number'];
            $fields = array_values(array_filter(explode(',', (string)($item['fields_wanted'] ?? ''))));
            try {
                $r = $this->researchEpisode($ep, [
                    'mode'    => $run->researchMode(),
                    'dry_run' => $run->isDryRun(),
                    'run'     => $run,
                    'fields'  => $this->fieldsForGaps($fields),
                ] + $opt);
            } catch (Throwable $e) {
                $r = ['outcome' => 'failed', 'reason' => 'Adapter error: ' . $e->getMessage(),
                      'confidence' => null, 'activity' => [], 'episode' => $ep];
                if (!$run->isDryRun()) {
                    $this->state->record($ep, RmResearchState::FAILED,
                        ['reason' => $r['reason'], 'run_id' => $run->id()]);
                }
            }

            $run->finishItem((int)$item['queue_id'], (string)$r['outcome'],
                             (string)$r['reason'], $r['confidence'] ?? null);
            if (($r['outcome'] ?? '') === 'completed' && !empty($r['applied'])) {
                $run->bump(!empty($r['is_new']) ? 'episodes_added' : 'episodes_updated');
            }
            if (isset($r['evidence']) && $r['evidence'] instanceof RmEvidenceSet) {
                $run->bump('evidence_count', $r['evidence']->count());
            }
            $done[]   = ['episode' => $ep, 'outcome' => $r['outcome'], 'reason' => $r['reason'],
                         'confidence' => $r['confidence'] ?? null];
            $activity = array_merge($activity, (array)($r['activity'] ?? []));

            $delay = (int)($opt['delay_ms'] ?? 0);
            if ($delay > 0) usleep($delay * 1000);
        }

        $run->syncCounts();
        $progress = $run->progress();

        // The run closes itself the moment the queue empties, so
        // "finished" is a fact in the database rather than something the
        // browser decided and then forgot on refresh.
        if ($progress['remaining'] === 0 && in_array($run->status(), [RmResearchRun::RUNNING, RmResearchRun::QUEUED], true)) {
            $run->finish(null, $this->runSummary($run));
        } elseif (!empty($run->row()['cancel_requested'])) {
            $run->cancelNow();
        }

        return [
            'run'      => $run->summary(),
            'stepped'  => $done,
            'activity' => $activity,
            'progress' => $progress,
        ];
    }

    private function runSummary(RmResearchRun $run): string
    {
        $p = $run->progress();
        return sprintf('%d processed · %d completed · %d with no new data · %d for review · %d failed',
                       $p['done'], $p['completed'], $p['no_data'], $p['needs_review'], $p['failed']);
    }

    /**
     * Start a run: build the queue from the archive's state, persist it,
     * and hand back something the page can render immediately.
     */
    public function startRun(string $scope, array $opt = []): ?RmResearchRun
    {
        $mode = (string)($opt['mode'] ?? rmScrapeConfig('research.default_mode', 'balanced'));
        $cfg  = self::modeConfig($mode);

        // Deep research overrides cool-downs by design: the point of
        // asking for it is to look again at what was already given up on.
        $queue = $this->state->buildQueue($scope, $opt + [
            'force' => !empty($opt['force']) || $mode === 'deep',
            'limit' => (int)($opt['limit'] ?? rmScrapeConfig('research.max_per_run', 200)),
        ]);

        return RmResearchRun::create($scope, $queue, [
            'research_mode' => $mode,
            'dry_run'       => !empty($opt['dry_run']),
            'scope'         => ($cfg['label'] ?? $mode) . ' · ' . $scope
                             . (isset($opt['episode']) ? ' EP' . (int)$opt['episode'] : ''),
        ]);
    }
}
