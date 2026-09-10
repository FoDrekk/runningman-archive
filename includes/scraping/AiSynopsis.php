<?php
// ============================================================
// RmAiSynopsisService — the auto-generate-missing-synopsis feature.
//
//   KEEP EXISTING if a usable synopsis already exists
//   → gather verified evidence (title, guests, theme, mission,
//     location, special_notes)
//   → INSUFFICIENT_EVIDENCE if there isn't enough to write from
//   → draft with the AI client
//   → GROUNDING VALIDATION against the evidence, one revision attempt
//   → REJECT if it still isn't grounded
//   → GENERATE only once the draft is grounded and confident
//
// Every decision here is one of the vocabulary the specification asks
// for (GENERATE, KEEP_EXISTING, INSUFFICIENT_EVIDENCE, REQUEST_REVIEW,
// REJECT, NO_USABLE_DATA, RETRY_LATER, DISABLED) and, when a database
// is available, is written to ai_generation_log so the decision can be
// audited later — what evidence it had, what it drafted, why it was
// accepted or refused.
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/AiReasoning.php';
require_once __DIR__ . '/GroundingValidator.php';

class RmAiSynopsisService
{
    public function __construct(
        private ?PDO $db = null,
        private RmAiClient $client = new RmAiClient(),
    ) {}

    /**
     * @param string|null $existingSynopsis current database value
     * @param array $evidence title, guests[], theme, mission, location,
     *                        special_notes, air_date — whatever verified
     *                        facts research already gathered for this episode
     * @param array $opt run_id, force (ignore an existing usable synopsis
     *                   anyway — used only by an explicit "regenerate")
     */
    public function consider(int $epNum, ?string $existingSynopsis, array $evidence, array $opt = []): array
    {
        $mode = (string)rmScrapeConfig('ai.mode', 'review');
        if ($mode === 'disabled') {
            return $this->log($epNum, 'DISABLED', 0, null, $evidence, $opt, 'AI layer is disabled in config');
        }

        if (empty($opt['force']) && $this->isUsable($existingSynopsis)) {
            return $this->log($epNum, 'KEEP_EXISTING', 100, null, $evidence, $opt,
                'A usable synopsis already exists — the AI layer never rewrites good source-written text');
        }

        $usableFacts = array_filter($evidence, fn($v) => $v !== null && $v !== '' && $v !== []);
        $minFields = (int)rmScrapeConfig('ai.synopsis.min_evidence_fields', 2);
        if (count($usableFacts) < $minFields) {
            return $this->log($epNum, 'INSUFFICIENT_EVIDENCE', 0, null, $evidence, $opt,
                'Only ' . count($usableFacts) . " verified fact(s) available — need at least $minFields to draft from");
        }

        if (!$this->client->available()) {
            // mode === 'disabled' already returned above, so the only way
            // to reach here is a missing key — a permanent condition, not
            // a transient one, exactly like TMDB/TVDB with no key.
            return $this->log($epNum, 'DISABLED', 0, null, $evidence, $opt,
                'No AI API key configured (RM_AI_API_KEY / ANTHROPIC_API_KEY) — optional feature skipped at no cost');
        }

        $draft = $this->draft($epNum, $usableFacts);
        if ($draft === null) {
            return $this->log($epNum, 'RETRY_LATER', 0, null, $evidence, $opt,
                'The AI request failed — treated as a transient obstacle, not a lack of evidence');
        }

        $grounding = RmGroundingValidator::validate($draft, $usableFacts);
        $groundingStatus = 'passed';
        if (!$grounding['valid']) {
            $revised = $this->revise($epNum, $usableFacts, $draft, $grounding['issues']);
            if ($revised !== null) {
                $regrounded = RmGroundingValidator::validate($revised, $usableFacts);
                if ($regrounded['valid']) {
                    $draft = $revised;
                    $grounding = $regrounded;
                    $groundingStatus = 'revised';
                } else {
                    return $this->log($epNum, 'REJECT', $regrounded['confidence'], $revised, $evidence, $opt,
                        'Still not grounded after one revision: ' . implode('; ', $regrounded['issues']),
                        'rejected', implode('; ', $regrounded['issues']));
                }
            } else {
                return $this->log($epNum, 'REJECT', $grounding['confidence'], $draft, $evidence, $opt,
                    'Not grounded in the evidence: ' . implode('; ', $grounding['issues']),
                    'rejected', implode('; ', $grounding['issues']));
            }
        }

        $thresholds = (array)rmScrapeConfig('ai.thresholds', ['high' => 90, 'review' => 70]);
        $confidence = $grounding['confidence'];

        if ($confidence < (int)($thresholds['review'] ?? 70)) {
            return $this->log($epNum, 'INSUFFICIENT_EVIDENCE', $confidence, $draft, $evidence, $opt,
                "Grounded but low confidence ($confidence%) — evidence is too thin to publish from",
                $groundingStatus);
        }

        $highEnough = $confidence >= (int)($thresholds['high'] ?? 90);
        $decision = ($mode === 'auto' && $highEnough) ? 'GENERATE' : 'REQUEST_REVIEW';
        $reason = $decision === 'GENERATE'
            ? "Grounded, high-confidence draft ($confidence%) generated automatically"
            : "Grounded draft ($confidence%) held for review — " . ($mode === 'auto' ? 'below the auto-apply threshold' : "AI mode is '$mode'");

        return $this->log($epNum, $decision, $confidence, $draft, $evidence, $opt, $reason, $groundingStatus);
    }

    /** A synopsis worth keeping: real prose, not a stub or a placeholder. */
    private function isUsable(?string $s): bool
    {
        if ($s === null) return false;
        $s = trim($s);
        $min = (int)rmScrapeConfig('safety.min_synopsis_chars', 15);
        return mb_strlen($s) >= max($min, 40);
    }

    private function draft(int $epNum, array $facts): ?string
    {
        $system = 'You write short, professional episode synopses for a TV archive — the kind you would '
            . 'read on a streaming platform. Engaging, natural, concise, cinematic, spoiler-conscious. '
            . 'Explain the premise and what makes the episode interesting without inventing anything. '
            . 'Use ONLY the facts given — never invent guests, winners, dialogue, emotions, twists, '
            . 'locations, outcomes or relationships beyond what is stated. '
            . 'Vary your sentence structure; never start two synopses the same way; never begin with '
            . '"In this episode" or any close variant. Target 50-100 words. '
            . 'Reply with ONLY the synopsis text — no title, no preamble, no quotation marks.';
        $user = "Episode $epNum. Verified facts:\n" . $this->factsBlock($facts);
        return $this->client->complete($system, $user);
    }

    private function revise(int $epNum, array $facts, string $draft, array $issues): ?string
    {
        $system = 'You previously drafted an episode synopsis that made claims the evidence does not '
            . 'support. Rewrite it using ONLY the facts given, removing or replacing every unsupported '
            . 'claim listed. Keep the same professional, cinematic tone, 50-100 words. '
            . 'Reply with ONLY the corrected synopsis text.';
        $user = "Episode $epNum. Verified facts:\n" . $this->factsBlock($facts)
              . "\n\nPrevious draft:\n$draft\n\nUnsupported claims to remove:\n- " . implode("\n- ", $issues);
        return $this->client->complete($system, $user);
    }

    private function factsBlock(array $facts): string
    {
        $lines = [];
        foreach ($facts as $k => $v) {
            $lines[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . (is_array($v) ? implode(', ', $v) : $v);
        }
        return implode("\n", $lines);
    }

    private function log(
        int $epNum, string $decision, int $confidence, ?string $value, array $evidence,
        array $opt, string $reason, ?string $groundingStatus = null, ?string $groundingIssues = null
    ): array {
        $result = [
            'decision'         => $decision,
            'confidence'       => $confidence,
            'value'            => $value,
            'reason'           => $reason,
            'evidence_basis'   => array_keys(array_filter($evidence, fn($v) => $v !== null && $v !== '' && $v !== [])),
            'grounding_status' => $groundingStatus,
            'grounding_issues' => $groundingIssues,
            'model'            => rmScrapeConfig('ai.model', 'claude-sonnet-5'),
        ];

        if ($this->db !== null && self::tableExists($this->db)) {
            try {
                $this->db->prepare(
                    'INSERT INTO ai_generation_log
                        (run_id, episode_number, field_name, model, decision, confidence,
                         evidence_basis, generated_value, grounding_status, grounding_issues, applied)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $opt['run_id'] ?? null, $epNum, 'synopsis', $result['model'], $decision, $confidence,
                    mb_substr(implode(',', $result['evidence_basis']), 0, 300),
                    $value !== null ? mb_substr($value, 0, 4000) : null,
                    $groundingStatus, $groundingIssues !== null ? mb_substr($groundingIssues, 0, 500) : null,
                    !empty($opt['applied']) ? 1 : 0,
                ]);
            } catch (Throwable $e) { /* logging must never break research */ }
        }
        return $result;
    }

    /**
     * The generation log's `applied` column is written FALSE by log()
     * every time, because consider() decides GENERATE/REQUEST_REVIEW
     * before the caller has attempted the actual database write —
     * whether it lands still depends on RmDecision::isSafe() and any
     * anomaly veto downstream. The caller marks the most recent log row
     * true only once that write has genuinely happened, so the audit
     * trail (Section 6) reflects reality rather than an assumption.
     */
    public function markApplied(int $epNum, ?int $runId): void
    {
        if ($this->db === null || !self::tableExists($this->db)) return;
        try {
            $this->db->prepare(
                'UPDATE ai_generation_log SET applied = 1
                   WHERE episode_number = ? AND field_name = ? AND (run_id <=> ?)
                   ORDER BY log_id DESC LIMIT 1'
            )->execute([$epNum, 'synopsis', $runId]);
        } catch (Throwable $e) { /* logging must never break research */ }
    }

    private static function tableExists(PDO $db): bool
    {
        static $exists = null;
        if ($exists !== null) return $exists;
        try { $db->query('SELECT 1 FROM ai_generation_log LIMIT 1'); $exists = true; }
        catch (Throwable $e) { $exists = false; }
        return $exists;
    }
}
