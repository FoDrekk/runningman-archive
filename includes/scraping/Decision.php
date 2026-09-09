<?php
// ============================================================
// RmDecisionEngine — deciding, and being able to say why.
//
// Every automatic write has to survive the question "why did you
// change that?", asked six months later by someone who was not here.
// A scraper that cannot answer it is indistinguishable from one that
// guessed, so each decision produced here carries the value chosen,
// the confidence, the sources that backed it, the sources that did
// not, and a sentence a person can read.
//
// The rules are deterministic and local. There is no model, no API
// key and no network call in this file, and the system is complete
// without one — RmDecisionProvider exists so a future reasoning
// provider can be consulted on the hard cases, not so the engine can
// depend on one.
//
// Decisions: KEEP · UPDATE · FILL · REJECT · REVIEW · UNKNOWN
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/Evidence.php';

/**
 * The seam for a future reasoning provider.
 *
 * It receives the full context the deterministic engine used and may
 * return a decision of its own. Nothing implements this today, and
 * nothing has to: when no provider is registered the deterministic
 * result is the result.
 */
interface RmDecisionProvider
{
    /**
     * @param array $context episode, field, candidate_values, sources,
     *                       source_reputation, field_reputation, evidence,
     *                       independence_groups, conflicts, existing_value,
     *                       existing_confidence, historical_context
     * @return array{decision:string,reason:string,confidence:int,recommended_action:string}|null
     */
    public function decide(array $context): ?array;
}

class RmDecision
{
    public function __construct(
        public int    $episode,
        public string $field,
        public string $decision,          // KEEP|UPDATE|FILL|REJECT|REVIEW|UNKNOWN
        public mixed  $value = null,
        public mixed  $existing = null,
        public int    $confidence = 0,    // 0..100
        public string $reason = '',
        public array  $supporting = [],
        public array  $dissenting = [],
        public int    $independent = 0,
        public array  $why = [],          // the bullet list shown in the UI
    ) {}

    /** Safe to write without a person looking at it first. */
    public function isSafe(): bool
    {
        return in_array($this->decision, ['UPDATE', 'FILL'], true);
    }

    public function toArray(): array
    {
        return [
            'episode' => $this->episode, 'field' => $this->field, 'decision' => $this->decision,
            'value' => $this->value, 'existing' => $this->existing, 'confidence' => $this->confidence,
            'reason' => $this->reason, 'supporting' => $this->supporting, 'dissenting' => $this->dissenting,
            'independent' => $this->independent, 'why' => $this->why, 'safe' => $this->isSafe(),
        ];
    }
}

class RmDecisionEngine
{
    private ?RmDecisionProvider $provider = null;

    /** Register a reasoning provider for the cases the rules leave open. */
    public function setProvider(?RmDecisionProvider $p): void { $this->provider = $p; }

    // ────────────────────────────────────────────────────────────
    // Thresholds
    // ────────────────────────────────────────────────────────────
    /**
     * How sure the engine has to be before writing a field without
     * asking. Getting an air date wrong corrupts the archive's spine;
     * getting a tag wrong is a tag. They should not need the same bar.
     */
    public static function threshold(string $field): array
    {
        $criticality = self::criticality($field);
        $cfg = (array)rmScrapeConfig("research.thresholds.$criticality", []);
        return [
            'update' => (int)($cfg['update'] ?? match ($criticality) {
                'critical' => 90, 'important' => 82, default => 75 }),
            'fill'   => (int)($cfg['fill'] ?? match ($criticality) {
                'critical' => 85, 'important' => 75, default => 65 }),
            'review' => (int)($cfg['review'] ?? match ($criticality) {
                'critical' => 70, 'important' => 60, default => 50 }),
        ];
    }

    public static function criticality(string $field): string
    {
        $map = (array)rmScrapeConfig('research.criticality', []);
        if (isset($map[$field])) return (string)$map[$field];
        return match ($field) {
            'episode_number', 'air_date', 'title'       => 'critical',
            'guests', 'location', 'synopsis', 'title_ko' => 'important',
            default                                      => 'secondary',
        };
    }

    // ────────────────────────────────────────────────────────────
    // Deciding
    // ────────────────────────────────────────────────────────────
    /**
     * One decision per field for which there is either evidence or an
     * existing value worth defending.
     *
     * @param array $existing   current database values, field => value
     * @param array $existingConf  field => 0..100 (from provenance), optional
     * @return array<string,RmDecision>
     */
    public function decideAll(int $epNum, RmEvidenceSet $ev, array $existing, array $existingConf = []): array
    {
        $out = [];
        foreach ($ev->fields() as $field) {
            $out[$field] = $this->decide(
                $epNum, $field, $ev->candidates($field),
                $existing[$field] ?? null,
                (int)($existingConf[$field] ?? 0)
            );
        }
        return $out;
    }

    public function decide(int $epNum, string $field, array $candidates, mixed $existing, int $existingConf = 0): RmDecision
    {
        $th = self::threshold($field);
        $hasExisting = !($existing === null || $existing === '' || $existing === []);

        if (!$candidates) {
            return new RmDecision(
                episode: $epNum, field: $field, decision: 'UNKNOWN', existing: $existing,
                reason: 'No source offered a value for this field.',
                why: ['No evidence was gathered for ' . $field],
            );
        }

        $top  = $candidates[0];
        $rest = array_slice($candidates, 1);
        $conf = $this->score($top, $rest);
        // PR #4 source policy: a candidate backed ONLY by a diagnostics/
        // verification-only source (e.g. IMDb) can corroborate or contest
        // another value, but must never itself become the value written
        // for canonical episode metadata — see RmEvidenceSet::candidates().
        $topVerificationOnly = (bool)($top['verification_only'] ?? false);

        $supporting = array_values(array_unique($top['sources']));
        $dissenting = [];
        foreach ($rest as $c) foreach ($c['sources'] as $s) $dissenting[] = $s;
        $dissenting = array_values(array_unique($dissenting));

        $why = $this->explain($field, $top, $rest, $existing, $existingConf, $conf);

        // ── Nothing there: filling a gap is the safest thing we do ──
        if (!$hasExisting) {
            if ($topVerificationOnly) {
                return new RmDecision($epNum, $field, 'REVIEW', $top['value'], $existing, $conf,
                    'The only evidence for this empty field comes from a diagnostics/verification-only source '
                    . '(' . implode(', ', $supporting) . ') — it may corroborate a canonical source but cannot fill the field alone.',
                    $supporting, $dissenting, (int)$top['independent'],
                    array_merge($why, ['Verification-only source cannot fill canonical metadata unattended']));
            }
            $decision = $conf >= $th['fill'] ? 'FILL' : ($conf >= $th['review'] ? 'REVIEW' : 'REJECT');
            $reason = match ($decision) {
                'FILL'   => "The field was empty and the evidence is strong enough ($conf% ≥ {$th['fill']}%) to fill it.",
                'REVIEW' => "The field is empty and there is a candidate, but at $conf% it is below the {$th['fill']}% needed to fill "
                          . self::criticality($field) . " data unattended.",
                default  => "The field is empty and the only candidate is too weakly supported ($conf%) to record.",
            };
            return new RmDecision($epNum, $field, $decision, $top['value'], $existing, $conf, $reason,
                                  $supporting, $dissenting, (int)$top['independent'], $why);
        }

        // ── Already agrees: the strongest and least interesting case ──
        if ($this->sameAsExisting($field, $existing, $top)) {
            return new RmDecision($epNum, $field, 'KEEP', $existing, $existing, max($conf, $existingConf),
                "The archive already holds this value and the sources agree with it.",
                $supporting, $dissenting, (int)$top['independent'],
                array_merge(['The stored value and the leading candidate are the same'], $why));
        }

        // ── Genuine disagreement between two well-supported answers ──
        if ($this->isContested($top, $rest)) {
            return new RmDecision($epNum, $field, 'REVIEW', $top['value'], $existing, $conf,
                'Two independently supported answers disagree; the archive keeps what it has until a person decides.',
                $supporting, $dissenting, (int)$top['independent'],
                array_merge($why, ['Held for review rather than picking a side automatically']));
        }

        // ── Different from what we hold: the case that needs care ──
        // Existing data is not overwritten because something newer
        // exists. It is overwritten when the new evidence is clearly
        // better than the evidence the old value rests on.
        $margin = (int)rmScrapeConfig('research.overwrite_margin', 8);
        if ($topVerificationOnly) {
            return new RmDecision($epNum, $field, 'REVIEW', $top['value'], $existing, $conf,
                'A diagnostics/verification-only source (' . implode(', ', $supporting) . ') disagrees with the '
                . 'stored value — flagged for a person to weigh, since a verification-only source cannot overwrite canonical data.',
                $supporting, $dissenting, (int)$top['independent'],
                array_merge($why, ['Verification-only source cannot overwrite existing data unattended']));
        }
        if ($conf >= $th['update'] && $conf >= $existingConf + $margin) {
            return new RmDecision($epNum, $field, 'UPDATE', $top['value'], $existing, $conf,
                "The evidence for the new value ($conf%) is stronger than the evidence behind the stored one"
                . ($existingConf > 0 ? " ($existingConf%)" : '') . ", by more than the $margin-point margin required to overwrite.",
                $supporting, $dissenting, (int)$top['independent'], $why);
        }
        if ($conf >= $th['review']) {
            return new RmDecision($epNum, $field, 'REVIEW', $top['value'], $existing, $conf,
                "The sources suggest a different value, but at $conf% the case is not strong enough"
                . ($existingConf > 0 ? " against the stored value's $existingConf%" : '')
                . ' to overwrite existing data unattended.',
                $supporting, $dissenting, (int)$top['independent'], $why);
        }
        return new RmDecision($epNum, $field, 'KEEP', $existing, $existing, $existingConf,
            "The differing value is too weakly supported ($conf%) to disturb what the archive already holds.",
            $supporting, $dissenting, (int)$top['independent'], $why);
    }

    // ────────────────────────────────────────────────────────────
    // Scoring
    // ────────────────────────────────────────────────────────────
    /**
     * Confidence in the leading candidate: how good its witnesses are,
     * how many of them are independent, and how much credible support
     * the alternatives have.
     */
    private function score(array $top, array $rest): int
    {
        // Corroboration is already folded into the candidate's
        // reliability by RmEvidenceSet::candidates() — adding a second
        // bonus here would count the same agreement twice.
        $conf = (int)$top['reliability'];

        // A lone witness — however reputable — is a single point of
        // failure, and that costs a little.
        if ($top['independent'] <= 1) $conf -= 6;

        // Credible disagreement costs the leader in proportion to how
        // credible it is.
        foreach ($rest as $c) {
            $conf -= (int)round($c['reliability'] * min(1.0, $c['independent'] / max(1, $top['independent'])) * 0.35);
        }
        return max(0, min(99, $conf));
    }

    /** Two answers, both independently supported, neither clearly ahead. */
    private function isContested(array $top, array $rest): bool
    {
        if (!$rest) return false;
        $second = $rest[0];
        if ((int)$second['independent'] < 2) return false;
        if ((int)$top['independent'] > (int)$second['independent'] + 1) return false;
        return abs((int)$top['reliability'] - (int)$second['reliability'])
               <= (int)rmScrapeConfig('research.contested_within', 12);
    }

    /** Compared after normalisation, so formatting is never mistaken for disagreement. */
    private function sameAsExisting(string $field, mixed $existing, array $candidate): bool
    {
        $a = RmEvidenceSet::normalize($field, $existing);
        $b = RmEvidenceSet::normalize($field, $candidate['value']);
        if (is_array($a) && is_array($b)) {
            sort($a); sort($b);
            return $a === $b;
        }
        return (string)$a !== '' && (string)$a === (string)$b;
    }

    /** The bullet list from the specification's "why" panel. */
    private function explain(string $field, array $top, array $rest, mixed $existing, int $existingConf, int $conf): array
    {
        $why = [];
        foreach (array_unique($top['sources']) as $s) $why[] = "$s supports this value";
        foreach ($rest as $c) {
            foreach (array_unique($c['sources']) as $s) {
                $v = is_array($c['value']) ? implode(', ', array_map('strval', $c['value'])) : (string)$c['value'];
                $why[] = "$s says \"" . mb_strimwidth($v, 0, 48, '…') . '" instead';
            }
        }
        $n = (int)$top['independent'];
        $why[] = $n <= 1
            ? 'Only one independent source backs it — copies of the same text are not counted twice'
            : "$n independent sources agree (shared-lineage and copied text counted once)";
        if ($existing !== null && $existing !== '' && $existing !== []) {
            $why[] = $existingConf > 0
                ? "The stored value is held at $existingConf% confidence"
                : 'The stored value has no recorded confidence';
        }
        $why[] = 'Field criticality: ' . self::criticality($field)
               . ' (needs ' . self::threshold($field)['update'] . '% to overwrite)';
        return $why;
    }

    // ────────────────────────────────────────────────────────────
    // The provider seam
    // ────────────────────────────────────────────────────────────
    /**
     * The context a reasoning provider would receive. Built here so the
     * shape is defined and testable now, rather than being invented
     * later by whoever wires a provider up.
     */
    public function contextFor(int $epNum, string $field, RmEvidenceSet $ev, mixed $existing, int $existingConf): array
    {
        $candidates = $ev->candidates($field);
        return [
            'episode'             => $epNum,
            'field'               => $field,
            'criticality'         => self::criticality($field),
            'candidate_values'    => array_map(fn($c) => [
                'value' => $c['value'], 'sources' => $c['sources'],
                'independent' => $c['independent'], 'reliability' => $c['reliability'],
            ], $candidates),
            'sources'             => array_map(fn($e) => $e->source, $ev->forField($field)),
            'source_reputation'   => array_column(array_map(
                fn($e) => [$e->source, $e->reliability], $ev->forField($field)), 1, 0),
            'field_reputation'    => array_column(array_map(
                fn($e) => [$e->source, RmSourceReputation::instance()->reliability($e->source, $field)],
                $ev->forField($field)), 1, 0),
            'evidence'            => array_map(fn($e) => $e->toArray(), $ev->forField($field)),
            'independence_groups' => array_values(array_unique(array_map(fn($e) => $e->group, $ev->forField($field)))),
            'conflicts'           => count($candidates) > 1,
            'existing_value'      => $existing,
            'existing_confidence' => $existingConf,
            'historical_context'  => null,     // filled by the caller when it has one
            'thresholds'          => self::threshold($field),
        ];
    }

    /**
     * Consult the provider, if one is registered, and only for cases the
     * rules left open. The deterministic decision is always computed
     * first and is what stands if the provider declines or is absent.
     */
    public function refine(RmDecision $d, array $context): RmDecision
    {
        if ($this->provider === null || $d->decision !== 'REVIEW') return $d;
        $r = $this->provider->decide($context);
        if (!is_array($r) || empty($r['decision'])) return $d;
        if (!in_array($r['decision'], ['KEEP','UPDATE','FILL','REVIEW','REJECT','UNKNOWN'], true)) return $d;

        $d->decision   = (string)$r['decision'];
        $d->confidence = max(0, min(100, (int)($r['confidence'] ?? $d->confidence)));
        $d->reason     = (string)($r['reason'] ?? $d->reason);
        $d->why[]      = 'Refined by a reasoning provider';
        return $d;
    }

    // ────────────────────────────────────────────────────────────
    // Persistence
    // ────────────────────────────────────────────────────────────
    /** @param array<string,RmDecision> $decisions */
    public static function persist(?PDO $db, int $epNum, array $decisions, ?int $runId = null, array $applied = []): int
    {
        if ($db === null || !rmResearchTablesExist()) return 0;
        $n = 0;
        try {
            $db->prepare('DELETE FROM research_decisions WHERE episode_number = ? AND (review_status IS NULL OR review_status = ?)')
               ->execute([$epNum, 'open']);
            $ins = $db->prepare(
                'INSERT INTO research_decisions
                   (run_id, episode_number, field_name, decision, chosen_value, existing_value,
                    confidence, reason, supporting, dissenting, independent_n, applied, review_status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($decisions as $field => $d) {
                $flat = fn($v) => $v === null ? null
                    : mb_substr(is_array($v) ? implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $v)) : (string)$v, 0, 4000);
                $ins->execute([
                    $runId, $epNum, mb_substr($field, 0, 40), $d->decision,
                    $flat($d->value), $flat($d->existing),
                    max(0, min(100, $d->confidence)), mb_substr($d->reason, 0, 400),
                    mb_substr(implode(',', $d->supporting), 0, 300) ?: null,
                    mb_substr(implode(',', $d->dissenting), 0, 300) ?: null,
                    max(0, min(127, $d->independent)),
                    in_array($field, $applied, true) ? 1 : 0,
                    $d->decision === 'REVIEW' ? 'open' : null,
                ]);
                $n++;
            }
        } catch (Throwable $e) { }
        return $n;
    }

    /** The conflict inbox: decisions still waiting for a person. */
    public static function openReviews(?PDO $db, int $limit = 100): array
    {
        if ($db === null || !rmResearchTablesExist()) return [];
        try {
            return $db->query(
                "SELECT * FROM research_decisions WHERE review_status = 'open'
                  ORDER BY episode_number DESC, field_name LIMIT " . max(1, min(500, $limit))
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /**
     * Close a review. Ignoring is a decision about what to do next, not
     * an instruction to forget: the row and its evidence stay exactly
     * where they are.
     */
    public static function resolveReview(?PDO $db, int $decisionId, string $outcome): bool
    {
        if ($db === null || !rmResearchTablesExist()) return false;
        if (!in_array($outcome, ['accepted', 'kept_existing', 'ignored'], true)) return false;
        try {
            $s = $db->prepare('UPDATE research_decisions SET review_status = ? WHERE decision_id = ?');
            $s->execute([$outcome, $decisionId]);
            return $s->rowCount() > 0;
        } catch (Throwable $e) { return false; }
    }
}
