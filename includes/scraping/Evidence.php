<?php
// ============================================================
// RmEvidenceSet — what the sources actually said, and how much of
// it is genuinely independent.
//
// Counting sources is not counting evidence. Four archives that all
// carry the same sentence, word for word, are one witness quoted four
// times, and treating them as four agreeing sources manufactures
// confidence that nothing earned. Wikipedia and Korean Wikipedia are
// sister projects that routinely copy each other; Wikidata is
// populated from Wikipedia. None of those are independent
// confirmations of one another.
//
// So evidence is grouped before it is counted:
//   · declared lineage — sources from one publisher family
//   · observed copying — identical normalised text between sources
//   · everything else  — its own group, one independent witness
//
// The decision engine then counts GROUPS, never rows.
// ============================================================
require_once __DIR__ . '/../../config/scraping.php';
require_once __DIR__ . '/DataNormalizer.php';
require_once __DIR__ . '/SourceReputation.php';

/** One source's answer for one field. */
class RmEvidence
{
    public function __construct(
        public string  $source,
        public string  $field,
        public mixed   $value,
        public ?string $url = null,
        public string  $status = 'found',
        public int     $reliability = 50,
        public string  $group = '',
        public string  $hash = '',
        public ?string $normalized = null,
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->source, 'field' => $this->field, 'value' => $this->value,
            'url' => $this->url, 'status' => $this->status, 'reliability' => $this->reliability,
            'independence_group' => $this->group, 'hash' => $this->hash,
            'normalized' => $this->normalized,
        ];
    }
}

class RmEvidenceSet
{
    /** @var array<string,RmEvidence[]> field => evidence rows */
    private array $byField = [];
    /** @var array<string,array{status:string,url:?string,fields:string[]}> per-source outcome */
    private array $sourceStatus = [];

    private RmSourceReputation $rep;

    public function __construct(?RmSourceReputation $rep = null)
    {
        $this->rep = $rep ?: RmSourceReputation::instance();
    }

    // ────────────────────────────────────────────────────────────
    // Building
    // ────────────────────────────────────────────────────────────
    /**
     * Turn one collect() run into evidence.
     *
     * @param array<string,array> $payloads source => [field => value, _url => …]
     * @param array<string,array> $meta     source => status/url/fields (from the engine)
     */
    public static function fromCollected(array $payloads, array $meta = []): self
    {
        $set = new self();
        foreach ($meta as $source => $m) {
            $set->sourceStatus[$source] = [
                'status' => self::sourceStatusFor((string)($m['status'] ?? 'empty')),
                'url'    => $m['url'] ?? null,
                'fields' => (array)($m['fields'] ?? []),
                'http'   => $m['http'] ?? null,
                'ms'     => (int)($m['ms'] ?? 0),
                'raw'    => (string)($m['status'] ?? ''),
                'error'  => $m['error'] ?? null,
            ];
        }
        foreach ($payloads as $source => $fields) {
            $url = $fields['_url'] ?? ($meta[$source]['url'] ?? null);
            foreach ($fields as $field => $value) {
                if ($field === '' || $field[0] === '_') continue;
                if ($value === null || $value === '' || $value === []) continue;
                $set->add($source, (string)$field, $value, $url);
            }
        }
        $set->group();
        return $set;
    }

    /**
     * Map the engine's transport-level status onto the vocabulary from
     * the specification. "Everything that went wrong is FAILED" is
     * exactly the flattening that made the old page unreadable, so
     * each distinct obstacle keeps its own name.
     */
    public static function sourceStatusFor(string $engineStatus): string
    {
        return match ($engineStatus) {
            'ok'                => 'FOUND',
            'empty'             => 'NO_DATA',
            'missing_episode'   => 'NO_DATA',
            'not_applicable'    => 'NOT_APPLICABLE',
            'parser_warning'    => 'PARSER_ERROR',
            'structure_changed' => 'STRUCTURE_CHANGED',
            'needs_javascript'  => 'JAVASCRIPT_REQUIRED',
            'blocked'           => 'ACCESS_RESTRICTED',
            'rate_limited'      => 'RATE_LIMITED',
            'suppressed'        => 'RATE_LIMITED',
            'disabled'          => 'NOT_APPLICABLE',
            'skipped'           => 'NOT_APPLICABLE',
            'fetch_failed'      => 'TEMPORARY_ERROR',
            'adapter_error'     => 'PARSER_ERROR',
            default             => 'UNAVAILABLE',
        };
    }

    public function add(string $source, string $field, mixed $value, ?string $url = null, string $status = 'found'): void
    {
        $normalized = self::normalize($field, $value);
        $this->byField[$field][] = new RmEvidence(
            source: $source,
            field: $field,
            value: $value,
            url: $url,
            status: $status,
            reliability: $this->rep->reliability($source, $field),
            hash: RmNormalizer::hash($normalized),
            normalized: is_array($normalized) ? implode(' | ', $normalized) : (string)$normalized,
        );
    }

    /** Fields whose members are people, and must be compared as identities. */
    private const PERSON_FIELDS = ['guests', 'cast', 'members'];

    /** Comparison form: differences of spelling must not read as disagreement. */
    public static function normalize(string $field, mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $s = is_array($v) ? ($v['name'] ?? json_encode($v)) : (string)$v;
                // "Kim Jong-kook", "Kim Jong Kook" and "김종국" are one
                // person. Comparing them as raw strings makes two sources
                // that agree perfectly look like a conflict, which is the
                // fastest way to fill the review queue with non-problems.
                $s = in_array($field, self::PERSON_FIELDS, true)
                    ? RmNormalizer::guestKey($s)
                    : mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)RmNormalizer::text($s) ?? '')), 'UTF-8');
                if ($s !== '') $out[] = $s;
            }
            sort($out);
            return array_values(array_unique($out));
        }
        $s = (string)(is_scalar($value) ? $value : json_encode($value));
        return match ($field) {
            'air_date' => (string)(RmNormalizer::date($s) ?? $s),
            'title', 'title_ko', 'title_en' =>
                mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s)), 'UTF-8'),
            default =>
                mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)(RmNormalizer::text($s) ?? $s))), 'UTF-8'),
        };
    }

    // ────────────────────────────────────────────────────────────
    // Independence
    // ────────────────────────────────────────────────────────────
    /**
     * Assign every piece of evidence to an independence group.
     *
     * Declared lineage wins: two Wikimedia projects are one witness
     * whether or not their wording happens to match today. Otherwise
     * identical normalised text on a free-text field is taken as
     * copying — original prose does not coincide word for word.
     */
    public function group(): void
    {
        $lineage = (array)rmScrapeConfig('source_lineage', []);
        $copyable = (array)rmScrapeConfig('research.copyable_fields',
                                          ['synopsis', 'mission', 'teams', 'results', 'special_notes']);

        foreach ($this->byField as $field => $rows) {
            // First pass: lineage families.
            foreach ($rows as $e) {
                $fam = $lineage[$e->source] ?? null;
                $e->group = $fam !== null ? "lineage:$fam" : "solo:{$e->source}";
            }
            // Second pass: identical text on a field where identical text
            // means one of them copied the other.
            if (in_array($field, $copyable, true)) {
                $seen = [];
                foreach ($rows as $e) {
                    $len = mb_strlen((string)$e->normalized);
                    if ($len < 40) continue;          // too short for coincidence to be meaningful
                    if (isset($seen[$e->hash])) $e->group = $seen[$e->hash];
                    else $seen[$e->hash] = $e->group = 'copy:' . substr($e->hash, 0, 10);
                }
            }
        }
    }

    // ────────────────────────────────────────────────────────────
    // Reading
    // ────────────────────────────────────────────────────────────
    /** @return string[] */
    public function fields(): array { return array_keys($this->byField); }

    /** @return RmEvidence[] */
    public function forField(string $field): array { return $this->byField[$field] ?? []; }

    public function sourceStatuses(): array { return $this->sourceStatus; }

    public function count(): int
    {
        $n = 0;
        foreach ($this->byField as $rows) $n += count($rows);
        return $n;
    }

    /**
     * The candidates for one field: each distinct value, who supports
     * it, how many INDEPENDENT groups back it, and the combined
     * reliability of its best witness in each group.
     *
     * @return array<int,array{value:mixed,hash:string,sources:string[],groups:string[],
     *                          independent:int,reliability:int,evidence:RmEvidence[]}>
     */
    public function candidates(string $field): array
    {
        $byHash = [];
        foreach ($this->forField($field) as $e) {
            $c = &$byHash[$e->hash];
            if ($c === null) {
                $c = ['value' => $e->value, 'hash' => $e->hash, 'sources' => [], 'groups' => [],
                      'reliability' => 0, 'evidence' => [], 'normalized' => $e->normalized];
            }
            $c['sources'][] = $e->source;
            $c['evidence'][] = $e;
            // One group contributes its strongest witness once, not once
            // per member — that is the whole point of grouping.
            $c['groups'][$e->group] = max($c['groups'][$e->group] ?? 0, $e->reliability);
            unset($c);
        }
        $out = [];
        foreach ($byHash as $c) {
            $c['independent'] = count($c['groups']);
            // PR #4 source policy: a candidate whose ONLY witnesses are
            // diagnostics/verification-only sources (e.g. IMDb) must never
            // be treated as the winner for canonical metadata — see
            // RmDecisionEngine::decide(). It still appears in the list so
            // it can corroborate or contest the real winner.
            $c['verification_only'] = self::allVerificationOnly($c['sources']);
            // Corroborated strength. One witness is worth exactly what
            // that witness is worth — no more, and crucially no less.
            // Each further INDEPENDENT witness closes part of the
            // remaining distance to certainty, weighted by its own
            // standing, so the second matters far more than the fifth and
            // no amount of agreement ever reaches 100.
            $sorted = array_values($c['groups']);
            rsort($sorted);
            $score = (float)array_shift($sorted);
            $w = 0.35;
            foreach ($sorted as $rel) {
                $score += (100 - $score) * $w * ($rel / 100);
                $w *= 0.5;
            }
            $c['reliability'] = (int)round(min(99, $score));
            $c['groups'] = array_keys($c['groups']);
            $out[] = $c;
        }
        usort($out, fn($a, $b) =>
            [$a['verification_only'] ? 1 : 0, $b['independent'], $b['reliability']]
            <=> [$b['verification_only'] ? 1 : 0, $a['independent'], $a['reliability']]);
        return $out;
    }

    /** True only when every source behind this candidate is verification-only (e.g. IMDb). */
    private static function allVerificationOnly(array $sources): bool
    {
        if (!$sources) return false;
        foreach ($sources as $s) if (!rmScrapeSourceVerificationOnly((string)$s)) return false;
        return true;
    }

    /** Persist this evidence so the archive can answer "who said what" later. */
    public function persist(?PDO $db, int $epNum, ?int $runId = null): int
    {
        if ($db === null || !rmResearchTablesExist()) return 0;
        $n = 0;
        try {
            // One run's evidence for one episode replaces the last run's:
            // the question is "what do the sources say now", not a
            // transcript of every look ever taken.
            $db->prepare('DELETE FROM research_evidence WHERE episode_number = ?')->execute([$epNum]);
            $ins = $db->prepare(
                'INSERT INTO research_evidence
                    (run_id, episode_number, field_name, source_name, source_url, source_status,
                     raw_value, normalized_value, value_hash, independence_group, reliability)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($this->byField as $field => $rows) {
                foreach ($rows as $e) {
                    $raw = is_array($e->value) ? json_encode($e->value, JSON_UNESCAPED_UNICODE) : (string)$e->value;
                    $ins->execute([
                        $runId, $epNum, mb_substr($field, 0, 40), mb_substr($e->source, 0, 40),
                        $e->url !== null ? mb_substr($e->url, 0, 500) : null,
                        mb_substr($e->status === 'found' ? 'FOUND' : $e->status, 0, 30),
                        mb_substr((string)$raw, 0, 8000),
                        mb_substr((string)$e->normalized, 0, 8000),
                        $e->hash, mb_substr($e->group, 0, 60), max(0, min(100, $e->reliability)),
                    ]);
                    $n++;
                }
            }
        } catch (Throwable $e) { }
        return $n;
    }

    /** Read back the stored evidence for one episode — the evidence view. */
    public static function stored(?PDO $db, int $epNum, ?string $field = null): array
    {
        if ($db === null || !rmResearchTablesExist()) return [];
        try {
            $sql = 'SELECT * FROM research_evidence WHERE episode_number = ?'
                 . ($field ? ' AND field_name = ?' : '')
                 . ' ORDER BY field_name, reliability DESC';
            $s = $db->prepare($sql);
            $s->execute($field ? [$epNum, $field] : [$epNum]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
