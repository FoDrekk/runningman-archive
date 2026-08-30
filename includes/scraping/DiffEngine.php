<?php
// ============================================================
// RmDiffEngine — compare what the database HAS against what the
// scrape FOUND, and decide what is safe to write.
//
// The governing rule (and the reason this class exists at all):
// an existing valid value is worth more than a new invalid one.
// If a source's HTML changes and its parser starts returning empty
// strings, the correct outcome is a PARSER_WARNING — never NULLing
// out a synopsis that was perfectly good yesterday.
//
// Output is a list of proposed changes, each with a type, the old
// and new values, the winning source, its confidence, and whether it
// will actually be applied. Dry-run mode renders exactly this list
// and writes nothing.
// ============================================================
require_once __DIR__ . '/DataNormalizer.php';
require_once __DIR__ . '/FieldResolver.php';

class RmDiffEngine
{
    const ADDED        = 'added';         // field was empty, now has a value
    const CHANGED      = 'changed';       // field had a different value
    const REMOVED      = 'removed';       // proposed removal (never auto-applied)
    const ITEM_ADDED   = 'item_added';    // guest/tag added to a set
    const ITEM_REMOVED = 'item_removed';  // guest/tag missing from all sources
    const UNCHANGED    = 'unchanged';
    const REJECTED     = 'rejected';      // new value refused by a safety rule
    const CONFLICT     = 'conflict';      // sources disagree — recorded, winner still applied

    /**
     * @param array $existing  current DB values keyed by field
     * @param array $resolved  RmFieldResolver output keyed by field
     * @return array{changes:array,apply:array,warnings:array,summary:array}
     */
    public function diff(array $existing, array $resolved, array $opt = []): array
    {
        $arrayFields = (array)rmScrapeConfig('array_fields', ['guests','tags']);
        $changes = []; $apply = []; $warnings = [];

        foreach ($resolved as $field => $r) {
            $new = $r['value'] ?? null;
            $old = $existing[$field] ?? null;

            if (in_array($field, $arrayFields, true)) {
                $changes = array_merge($changes, $this->diffSet($field, (array)($old ?: []), (array)($new ?: []), $r, $apply));
                continue;
            }

            // ── Nothing usable came back ─────────────────────────
            if ($new === null || $new === '') {
                if ($this->isPresent($old)) {
                    // Held, not overwritten. This is the single most
                    // important behaviour in the whole engine.
                    $warnings[] = [
                        'field' => $field, 'type' => 'kept_existing',
                        'message' => "No valid $field from any source this run — existing value kept",
                    ];
                }
                continue;
            }

            if (!$this->isPresent($old)) {
                $changes[] = $this->change($field, self::ADDED, $old, $new, $r, true);
                $apply[$field] = $new;
                continue;
            }

            if ($this->sameValue($field, $old, $new)) {
                $changes[] = $this->change($field, self::UNCHANGED, $old, $new, $r, false);
                continue;
            }

            // ── Old and new differ: is the new one actually better? ──
            $guard = $this->guard($field, $old, $new, $r, $opt);
            if (!$guard['allow']) {
                $changes[] = $this->change($field, self::REJECTED, $old, $new, $r, false, $guard['reason']);
                $warnings[] = ['field'=>$field,'type'=>'refused_overwrite','message'=>$guard['reason']];
                continue;
            }

            $type = ($r['confidence'] ?? null) === RmFieldResolver::CONFLICT ? self::CONFLICT : self::CHANGED;
            $willApply = $guard['allow'] && empty($opt['manual_review_only']);
            $changes[] = $this->change($field, $type, $old, $new, $r, $willApply, $guard['reason']);
            if ($willApply) $apply[$field] = $new;
        }

        return [
            'changes'  => $changes,
            'apply'    => $apply,
            'warnings' => $warnings,
            'summary'  => $this->summarise($changes),
        ];
    }

    /** Union semantics: items are added, never silently dropped. */
    private function diffSet(string $field, array $old, array $new, array $r, array &$apply): array
    {
        $keyer = $field === 'guests'
            ? fn($v) => RmNormalizer::guestKey((string)$v)
            : fn($v) => mb_strtolower(trim((string)$v), 'UTF-8');

        $oldMap = []; foreach ($old as $v) { $k = $keyer($v); if ($k !== '') $oldMap[$k] = $v; }
        $newMap = []; foreach ($new as $v) { $k = $keyer($v); if ($k !== '') $newMap[$k] = $v; }

        $changes = [];
        $added   = array_diff_key($newMap, $oldMap);
        $missing = array_diff_key($oldMap, $newMap);

        foreach ($added as $k => $v) {
            $changes[] = $this->change($field, self::ITEM_ADDED, null, $v, $r, true);
        }
        // A name the sources no longer mention is NOT evidence it was
        // wrong — coverage varies per source and per run. It is reported
        // for review and left in place.
        foreach ($missing as $k => $v) {
            $changes[] = $this->change($field, self::ITEM_REMOVED, $v, null, $r, false,
                'Not returned by any source this run — kept (removal needs manual review)');
        }
        if (!$added && !$missing) {
            $changes[] = $this->change($field, self::UNCHANGED, $old, $new, $r, false);
        }

        // The applied set is always old ∪ new: additive by construction.
        if ($added) $apply[$field] = array_values($oldMap + $newMap);
        return $changes;
    }

    /**
     * Data-safety guards. Each returns a reason so the refusal shows up
     * in the change log instead of vanishing.
     */
    private function guard(string $field, $old, $new, array $r, array $opt): array
    {
        $allow = fn(?string $why = null) => ['allow' => true,  'reason' => $why];
        $deny  = fn(string $why)         => ['allow' => false, 'reason' => $why];

        if (!$this->isPresent($new)) return $deny("Refused: new $field is empty and the existing value is valid");

        switch ($field) {
            case 'title':
                // Never trade a descriptive title for a bare placeholder.
                $oldDesc = RmNormalizer::titleDescriptor((string)$old);
                $newDesc = RmNormalizer::titleDescriptor((string)$new);
                if ($oldDesc !== null && $newDesc === null) {
                    return $deny('Refused: new title has no descriptor while the existing one does');
                }
                if ($oldDesc !== null && $newDesc !== null && mb_strlen($newDesc) < mb_strlen($oldDesc) * 0.5) {
                    return $deny('Refused: new title descriptor is less than half the length of the existing one');
                }
                return $allow();

            case 'synopsis':
                $ratio = (float)rmScrapeConfig('safety.synopsis_shrink_ratio', 0.5);
                $oldLen = mb_strlen((string)$old); $newLen = mb_strlen((string)$new);
                if ($oldLen > 0 && $newLen < $oldLen * $ratio) {
                    return $deny("Refused: new synopsis is $newLen chars vs the existing $oldLen — suspicious shrink, likely a parser break");
                }
                // A truncated source string that the existing value already
                // starts with is the same text, cut shorter. Keep the longer.
                $oldN = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string)$old, 'UTF-8'));
                $newN = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string)$new, 'UTF-8'));
                if ($oldN !== '' && str_starts_with($oldN, $newN)) {
                    return $deny('Refused: new synopsis is a truncated prefix of the existing one');
                }
                return $allow();

            case 'air_date':
                // Air dates effectively never change once broadcast. A
                // different date is either a correction or a mis-parse, so
                // it only wins with real backing.
                $conf = $r['confidence'] ?? null;
                if ($conf === RmFieldResolver::LOW) {
                    return $deny('Refused: air-date change proposed by a single low-trust source');
                }
                if ($conf === RmFieldResolver::CONFLICT && empty($opt['allow_conflict_writes'])) {
                    return $deny('Refused: sources disagree on the air date — flagged for review instead');
                }
                return $allow();

            case 'location':
                if ($this->isPresent($old) && ($r['confidence'] ?? null) === RmFieldResolver::LOW) {
                    return $deny('Refused: location change proposed by a single low-trust source');
                }
                return $allow();

            case 'image_url':
                return $allow();

            default:
                if (is_string($old) && is_string($new) && mb_strlen($new) < mb_strlen($old) * 0.4 && mb_strlen($old) > 40) {
                    return $deny("Refused: new $field is drastically shorter than the existing value");
                }
                return $allow();
        }
    }

    private function change(string $field, string $type, $old, $new, array $r, bool $applied, ?string $reason = null): array
    {
        return [
            'field'      => $field,
            'type'       => $type,
            'old'        => $this->flat($old),
            'new'        => $this->flat($new),
            'source'     => $r['source'] ?? null,
            'sources'    => $r['sources'] ?? [],
            'confidence' => $r['confidence'] ?? null,
            'conflicts'  => $r['conflicts'] ?? [],
            'applied'    => $applied,
            'reason'     => $reason,
        ];
    }

    private function flat($v): ?string
    {
        if ($v === null) return null;
        if (is_array($v)) return implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x, JSON_UNESCAPED_UNICODE), $v));
        return (string)$v;
    }

    private function isPresent($v): bool
    {
        if ($v === null) return false;
        if (is_array($v)) return count($v) > 0;
        return trim((string)$v) !== '';
    }

    private function sameValue(string $field, $old, $new): bool
    {
        if (is_array($old) || is_array($new)) return RmNormalizer::hash((array)$old) === RmNormalizer::hash((array)$new);
        $o = (string)$old; $n = (string)$new;
        return match ($field) {
            'air_date'  => trim($o) === trim($n),
            'title'     => RmNormalizer::titleKey($o) === RmNormalizer::titleKey($n),
            'location'  => RmNormalizer::locationKey($o) === RmNormalizer::locationKey($n),
            'image_url' => strtolower(preg_replace('/\?.*$/', '', $o)) === strtolower(preg_replace('/\?.*$/', '', $n)),
            default     => preg_replace('/\s+/u', ' ', trim($o)) === preg_replace('/\s+/u', ' ', trim($n)),
        };
    }

    private function summarise(array $changes): array
    {
        $s = ['added'=>0,'changed'=>0,'item_added'=>0,'item_removed'=>0,'unchanged'=>0,'rejected'=>0,'conflict'=>0,'removed'=>0];
        foreach ($changes as $c) if (isset($s[$c['type']])) $s[$c['type']]++;
        $s['total_applied'] = count(array_filter($changes, fn($c) => $c['applied']));
        return $s;
    }

    /** One-line renderings for the admin change feed / dry-run output. */
    public static function renderLine(array $c): string
    {
        $sym = match ($c['type']) {
            self::ADDED, self::ITEM_ADDED   => '+',
            self::ITEM_REMOVED, self::REMOVED => '−',
            self::CHANGED, self::CONFLICT   => '~',
            self::REJECTED                  => '✗',
            default                         => '=',
        };
        $label = ucfirst(str_replace('_', ' ', $c['field']));
        return match ($c['type']) {
            self::ADDED      => "$sym $label: " . self::trim($c['new']),
            self::ITEM_ADDED => "$sym $label: " . self::trim($c['new']),
            self::ITEM_REMOVED => "$sym $label: " . self::trim($c['old']) . ' (kept — review)',
            self::CHANGED    => "$sym $label changed: " . self::trim($c['old']) . ' → ' . self::trim($c['new']),
            self::CONFLICT   => "$sym $label CONFLICT: " . self::trim($c['old']) . ' → ' . self::trim($c['new']),
            self::REJECTED   => "$sym $label unchanged — " . ($c['reason'] ?? 'refused'),
            default          => "$sym $label unchanged",
        };
    }

    private static function trim(?string $s, int $len = 70): string
    {
        if ($s === null || $s === '') return '(empty)';
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }
}
