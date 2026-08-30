<?php
// ============================================================
// RmFieldResolver — decides, FIELD BY FIELD, which source wins.
//
// A single global "source priority" is wrong for this archive: the
// site with the most accurate air dates is not the site with the
// best synopses, and the only site that records filming locations
// has no episode titles at all. So each field carries its own
// ordered preference list (config/scraping.php → field_priority),
// and each resolved field carries a CONFIDENCE derived from how
// many independent sources agreed:
//
//   HIGH     several trusted sources agree
//   MEDIUM   one trusted source, unopposed
//   LOW      only a weak/community source has it
//   CONFLICT two trusted sources disagree → the priority winner is
//            still used, but the disagreement is recorded, not hidden
//
// Array fields (guests, tags) are unioned rather than won outright:
// three sources listing overlapping guests should produce the union,
// deduplicated by identity, not whichever list happens to rank first.
// ============================================================
require_once __DIR__ . '/DataNormalizer.php';
require_once __DIR__ . '/DataValidator.php';

class RmFieldResolver
{
    const HIGH = 'high', MEDIUM = 'medium', LOW = 'low', CONFLICT = 'conflict';

    /**
     * @param array<string,array> $bySource  source => field => value (raw source payloads)
     * @param array $ctx  episode_number, expected_year
     * @return array field => [
     *     value, source, sources[], confidence, conflicts[], rejected[], url
     *   ]
     */
    public function resolve(array $bySource, array $ctx = []): array
    {
        $priority   = (array)rmScrapeConfig('field_priority', []);
        $arrayFields= (array)rmScrapeConfig('array_fields', ['guests','tags']);
        $out        = [];

        foreach ($priority as $field => $order) {
            $candidates = $this->collectCandidates($field, $order, $bySource, $ctx);
            if (!$candidates['valid']) {
                if ($candidates['rejected']) {
                    $out[$field] = ['value'=>null,'source'=>null,'sources'=>[],'confidence'=>null,
                                    'conflicts'=>[],'rejected'=>$candidates['rejected'],'url'=>null];
                }
                continue;
            }
            $out[$field] = in_array($field, $arrayFields, true)
                ? $this->resolveArrayField($field, $candidates)
                : $this->resolveScalarField($field, $candidates);
        }
        return $out;
    }

    /** Validate every source's offer for one field, in priority order. */
    private function collectCandidates(string $field, array $order, array $bySource, array $ctx): array
    {
        $valid = []; $rejected = [];
        foreach ($order as $rank => $source) {
            if (!array_key_exists($source, $bySource)) continue;
            $payload = $bySource[$source];
            if (!is_array($payload) || !array_key_exists($field, $payload)) continue;
            $raw = $payload[$field];
            if ($raw === null || $raw === '' || $raw === []) continue;

            $verdict = RmValidator::check($field, $raw, $ctx);
            if (!$verdict['valid']) {
                $rejected[] = ['source'=>$source,'reason'=>$verdict['reason']];
                continue;
            }
            $valid[] = [
                'source' => $source,
                'rank'   => $rank,
                'tier'   => (int)rmScrapeConfig("sources.$source.tier", 1),
                'value'  => $verdict['value'],
                'url'    => $payload['_url'] ?? null,
            ];
        }
        return ['valid' => $valid, 'rejected' => $rejected];
    }

    private function resolveScalarField(string $field, array $c): array
    {
        $cands = $c['valid'];
        $winner = $cands[0];                       // priority order is already applied

        // Group candidates by normalised equality to see who agrees.
        $groups = [];
        foreach ($cands as $cand) {
            $k = $this->comparisonKey($field, $cand['value']);
            $groups[$k][] = $cand;
        }
        $winnerKey   = $this->comparisonKey($field, $winner['value']);
        $agreeing    = $groups[$winnerKey];
        $agreeNames  = array_values(array_unique(array_column($agreeing, 'source')));
        $agreeWeight = array_sum(array_column($agreeing, 'tier'));

        // Anything in another group is a genuine disagreement.
        $conflicts = [];
        foreach ($groups as $k => $grp) {
            if ($k === $winnerKey) continue;
            foreach ($grp as $g) {
                $conflicts[] = ['source'=>$g['source'],'tier'=>$g['tier'],'value'=>$this->stringify($g['value'])];
            }
        }

        $confidence = $this->scoreConfidence($agreeing, $conflicts, $agreeWeight);

        return [
            'value'      => $winner['value'],
            'source'     => $winner['source'],
            'sources'    => $agreeNames,
            'confidence' => $confidence,
            'conflicts'  => $conflicts,
            'rejected'   => $c['rejected'],
            'url'        => $winner['url'],
        ];
    }

    /** Union merge: every source contributes items; identity dedups them. */
    private function resolveArrayField(string $field, array $c): array
    {
        $cands = $c['valid'];
        if ($field === 'guests') {
            $bySource = [];
            foreach ($cands as $cand) $bySource[$cand['source']] = $cand['value'];
            $merged = RmNormalizer::mergeGuests($bySource);
            $items  = $merged['names'];
            $itemSources = $merged['byKeySources'];
            $review = $merged['review'];
        } else {
            $items = []; $itemSources = []; $review = [];
            foreach ($cands as $cand) {
                foreach ((array)$cand['value'] as $item) {
                    $k = mb_strtolower(trim((string)$item), 'UTF-8');
                    if ($k === '') continue;
                    if (!isset($items[$k])) $items[$k] = $item;
                    $itemSources[$k][] = $cand['source'];
                }
            }
            $items = array_values($items);
            foreach ($itemSources as $k => $v) $itemSources[$k] = array_values(array_unique($v));
        }

        $sourceNames = array_values(array_unique(array_column($cands, 'source')));
        $weight = array_sum(array_column($cands, 'tier'));
        // For sets, "agreement" means more than one source contributed to
        // the same set. Disjoint sets aren't a conflict — they're coverage.
        $confidence = count($cands) >= 2
            ? ($weight >= (int)rmScrapeConfig('confidence.high_weight', 4) ? self::HIGH : self::MEDIUM)
            : ($cands[0]['tier'] >= (int)rmScrapeConfig('confidence.reliable_tier', 2) ? self::MEDIUM : self::LOW);

        return [
            'value'        => $items,
            'source'       => $cands[0]['source'],
            'sources'      => $sourceNames,
            'confidence'   => $confidence,
            'conflicts'    => [],
            'item_sources' => $itemSources,
            'review'       => $review,
            'rejected'     => $c['rejected'],
            'url'          => $cands[0]['url'],
        ];
    }

    private function scoreConfidence(array $agreeing, array $conflicts, int $agreeWeight): string
    {
        $conflictTier = (int)rmScrapeConfig('confidence.conflict_tier', 2);
        $reliableTier = (int)rmScrapeConfig('confidence.reliable_tier', 2);
        $highWeight   = (int)rmScrapeConfig('confidence.high_weight', 4);

        // A disagreement only counts as CONFLICT when a source we actually
        // trust is on the other side. A community site contradicting
        // Wikipedia is expected noise, not a data emergency.
        $seriousConflict = false;
        foreach ($conflicts as $x) if ((int)$x['tier'] >= $conflictTier) $seriousConflict = true;
        $winnerTier = max(array_column($agreeing, 'tier') ?: [1]);
        if ($seriousConflict && $winnerTier >= $conflictTier) return self::CONFLICT;

        if (count($agreeing) >= 2 && $agreeWeight >= $highWeight) return self::HIGH;
        if (count($agreeing) >= 2) return self::MEDIUM;
        return $winnerTier >= $reliableTier ? self::MEDIUM : self::LOW;
    }

    /** Field-appropriate equality: dates compare exactly, prose loosely. */
    private function comparisonKey(string $field, $value): string
    {
        if (is_array($value)) return RmNormalizer::hash($value);
        $s = (string)$value;
        return match ($field) {
            'air_date'  => trim($s),
            'title'     => RmNormalizer::titleKey($s),
            'location'  => RmNormalizer::locationKey($s),
            'image_url' => strtolower(preg_replace('/\?.*$/', '', $s)),
            // Two synopses are "the same" if their first 120 significant
            // characters match — sources truncate at different lengths.
            'synopsis'  => substr(preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s, 'UTF-8')), 0, 120),
            default     => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s, 'UTF-8')),
        };
    }

    private function stringify($v): string
    {
        if (is_array($v)) {
            $flat = array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x, JSON_UNESCAPED_UNICODE), $v);
            return implode(', ', $flat);
        }
        return (string)$v;
    }

    /** Human-readable confidence label for Admin. */
    public static function label(?string $confidence): string {
        return match ($confidence) {
            self::HIGH     => 'HIGH',
            self::MEDIUM   => 'MEDIUM',
            self::LOW      => 'LOW',
            self::CONFLICT => 'CONFLICT',
            default        => '—',
        };
    }
}
